<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseName;
use App\Models\NameReview;
use App\Models\PedigreeEntry;
use App\Models\ReviewItem;
use App\Models\ShowRecord;
use App\Models\User;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Support\Facades\DB;

/**
 * Staff have confirmed that $gone is the same horse as $keep under a misspelled name (usually from a registry name
 * lookup). Everything on $gone moves to $keep: results, sale lots, identifiers, documents, photos, its pedigree lines
 * that $keep doesn't have, and every pedigree line elsewhere that points at it (those take the right spelling).
 * Where the two disagree (foal year, sex, color, a pedigree line), the other value is kept and goes to the review
 * queue for staff to choose (Richard, Oct 18 2026): nothing is dropped. The
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

            // Its own chart: lines $keep lacks move over; nothing on $keep is overwritten. Where both have a line in the
            // same place and the names differ, the other line is kept behind the current one and flagged for staff.
            $keepLines = $keep->pedigree()->get()->keyBy('position');
            $moved['pedigree_lines'] = DB::table('pedigree_entries')->where('horse_id', $gone->id)->whereNull('superseded_by')
                ->whereNotIn('position', $keepLines->keys()->all() ?: [''])->update(['horse_id' => $keep->id]);
            $conflicts = [];
            foreach (PedigreeEntry::where('horse_id', $gone->id)->whereNull('superseded_by')->get() as $line) {
                $current = $keepLines->get($line->position);
                if (! $current || ShowRecord::key((string) $current->name) === ShowRecord::key((string) $line->name)) {
                    continue; // same horse in the same place: nothing to decide
                }
                $line->forceFill(['horse_id' => $keep->id, 'superseded_by' => $current->id, 'status' => 'disputed'])->save();
                ReviewItem::create([
                    'kind' => 'pedigree_conflict', 'horse_id' => $keep->id, 'submitted_by' => $staff->id,
                    'subject_type' => 'PedigreeEntry', 'subject_id' => $line->id,
                    'statement' => 'Merged records disagree: '.Record::pedigreeLabel($line->position).' is "'.$current->name.'" here, but the merged record "'.$gone->registered_name.'" ('.$gone->horsefax_id.') said "'.$line->name.'". Approve to use "'.$line->name.'" instead; reject to keep "'.$current->name.'".',
                    'details' => ['current_entry_id' => $current->id, 'merged_from' => $gone->horsefax_id],
                ]);
                $conflicts[] = $line->position;
            }
            $moved['pedigree_conflicts'] = $conflicts;

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

            // Identity: fill gaps on $keep from $gone (never overwriting). Where both have a value and they differ, the
            // other value is kept as a held fact on $keep and flagged for staff, so nothing is silently dropped.
            $fill = [];
            $held = [];
            foreach (['sex', 'color', 'breed', 'markings'] as $f) {
                if (! $gone->{$f}) {
                    continue;
                }
                if (! $keep->{$f}) {
                    $fill[$f] = $gone->{$f};
                } elseif (strcasecmp(trim((string) $keep->{$f}), trim((string) $gone->{$f})) !== 0 && in_array($f, ['sex', 'color'], true)) {
                    $held[$f] = [[$gone->{$f}, null], (string) $keep->{$f}];
                }
            }
            $goneBirth = ($gone->birth_precision ?? 'unknown') === 'unknown' ? null : ($gone->birth_precision === 'day' ? $gone->birth_date?->toDateString() : (string) $gone->birth_year);
            if (($keep->birth_precision ?? 'unknown') === 'unknown') {
                if ($goneBirth) {
                    $fill += ['birth_precision' => $gone->birth_precision, 'birth_year' => $gone->birth_year, 'birth_date' => $gone->birth_date];
                }
            } elseif ($goneBirth && (int) $gone->birth_year !== (int) $keep->birth_year) {
                $keepBirth = $keep->birth_precision === 'day' ? $keep->birth_date?->format('M j, Y') : (string) $keep->birth_year;
                $held['birth'] = [[$goneBirth, ['precision' => $gone->birth_precision]], (string) $keepBirth];
            }
            $keep->forceFill($fill + ($keep->activeStewards()->exists() ? [] : ['listed' => true, 'public_summary' => $keep->public_summary || $gone->public_summary]))->save();
            if ($fill) {
                app(RecordFacts::class)($keep, $staff, RecordFacts::fromColumns($keep->only(array_merge(array_keys($fill), ['birth_precision', 'birth_year', 'birth_date']))), 'historical');
            }
            foreach ($held as $field => [[$value, $meta], $currentShown]) {
                $fact = \App\Models\HorseFact::create([
                    'horse_id' => $keep->id, 'field' => $field, 'value' => $value, 'meta' => $meta, 'source_type' => 'historical',
                    'status' => 'unverified', 'is_current' => false, 'contributor_id' => $staff->id, 'contributor_role' => 'staff',
                ]);
                $current = $keep->currentFacts()->where('field', $field)->first();
                ReviewItem::create([
                    'kind' => 'fact_conflict', 'horse_id' => $keep->id, 'submitted_by' => $staff->id, 'subject_type' => 'HorseFact', 'subject_id' => $fact->id,
                    'statement' => 'Merged records disagree: '.strtolower($fact->label()).' is "'.$currentShown.'" here, but the merged record "'.$gone->registered_name.'" ('.$gone->horsefax_id.') said "'.$fact->display().'". Approve to use "'.$fact->display().'" instead; reject to keep "'.$currentShown.'".',
                    'details' => ['current_fact_id' => $current?->id, 'merged_from' => $gone->horsefax_id],
                ]);
                $moved['identity_conflicts'][] = $field;
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
