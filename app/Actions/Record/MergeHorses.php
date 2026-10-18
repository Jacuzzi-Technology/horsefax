<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseName;
use App\Models\NameReview;
use App\Models\User;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Support\Facades\DB;

/**
 * Staff have confirmed that $gone is the same horse as $keep under a misspelled name (usually from a registry name
 * lookup). Everything on $gone moves to $keep: results, sale lots, identifiers, documents, photos, its pedigree lines
 * that $keep doesn't have, and every pedigree line elsewhere that points at it (those take the right spelling). The
 * old spelling becomes an alias on $keep, and $gone stays as a pointer: hidden from search, its page redirects.
 * Only records nobody owns are merged; an owned record is the owner's to change.
 */
class MergeHorses
{
    public function __invoke(Horse $keep, Horse $gone, User $staff, ?string $note = null): void
    {
        abort_if($keep->id === $gone->id || $gone->merged_into_id || $keep->merged_into_id, 422, 'These records cannot be merged.');
        abort_if($gone->activeStewards()->exists(), 422, 'The misspelled record has an owner: ask them, or change it from their record.');

        DB::transaction(function () use ($keep, $gone, $staff, $note) {
            $moved = [];
            foreach (['horse_events', 'documents', 'media', 'show_records', 'sale_lots'] as $t) {
                $moved[$t] = DB::table($t)->where('horse_id', $gone->id)->update(['horse_id' => $keep->id]);
            }
            $have = $keep->identifiers()->pluck('value_normalized')->flip();
            $moved['horse_identifiers'] = DB::table('horse_identifiers')->where('horse_id', $gone->id)
                ->whereNotIn('value_normalized', $have->keys()->all() ?: [''])->update(['horse_id' => $keep->id]);

            // Its own chart: lines $keep lacks move over; nothing on $keep is overwritten.
            $positions = $keep->pedigree()->pluck('position')->all();
            $moved['pedigree_lines'] = DB::table('pedigree_entries')->where('horse_id', $gone->id)->whereNull('superseded_by')
                ->whereNotIn('position', $positions ?: [''])->update(['horse_id' => $keep->id]);

            // Other charts that name this horse now point at the right record and spelling.
            $moved['charts'] = DB::table('pedigree_entries')->where('linked_horse_id', $gone->id)
                ->update(['linked_horse_id' => $keep->id, 'name' => $keep->registered_name, 'updated_at' => now()]);

            foreach (DB::table('horse_names')->where('horse_id', $gone->id)->get() as $n) {
                DB::table('horse_names')->where('id', $n->id)->update(['horse_id' => $keep->id]);
            }
            if ($gone->registered_name && strcasecmp($gone->registered_name, (string) $keep->registered_name) !== 0
                && ! $keep->names()->whereRaw('lower(name) = lower(?)', [$gone->registered_name])->exists()) {
                HorseName::create(['horse_id' => $keep->id, 'name' => $gone->registered_name, 'kind' => 'alias', 'visibility' => 'shared', 'added_by' => $staff->id]);
            }

            // Fill identity gaps on $keep from $gone (never overwriting).
            $fill = [];
            foreach (['sex', 'color', 'breed', 'markings'] as $f) {
                if (! $keep->{$f} && $gone->{$f}) {
                    $fill[$f] = $gone->{$f};
                }
            }
            if (($keep->birth_precision ?? 'unknown') === 'unknown' && ($gone->birth_precision ?? 'unknown') !== 'unknown') {
                $fill += ['birth_precision' => $gone->birth_precision, 'birth_year' => $gone->birth_year, 'birth_date' => $gone->birth_date];
            }
            $keep->forceFill($fill + ($keep->activeStewards()->exists() ? [] : ['listed' => true, 'public_summary' => $keep->public_summary || $gone->public_summary]))->save();
            if ($fill) {
                app(RecordFacts::class)($keep, $staff, RecordFacts::fromColumns($keep->only(array_merge(array_keys($fill), ['birth_precision', 'birth_year', 'birth_date']))), 'historical');
            }

            $gone->forceFill(['merged_into_id' => $keep->id, 'merged_at' => now(), 'listed' => false, 'public_summary' => false])->save();

            // Other open pairs that involve the old record now concern the kept one, or close if they'd pair it with itself.
            foreach (NameReview::where('status', 'open')->where(fn ($q) => $q->where('horse_a_id', $gone->id)->orWhere('horse_b_id', $gone->id))->get() as $r) {
                $other = $r->horse_a_id === $gone->id ? $r->horse_b_id : $r->horse_a_id;
                $r->forceFill(['status' => 'closed', 'note' => 'Closed: '.$gone->registered_name.' was merged into '.$keep->registered_name.'.'])->save();
                if ($other !== $keep->id) {
                    [$a, $b] = strcmp($keep->id, $other) < 0 ? [$keep->id, $other] : [$other, $keep->id];
                    NameReview::insertOrIgnore(['horse_a_id' => $a, 'horse_b_id' => $b, 'match' => $r->match, 'priority' => $r->priority, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            Audit::record('horse.merged', $gone, null, ['into' => $keep->horsefax_id, 'old_name' => $gone->registered_name, 'kept_name' => $keep->registered_name, 'moved' => $moved, 'note' => $note], $keep->id, $staff->id);
        });
    }
}
