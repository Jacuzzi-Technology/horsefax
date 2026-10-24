<?php

namespace App\Actions\Record;

use App\Models\Horse;
use Illuminate\Support\Facades\DB;

/**
 * After records are removed (a retired source's purge, a cleanup of mis-read imports), the ancestor records
 * CreateAncestorRecords made for their pedigrees can be left with nothing pointing at them. This removes those, and
 * then the ancestors behind them that become orphans in turn (their own pedigree lines go with them).
 *
 * Only records with source "pedigree" are touched, and only when nothing else refers to them: no pedigree line links
 * to them, and no sale lot, show record, registry record or owner history, steward, claim, document, transfer, media
 * or verification. An ancestor another horse's chart also links to is kept. Returns the ids removed (callers run it
 * inside a transaction, so a dry run can roll back).
 */
class PruneOrphanAncestors
{
    /** @param list<string> $candidates horse ids to check first (e.g. the linked_horse_id of the removed records' pedigree lines) */
    public function __invoke(array $candidates): array
    {
        $removed = [];
        $queue = array_values(array_unique(array_filter($candidates)));
        while ($queue) {
            // A candidate kept in one round (another ancestor still linked to it) is checked again when that ancestor
            // goes: so only removed ids are skipped, never ones merely seen.
            $batch = array_values(array_diff($queue, $removed));
            $queue = [];
            if (! $batch) {
                break;
            }
            $orphans = Horse::whereIn('id', $batch)->where('source', 'pedigree')->pluck('id')
                ->filter(fn ($id) => ! self::referenced($id))->values()->all();
            if (! $orphans) {
                continue;
            }
            // The lines behind them: checked next, once these are gone.
            $queue = DB::table('pedigree_entries')->whereIn('horse_id', $orphans)->whereNotNull('linked_horse_id')->pluck('linked_horse_id')->unique()->values()->all();
            DB::table('review_suggestions')->whereIn('horse_id', $orphans)->delete();
            DB::table('name_reviews')->where(fn ($q) => $q->whereIn('horse_a_id', $orphans)->orWhereIn('horse_b_id', $orphans)->orWhereIn('kept_horse_id', $orphans))->delete();
            Horse::whereIn('id', $orphans)->get()->each->delete();
            $removed = array_merge($removed, $orphans);
        }

        return $removed;
    }

    public static function referenced(string $id): bool
    {
        if (DB::table('pedigree_entries')->where('linked_horse_id', $id)->where('horse_id', '<>', $id)->exists()) {
            return true;
        }
        foreach (['sale_lots', 'show_records', 'registry_records', 'registry_owners', 'stewardships', 'claim_codes', 'documents', 'transfers', 'media', 'verifications'] as $table) {
            if (DB::table($table)->where('horse_id', $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidates from the audit trail: every ancestor that a pedigree line of a record that no longer exists was
     * linked to ("pedigree.linked" keeps the removed record's id and the linked ancestor's id).
     */
    public static function fromAuditTrail(): array
    {
        return DB::table('audit_log as a')->where('a.action', 'pedigree.linked')
            ->whereNotExists(fn ($q) => $q->from('horses as h')->whereColumn('h.id', 'a.horse_id'))
            ->pluck('a.after')->map(fn ($j) => json_decode((string) $j, true)['linked_horse_id'] ?? null)->filter()->unique()->values()->all();
    }
}
