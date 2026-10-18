<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\ReviewItem;
use App\Models\ShowRecord;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Http\Request;

/** Registered names found in published show results, before (or after) they're matched to a horse record. */
class ShowRecordController extends Controller
{
    public function show(ShowRecord $record)
    {
        if ($record->horse_id && $record->horse && ($record->horse->public_summary || $record->horse->listed)) {
            return redirect()->route('horse.public', $record->horse, 301);
        }

        return view('public.show-record', [
            'record' => $record,
            'byYear' => $record->appearances()->orderByDesc('event_year')->orderBy('event_name')->orderBy('class_name')->get()->groupBy('event_year'),
        ]);
    }

    /** An owner says these results are their horse's: staff check and attach them. */
    public function claim(Request $request, Horse $horse, ShowRecord $record)
    {
        abort_unless($horse->canBeEditedBy($request->user()) && ! $record->horse_id, 403);
        $exists = ReviewItem::where('kind', 'results_match')->where('subject_id', $record->id)->where('horse_id', $horse->id)->where('status', 'open')->exists();
        if (! $exists) {
            $item = ReviewItem::create([
                'kind' => 'results_match', 'horse_id' => $horse->id, 'submitted_by' => $request->user()->id,
                'subject_type' => 'ShowRecord', 'subject_id' => $record->id,
                'statement' => 'The owner says the '.$record->appearances.' published results under "'.$record->display_name.'" ('.$record->yearsLabel().') belong to this horse.',
            ]);
            Audit::record('show_results.claimed', $item, null, ['show_record' => $record->display_name], $horse->id);
            Notify::staff('Horsefax results match request for '.$horse->horsefax_id, $item->statement);
        }

        return back()->with('status', 'Thanks. Horsefax staff will check the results against your horse and attach them.');
    }
}
