<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\SaleLot;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Support\Facades\DB;

/**
 * Repair for the Robin Glenn load (found Oct 23 2026): catalogs print unnamed foals as "Pending/<dam>" ("Pending A/<dam>",
 * "Pending - <dam>", ...), and the registered-name rule took those labels for names before Record::placeholderName()
 * knew them, so unverified records were created under them.
 *
 * A record is removed only when all of this holds: the system made it from a published sale lot (match_tier "created"),
 * it is unverified, not merged, its name is such a label, and nothing else points at it. Every sale lot on it carries a
 * label too; no show record, registry record or owner history, steward, contributor, claim, document, transfer, media,
 * radiograph, share link, verification or review item; no event except the sale prices its own lots put there; no name or pedigree line a person added; no other record
 * merged into it or linking to it as an ancestor (ancestors first entered in its chart don't count: they're pruned or kept below); no name review a person decided. Anything else is kept and counted.
 *
 * For each record removed: its lots are detached and left as unmatched source records (sire, dam and dam's sire stay on
 * the lot, where they already were); the system's review suggestions and automatic name reviews on it go; the record
 * goes with those sale-price events (an audit entry keeps its id and name); then ancestor records nothing points at any more go (PruneOrphanAncestors).
 * Label lots still waiting on staff without a record (queued with a system suggestion) go back to unmatched as well.
 * Everything runs in one transaction; a dry run rolls it back, so its counts are real.
 */
class CleanPendingFoalRecords
{
    public const NOTE = 'Unnamed foal label (e.g. "Pending/<dam>"), not a registered name';

    private const BLOCKERS = ['show_records', 'registry_records', 'registry_owners', 'stewardships', 'contributors', 'claim_codes', 'documents',
        'transfers', 'media', 'radiograph_sets', 'share_links', 'verifications', 'review_items'];

