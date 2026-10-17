<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\ShowRecord;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Staff confirm that a registered name's published results belong to a horse. Each result becomes a competition
 * entry on the horse's record: source "Historical / third-party" (we collected it from a published page), not
 * verified, linked back to its source page. Idempotent: results already attached are skipped.
 */
class AttachShowResults
{
    public function __invoke(ShowRecord $record, Horse $horse, User $staff): int
    {
        return DB::transaction(function () use ($record, $horse, $staff) {
            $n = 0;
            foreach ($record->appearances()->whereNull('horse_event_id')->orderBy('event_year')->get() as $a) {
                $event = HorseEvent::create([
                    'horse_id' => $horse->id, 'category' => 'competition', 'type' => 'show',
                    'title' => $a->class_name, 'organization' => $a->organization === 'AQHA' ? 'AQHA (at the '.$a->event_name.')' : $a->organization,
                    'event_name' => $a->event_name.' '.$a->event_year, 'class_name' => $a->class_name, 'round' => $a->round,
                    'placing' => $a->placing, 'earnings_cents' => $a->earnings_cents,
                    'occurred_on' => $a->event_year.'-10-01', 'date_precision' => 'year',
                    'details' => 'From the published results: '.$a->source_url,
                    'contributor_id' => $staff->id, 'contributor_role' => 'staff',
                    'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public',
                ]);
                $a->forceFill(['horse_event_id' => $event->id])->save();
                $n++;
            }
            $record->forceFill(['horse_id' => $horse->id, 'matched_at' => now()])->save();
            Audit::record('show_results.attached', $horse, null, ['show_record' => $record->display_name, 'results' => $n], $horse->id, $staff->id);

            return $n;
        });
    }
}
