<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Record\AddIdentifier;
use App\Actions\Record\MergeHorses;
use App\Http\Controllers\Controller;
use App\Models\Horse;
use App\Models\NameReview;
use App\Models\PedigreeEntry;
use App\Models\ShowRecord;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $facts = [
            'parents' => PedigreeEntry::whereIn('horse_id', $ids)->whereIn('position', ['s', 'd'])->whereNull('superseded_by')->get()->groupBy('horse_id'),
            'results' => DB::table('horse_events')->whereIn('horse_id', $ids)->where('category', 'competition')->selectRaw('horse_id, count(*) n')->groupBy('horse_id')->pluck('n', 'horse_id'),
            'shown' => ShowRecord::whereIn('horse_id', $ids)->selectRaw('horse_id, min(first_year) a, max(last_year) b')->groupBy('horse_id')->get()->keyBy('horse_id'),
            'charts' => PedigreeEntry::whereIn('linked_horse_id', $ids)->selectRaw('linked_horse_id, count(*) n')->groupBy('linked_horse_id')->pluck('n', 'linked_horse_id'),
            'regs' => DB::table('horse_identifiers')->whereIn('horse_id', $ids)->where('kind', 'registration')->whereNull('superseded_by')->get()->groupBy('horse_id'),
            'owned' => DB::table('stewardships')->whereIn('horse_id', $ids)->where('status', '!=', 'ended')->pluck('horse_id')->flip(),
        ];
        $counts = NameReview::where('status', 'open')->selectRaw('priority, count(*) n')->groupBy('priority')->pluck('n', 'priority');

        return view('admin.names', compact('reviews', 'facts', 'counts', 'p', 'status'));
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
}
