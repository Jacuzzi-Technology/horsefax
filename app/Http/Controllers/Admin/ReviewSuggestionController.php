<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Record\AttachSaleLot;
use App\Actions\Record\MatchSaleLots;
use App\Actions\Record\MergeHorses;
use App\Http\Controllers\Controller;
use App\Models\Horse;
use App\Models\NameReview;
use App\Models\ReviewSuggestion;
use App\Models\SaleLot;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Staff accept or reject a search-backed suggestion. Accepting does what the suggestion says, as if staff chose it on the card. */
class ReviewSuggestionController extends Controller
{
    public function decide(Request $request, ReviewSuggestion $suggestion, MergeHorses $merge, AttachSaleLot $attach)
    {
        abort_unless($suggestion->actionable(), 409, 'Already decided.');
        $data = $request->validate(['decision' => 'required|in:accept,reject']);
        $staff = $request->user();
        $note = 'Search suggestion #'.$suggestion->id.' accepted';

        if ($data['decision'] === 'reject') {
            $suggestion->forceFill(['status' => 'rejected', 'decided_by' => $staff->id, 'decided_at' => now()])->save();

            return back()->with('status', 'Suggestion rejected; the item stays on the list.');
        }

        if ($suggestion->subject_type === 'name_review') {
            $review = NameReview::with(['a', 'b'])->findOrFail($suggestion->subject_id);
            abort_unless($review->status === 'open', 409, 'This pair was already decided.');
            if ($suggestion->suggestion === 'different_horses') {
                $review->forceFill(['status' => 'different_horses', 'note' => $note, 'decided_by' => $staff->id, 'decided_at' => now()])->save();
                Audit::record('name_review.different_horses', $review, null, ['a' => $review->a->horsefax_id, 'b' => $review->b->horsefax_id, 'suggestion' => $suggestion->id], $review->horse_a_id, $staff->id);
                $msg = $review->a->registered_name.' and '.$review->b->registered_name.' are marked as different horses.';
            } else {
                [$keep, $gone] = $suggestion->horse_id === $review->horse_a_id ? [$review->a, $review->b] : [$review->b, $review->a];
                $merge($keep, $gone, $staff, $note);
                $review->forceFill(['status' => 'merged', 'kept_horse_id' => $keep->id, 'note' => $note, 'decided_by' => $staff->id, 'decided_at' => now()])->save();
                $msg = '"'.$gone->registered_name.'" ('.$gone->horsefax_id.') was merged into '.$keep->horsefax_id.'.';
            }
        } else {
            $lot = SaleLot::findOrFail($suggestion->subject_id);
            abort_if($lot->horse_id, 409, 'This lot is already on a record.');
            if ($suggestion->suggestion === 'different_horses') {
                $lot->forceFill(['review_status' => 'conflict', 'review_note' => mb_substr(MatchSaleLots::DIFFERENT.'search evidence (suggestion #'.$suggestion->id.')', 0, 255)])->save();
                $msg = 'Marked as a different horse from the records of that name.';
            } else {
                $horse = Horse::findOrFail($suggestion->horse_id);
                if ($why = app(MatchSaleLots::class)->contradiction($lot, $horse)) {
                    return back()->withErrors(['suggestion' => 'That record now contradicts this lot ('.$why.'). Decide it on the card.']);
                }
                if ($lot->publishable()) {
                    $attach($lot, $horse, $staff, 'staff');
                } else {
                    $lot->forceFill(['horse_id' => $horse->id, 'match_tier' => 'staff', 'matched_at' => now(), 'review_status' => $lot->priceKind() ? 'held' : null])->save();
                }
                Audit::record('sale_lot.attach_note', $horse, null, ['note' => $note, 'lot' => $lot->id], $horse->id, $staff->id);
                $msg = $lot->horse_name.' ('.$lot->sale_name.') attached to '.$horse->horsefax_id.'.';
            }
        }
        $suggestion->forceFill(['status' => 'accepted', 'decided_by' => $staff->id, 'decided_at' => now()])->save();

        return back()->with('status', $msg);
    }
}
