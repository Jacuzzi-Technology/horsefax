<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\NameReview;
use Illuminate\Support\Facades\DB;

/**
 * Merges the pairs NamePairRules calls the same horse, without a staff click (Richard, Nov 2 2026). Each pair is checked
 * again on today's facts. Records with an owner or a verified identity are left for staff. Where the two disagree on
 * something, MergeHorses keeps the other value and raises it for review; UnmergeHorses reverses any merge.
 */
class MergeDuplicates
{
    /**
     * @param  list<int>|null  $reviewIds  only these pairs (a test batch)
     * @return array{merged:int, left_for_staff:int, no_longer_same:int, review_items:int}
     */
    public function __invoke(?int $limit = null, bool $dryRun = false, ?array $reviewIds = null, ?string $batch = null, ?\Closure $log = null): array
    {
        $out = ['merged' => 0, 'left_for_staff' => 0, 'no_longer_same' => 0, 'review_items' => 0];
        $system = BuildRecordsFromResults::system();
        $batch ??= 'auto-'.now()->format('Ymd-His');
        $rules = array_diff(array_keys(NamePairRules::LABELS), ['near_name_both_parents']);
        $ids = NameReview::where('status', 'open')->whereIn('rule', $rules)->when($reviewIds !== null, fn ($q) => $q->whereIn('id', $reviewIds))->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            if ($limit !== null && $out['merged'] >= $limit) {
                break;
            }
            $review = NameReview::find($id);
            if (! $review || $review->status !== 'open') {
                continue; // an earlier merge in this run closed it
            }
            $a = Horse::find($review->horse_a_id);
            $b = Horse::find($review->horse_b_id);
            $facts = NamePairRules::load([$review->horse_a_id, $review->horse_b_id]);
            if (! isset($facts[$a->id], $facts[$b->id]) || NamePairRules::classify($facts[$a->id], $facts[$b->id])[0] !== NamePairRules::SAME) {
                $out['no_longer_same']++;
                continue;
            }
            $check = app(NamePairCheck::class)->load([$a->id, $b->id]);
            if ($check->owned($a) || $check->owned($b) || $a->identity_status !== 'unverified' || $b->identity_status !== 'unverified') {
                $out['left_for_staff']++;
                continue;
            }
            $keep = $check->suggestedKeep($a, $b);
            $gone = $keep->id === $a->id ? $b : $a;
            $note = 'Automatic: '.(NamePairRules::LABELS[$review->rule] ?? $review->rule).'.';
            $items = \App\Models\ReviewItem::count();

            DB::beginTransaction();
            try {
                app(MergeHorses::class)($keep, $gone, $system, $note, $review->rule, $batch);
                $review->refresh()->forceFill(['status' => 'merged', 'kept_horse_id' => $keep->id, 'note' => $note, 'decided_at' => now()])->save();
                $out['merged']++;
                $out['review_items'] += \App\Models\ReviewItem::count() - $items;
                $dryRun ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            if ($log && $out['merged'] % 250 === 0) {
                $log(json_encode($out));
            }
        }

        return $out;
    }
}
