<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseMerge;
use App\Models\NameReview;
use App\Models\ReviewItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a merge from its horse_merges log: the rows it moved go back, the kept record's fields return to what they
 * were, and the pair goes back on the staff list with rule "undone" so the rules never merge it again. Anything that
 * arrived on the kept record since stays there. Refused once staff have decided a conflict the merge raised, or the
 * kept record has itself been merged away: those need a person (or the later merge undone first).
 */
class UnmergeHorses
{
    /** @return string|null why it was refused, or null when done */
    public function __invoke(HorseMerge $merge, User $by, ?string $note = null): ?string
    {
        if ($merge->undone_at) {
            return 'Already undone.';
        }
        $log = $merge->log;
        $keep = Horse::findOrFail($merge->kept_horse_id);
        $gone = Horse::findOrFail($merge->gone_horse_id);
        if ($keep->merged_into_id) {
            return $keep->horsefax_id.' has since been merged into another record: undo that first.';
        }
        if (ReviewItem::whereIn('id', $log['review_items'] ?? [])->where('status', '!=', 'open')->exists()) {
            return 'Staff have decided a conflict this merge raised.';
        }

        DB::transaction(function () use ($merge, $by, $note, $log, $keep, $gone) {
            foreach ($log['rows'] as $table => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->update(['horse_id' => $gone->id]);
                }
            }
            foreach ($log['held_lines'] ?? [] as $line) {
                DB::table('pedigree_entries')->where('id', $line['id'])->update(['horse_id' => $gone->id, 'superseded_by' => null, 'status' => $line['status']]);
            }
            foreach ($log['charts'] ?? [] as $id => $name) {
                DB::table('pedigree_entries')->where('id', $id)->update(['linked_horse_id' => $gone->id, 'name' => $name]);
            }
            ReviewItem::whereIn('id', $log['review_items'] ?? [])->delete();
            if ($log['alias'] ?? null) {
                DB::table('horse_names')->where('id', $log['alias'])->delete();
            }

            $before = $log['facts_before'] ?? [];
            DB::table('horse_facts')->where('horse_id', $keep->id)->whereNotIn('id', array_keys($before))->delete();
            foreach ($before as $id => $current) {
                DB::table('horse_facts')->where('id', $id)->update(['is_current' => $current]);
            }
            DB::table('horses')->where('id', $keep->id)->update($log['keep']);
            DB::table('horses')->where('id', $gone->id)->update($log['gone']);

            NameReview::whereIn('id', $log['reviews_closed'] ?? [])->update(['status' => 'open', 'note' => null]);
            foreach ($log['reviews_added'] ?? [] as [$a, $b]) {
                NameReview::where('horse_a_id', $a)->where('horse_b_id', $b)->where('status', 'open')->delete();
            }
            [$a, $b] = strcmp($keep->id, $gone->id) < 0 ? [$keep->id, $gone->id] : [$gone->id, $keep->id];
            NameReview::where('horse_a_id', $a)->where('horse_b_id', $b)->update(['status' => 'open', 'rule' => 'undone', 'kept_horse_id' => null,
                'decided_by' => null, 'decided_at' => null, 'note' => $note ?? 'Merge undone: these need a person to decide.']);

            $merge->forceFill(['undone_at' => now()])->save();
            Audit::record('horse.unmerged', $gone, null, ['from' => $keep->horsefax_id, 'merge' => $merge->id, 'note' => $note], $keep->id, $by->id);
        });

        return null;
    }
}
