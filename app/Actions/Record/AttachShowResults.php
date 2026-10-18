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
    /** $showNames false leaves out results published under hunter/jumper show names (EquestrianLive), which often aren't the same horse. */
    public function __invoke(ShowRecord $record, Horse $horse, User $staff, bool $showNames = true): int
    {
        return DB::transaction(function () use ($record, $horse, $staff, $showNames) {
            $n = 0;
            $q = $record->appearances()->whereNull('horse_event_id')->orderBy('event_year');
            // A result printed with a different registration number than the record's belongs to another horse.
            $regs = $horse->identifiers()->where('kind', 'registration')->whereNull('superseded_by')->pluck('value_normalized')->all();
            if ($regs) {
                $q->where(fn ($w) => $w->whereNull('registration_number')->orWhereIn(DB::raw("upper(regexp_replace(registration_number, '[^A-Za-z0-9]', '', 'g'))"), $regs));
            }
            if (! $showNames) {
                $q->whereNotIn('data_source_id', \App\Models\DataSource::whereIn('key', BuildRecordsFromResults::SHOW_NAME_SOURCES)->select('id'));
            }
            foreach ($q->get() as $a) {
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

            // Published parentage (NRCHA) seeds an empty pedigree: historical, unverified, never over existing lines.
            $par = $record->parentage();
            if (($par['sire'] || $par['dam']) && ! $horse->pedigree()->exists()) {
                foreach (['s' => $par['sire'], 'd' => $par['dam'], 'ds' => $par['dam_sire']] as $pos => $name) {
                    if ($name) {
                        \App\Models\PedigreeEntry::create(['horse_id' => $horse->id, 'position' => $pos, 'name' => $name, 'visibility' => 'public',
                            'added_by' => $staff->id, 'source_type' => 'historical', 'status' => 'unverified']);
                    }
                }
                Audit::record('pedigree.seeded_from_results', $horse, null, $par, $horse->id, $staff->id);
                app(CreateAncestorRecords::class)($horse, $staff);
            }
            // A registration number and foal year printed with the results (WSQHA) fill what the record is missing.
            $published = $record->publishedIdentity();
            if ($published['registration_number'] || $published['foal_year']) {
                app(AddPublishedIdentity::class)($horse, BuildRecordsFromResults::system(), $published, 'show results for '.$record->display_name);
            }
            Audit::record('show_results.attached', $horse, null, ['show_record' => $record->display_name, 'results' => $n], $horse->id, $staff->id);

            return $n;
        });
    }
}