    /** @return array{0: array<string,int|array>, 1: list<array{horsefax_id:string,name:string,sale:?string}>} counts, and the records removed (or that a dry run would remove) */
    public function __invoke(bool $dryRun = false, ?\Closure $log = null): array
    {
        $out = ['label_records' => 0, 'removed' => 0, 'lots_detached' => 0, 'queued_lots_reset' => 0, 'ancestors_pruned' => 0, 'kept' => []];
        $list = [];
        DB::beginTransaction();
        try {
            $this->run($out, $list, $log);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $dryRun ? DB::rollBack() : DB::commit();

        return [$out, $list];
    }

    private function run(array &$out, array &$list, ?\Closure $log): void
    {
        $system = BuildRecordsFromResults::system();
        $records = Horse::where('source', 'published')->where('created_by', $system->id)->where('identity_status', 'unverified')
            ->whereNull('merged_into_id')->where('registered_name', 'ilike', '%pending%')
            ->whereExists(fn ($q) => $q->from('sale_lots')->whereColumn('sale_lots.horse_id', 'horses.id')->where('match_tier', 'created'))
            ->get()->filter(fn (Horse $h) => Record::placeholderName($h->registered_name))->values();
        $out['label_records'] = $records->count();
        $log && $log($records->count().' system-made records named with an unnamed-foal label');

        $remove = [];
        foreach ($records as $h) {
            if ($why = $this->keep($h, $system->id)) {
                $out['kept'][$why] = ($out['kept'][$why] ?? 0) + 1;
                continue;
            }
            $remove[] = $h;
        }

        $prune = [];
        foreach (array_chunk($remove, 500) as $chunk) {
            $ids = array_map(fn (Horse $h) => $h->id, $chunk);
            $sales = SaleLot::whereIn('horse_id', $ids)->where('match_tier', 'created')->pluck('sale_name', 'horse_id');
            foreach ($chunk as $h) {
                $list[] = ['horsefax_id' => $h->horsefax_id, 'name' => $h->registered_name, 'sale' => $sales[$h->id] ?? null];
            }
            $prune = array_merge($prune, DB::table('pedigree_entries')->whereIn('horse_id', $ids)->whereNotNull('linked_horse_id')->pluck('linked_horse_id')->all());
            // Ancestors first entered in its chart: pruned below if nothing else needs them; kept ones lose the dangling pointer.
            Horse::whereIn('source_horse_id', $ids)->update(['source_horse_id' => null]);
            $lotIds = SaleLot::whereIn('horse_id', $ids)->pluck('id');
            DB::table('review_suggestions')->where('subject_type', 'sale_lot')->whereIn('subject_id', $lotIds)->delete();
            $out['lots_detached'] += SaleLot::whereIn('id', $lotIds)->update(['horse_id' => null, 'horse_event_id' => null, 'match_tier' => null, 'matched_at' => null,
                'review_status' => 'unmatched', 'review_note' => self::NOTE, 'updated_at' => now()]);
            DB::table('review_suggestions')->whereIn('horse_id', $ids)->delete();
            DB::table('name_reviews')->where(fn ($q) => $q->whereIn('horse_a_id', $ids)->orWhereIn('horse_b_id', $ids)->orWhereIn('kept_horse_id', $ids))->delete();
            foreach ($chunk as $h) {
                Audit::record('horse.removed_cleanup', $h, ['horsefax_id' => $h->horsefax_id, 'registered_name' => $h->registered_name], ['reason' => self::NOTE], $h->id, $system->id);
                $h->delete();
                $out['removed']++;
            }
        }

        // Label lots that never got a record but wait on staff with a system suggestion (e.g. proposed against a record of the label).
        $queued = SaleLot::whereNull('horse_id')->where('review_status', 'queued')->where('horse_name', 'ilike', '%pending%')->get()
            ->filter(fn (SaleLot $l) => Record::placeholderName($l->horse_name) && ! DB::table('review_suggestions')->where('subject_type', 'sale_lot')->where('subject_id', $l->id)->whereNotNull('decided_by')->exists());
        foreach ($queued as $l) {
            DB::table('review_suggestions')->where('subject_type', 'sale_lot')->where('subject_id', $l->id)->delete();
            $l->forceFill(['review_status' => 'unmatched', 'review_note' => self::NOTE])->save();
            $out['queued_lots_reset']++;
        }

        $out['ancestors_pruned'] = count(app(PruneOrphanAncestors::class)(array_values(array_unique($prune))));
        $log && $log(json_encode($out));
    }

    /** Why the record must stay (something besides label lots and the system's own facts points at it), or null. */
    private function keep(Horse $h, int $system): ?string
    {
        $lots = SaleLot::where('horse_id', $h->id)->pluck('horse_name');
        if ($lots->contains(fn ($n) => ! Record::placeholderName($n))) {
            return 'has_a_lot_with_a_real_name';
        }
        foreach (self::BLOCKERS as $table) {
            if (DB::table($table)->where('horse_id', $h->id)->exists()) {
                return 'has_'.$table;
            }
        }
        if (DB::table('horse_events')->where('horse_id', $h->id)
            ->whereNotIn('id', SaleLot::where('horse_id', $h->id)->whereNotNull('horse_event_id')->select('horse_event_id'))->exists()) {
            return 'has_an_event_not_from_its_lots';
        }
        if (DB::table('horse_names')->where('horse_id', $h->id)->where('added_by', '<>', $system)->exists()
            || DB::table('pedigree_entries')->where('horse_id', $h->id)->where('added_by', '<>', $system)->exists()) {
            return 'edited_by_a_person';
        }
        if (Horse::where('merged_into_id', $h->id)->exists()) {
            return 'another_record_merged_into_it';
        }
        if (DB::table('pedigree_entries')->where('linked_horse_id', $h->id)->where('horse_id', '<>', $h->id)->exists()) {
            return 'linked_as_an_ancestor';
        }
        if (DB::table('name_reviews')->where(fn ($q) => $q->where('horse_a_id', $h->id)->orWhere('horse_b_id', $h->id)->orWhere('kept_horse_id', $h->id))->whereNotNull('decided_by')->exists()) {
            return 'name_review_decided_by_a_person';
        }

        return null;
    }
}
