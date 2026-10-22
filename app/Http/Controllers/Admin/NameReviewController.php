<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Record\AddIdentifier;
use App\Actions\Record\MergeHorses;
use App\Actions\Record\NamePairCheck;
use App\Http\Controllers\Controller;
use App\Models\Horse;
use App\Models\NameReview;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Http\Request;

/** Staff list of possible duplicate names: decide "different horses" or "same horse, keep this spelling". */
class NameReviewController extends Controller
{
    public const PRIORITIES = [
        1 => 'Same letters, different spacing or punctuation',
        2 => 'A letter or two apart; one is only known from a pedigree chart',
        3 => 'A letter or two apart; one has no results of its own',
        4 => 'A letter or two apart; both have their own results',
    ];

    public function index(Request $request)
    {
        $p = (int) $request->query('p', 0);
        $status = in_array($request->query('status'), ['open', 'different_horses', 'merged'], true) ? $request->query('status') : 'open';
        $reviews = NameReview::with(['a', 'b'])->where('status', $status)
            ->when($p, fn ($q) => $q->where('priority', $p))
            ->orderBy('priority')->orderBy('id')->paginate(40)->withQueryString();
        $ids = $reviews->flatMap(fn ($r) => [$r->horse_a_id, $r->horse_b_id])->unique()->values()->all();
        $check = app(NamePairCheck::class)->load($ids);
        $facts = $check->facts;
        // Where the two records disagree, say so on the card: the reviewer should check the registry before choosing.
        $warnings = [];
        foreach ($reviews as $r) {
            if ($w = $check->disagreements($r->a, $r->b)) {
                $warnings[$r->id] = $w;
            }
        }
        $counts = NameReview::where('status', 'open')->selectRaw('priority, count(*) n')->groupBy('priority')->pluck('n', 'priority');

        $suggestions = \App\Models\ReviewSuggestion::with('horse')->where('subject_type', 'name_review')
            ->whereIn('subject_id', $reviews->pluck('id')->map(fn ($id) => (string) $id)->all())->get()->keyBy('subject_id');

        return view('admin.names', compact('reviews', 'facts', 'counts', 'p', 'status', 'warnings', 'suggestions'));
    }

    public function decide(Request $request, NameReview $review, MergeHorses $merge, AddIdentifier $addId)
    {
        abort_unless($review->status === 'open', 409, 'Already decided.');
        $data = $request->validate([
            'decision' => 'required|in:different,keep_a,keep_b',
            'reg_a' => 'nullable|string|max:40', 'reg_b' => 'nullable|string|max:40', 'note' => 'nullable|string|max:1000',
        ]);
        $staff = $request->user();
        [$a, $b] = [Horse::findOrFail($review->horse_a_id), Horse::findOrFail($review->horse_b_id)];

        // Registration numbers staff looked up go on the records as registry information (not yet verified).
        foreach ([[$a, $data['reg_a'] ?? null], [$b, $data['reg_b'] ?? null]] as [$h, $reg]) {
            $reg = trim((string) $reg);
            if ($reg !== '' && ! $h->identifiers()->where('kind', 'registration')->where('value_normalized', Record::normalizeIdentifier($reg))->exists()) {
                $addId($h, $staff, ['kind' => 'registration', 'registry' => 'AQHA', 'value' => $reg, 'visibility' => 'public', 'source_type' => 'official_source']);
            }
        }

        if ($data['decision'] === 'different') {
            $review->forceFill(['status' => 'different_horses', 'note' => $data['note'] ?? null, 'registration_number' => trim(($data['reg_a'] ?? '').' / '.($data['reg_b'] ?? ''), ' /') ?: null,
                'decided_by' => $staff->id, 'decided_at' => now()])->save();
            Audit::record('name_review.different_horses', $review, null, ['a' => $a->horsefax_id, 'b' => $b->horsefax_id], $a->id, $staff->id);

            return back()->with('status', $a->registered_name.' and '.$b->registered_name.' are marked as different horses.');
        }

        [$keep, $gone] = $data['decision'] === 'keep_a' ? [$a, $b] : [$b, $a];
        $merge($keep, $gone, $staff, $data['note'] ?? null);
        $review->forceFill(['status' => 'merged', 'kept_horse_id' => $keep->id, 'note' => $data['note'] ?? null,
            'registration_number' => trim((string) ($data['decision'] === 'keep_a' ? ($data['reg_a'] ?? '') : ($data['reg_b'] ?? ''))) ?: null,
            'decided_by' => $staff->id, 'decided_at' => now()])->save();

        return back()->with('status', '"'.$gone->registered_name.'" was merged into "'.$keep->registered_name.'" ('.$keep->horsefax_id.').');
    }

    /**
     * Priority-1 pairs (same letters, different spacing or punctuation) where nothing on the two records disagrees and
     * neither is owned: one page, each row preset to "same horse, keep the suggested spelling". Staff switch any row to
     * the other spelling, "different horses" or "skip", then apply. Nothing merges without that click (Richard, Oct 19 2026).
     *
     * @return \Illuminate\Support\Collection<int, array{review: NameReview, keep: string}>
     */
    public static function batchRows(): \Illuminate\Support\Collection
    {
        $reviews = NameReview::with(['a', 'b'])->where('status', 'open')->where('priority', 1)->orderBy('id')->get()
            ->filter(fn ($r) => $r->a && $r->b && ! $r->a->merged_into_id && ! $r->b->merged_into_id
                && ! \App\Support\Record::placeholderName($r->a->registered_name)); // two "Pending" records aren't one horse
        $check = app(NamePairCheck::class)->load($reviews->flatMap(fn ($r) => [$r->horse_a_id, $r->horse_b_id])->unique()->values()->all());

        return $reviews->reject(fn ($r) => $check->owned($r->a) || $check->owned($r->b) || $check->disagreements($r->a, $r->b))
            ->map(fn ($r) => ['review' => $r, 'keep' => $check->suggestedKeep($r->a, $r->b)->id === $r->horse_a_id ? 'keep_a' : 'keep_b', 'check' => $check])
            ->values();
    }

    public function batch(Request $request)
    {
        $rows = self::batchRows();
        $page = $rows->take(50);
        $counts = NameReview::where('status', 'open')->selectRaw('priority, count(*) n')->groupBy('priority')->pluck('n', 'priority');

        return view('admin.names-batch', ['rows' => $page, 'total' => $rows->count(), 'counts' => $counts]);
    }

    public function batchDecide(Request $request, MergeHorses $merge)
    {
        $data = $request->validate(['decisions' => 'required|array|max:100', 'decisions.*' => 'required|in:keep_a,keep_b,different,skip']);
        $staff = $request->user();
        $out = ['merged' => 0, 'different' => 0, 'skipped' => 0, 'changed' => 0];
        foreach ($data['decisions'] as $id => $decision) {
            if ($decision === 'skip') {
                $out['skipped']++;
                continue;
            }
            $review = NameReview::with(['a', 'b'])->find((int) $id);
            // Decided elsewhere meanwhile, or no longer a clean pair (a merge on this page, new facts): left for the one-by-one list.
            $check = $review ? app(NamePairCheck::class)->load([$review->horse_a_id, $review->horse_b_id]) : null;
            if (! $review || $review->status !== 'open' || $review->priority !== 1 || $review->a->merged_into_id || $review->b->merged_into_id
                || Record::placeholderName($review->a->registered_name)
                || $check->owned($review->a) || $check->owned($review->b) || $check->disagreements($review->a, $review->b)) {
                $out['changed']++;
                continue;
            }
            if ($decision === 'different') {
                $review->forceFill(['status' => 'different_horses', 'note' => 'Batch review', 'decided_by' => $staff->id, 'decided_at' => now()])->save();
                Audit::record('name_review.different_horses', $review, null, ['a' => $review->a->horsefax_id, 'b' => $review->b->horsefax_id, 'batch' => true], $review->horse_a_id, $staff->id);
                $out['different']++;
                continue;
            }
            [$keep, $gone] = $decision === 'keep_a' ? [$review->a, $review->b] : [$review->b, $review->a];
            $merge($keep, $gone, $staff, 'Batch approval: same letters, nothing on the records disagrees');
            $review->forceFill(['status' => 'merged', 'kept_horse_id' => $keep->id, 'note' => 'Batch approval',
                'decided_by' => $staff->id, 'decided_at' => now()])->save();
            $out['merged']++;
        }

        return redirect()->route('admin.names.batch')->with('status', $out['merged'].' merged, '.$out['different'].' marked different horses, '.$out['skipped'].' skipped'
            .($out['changed'] ? ', '.$out['changed'].' left for one-by-one review (changed since the page loaded)' : '').'.');
    }
}
