<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseIdentifier;
use App\Models\HorseName;
use App\Models\PedigreeEntry;
use App\Models\ShareLink;
use App\Models\Stewardship;
use Illuminate\Support\Carbon;

/**
 * The sample report: explicitly fictional data rendered through the real report template. Nothing here is saved.
 * Names, numbers and events are invented; the registry is "Sample Breed Registry" so no real number is implied.
 */
final class SampleReport
{
    public static function data(): array
    {
        $horse = (new Horse)->forceFill([
            'horsefax_id' => 'HF-SAMP-L3XX', 'registered_name' => 'Juniper Creek Lark', 'barn_name' => 'Lark', 'breed' => 'Quarter Horse',
            'sex' => 'mare', 'birth_precision' => 'year', 'birth_year' => 2016, 'color' => 'Bay', 'markings' => 'Star and snip; left hind sock',
            'height_hands' => 15.1, 'height_measured_on' => '2026-03-02', 'identity_status' => 'verified', 'public_summary' => true,
        ]);
        $link = (new ShareLink)->forceFill(['token' => 'sample', 'label' => 'Sample buyer', 'sections' => array_keys(Record::SHARE_SECTIONS), 'expires_at' => now()->addDays(30)]);

        $e = fn (array $a) => (new HorseEvent)->forceFill($a + ['status' => 'self_reported', 'date_precision' => 'day', 'visibility' => 'shared']);
        $events = collect([
            $e(['category' => 'health', 'type' => 'coggins', 'title' => 'Coggins (EIA) test', 'result' => 'Negative', 'occurred_on' => '2026-03-02', 'expires_on' => '2027-03-02', 'provider' => 'Sample Valley Equine Clinic', 'status' => 'independently_verified']),
            $e(['category' => 'health', 'type' => 'vaccination', 'title' => 'Spring vaccinations', 'details' => 'EEE/WEE, tetanus, West Nile, rabies.', 'occurred_on' => '2026-03-02', 'provider' => 'Sample Valley Equine Clinic', 'status' => 'document_supplied']),
            $e(['category' => 'care', 'type' => 'farrier', 'title' => 'Trim and front shoes reset', 'occurred_on' => '2026-02-10', 'provider' => 'Owner-entered farrier visit']),
            $e(['category' => 'health', 'type' => 'health_certificate', 'title' => 'Health certificate for interstate travel', 'occurred_on' => '2025-10-01', 'expires_on' => '2025-10-31', 'status' => 'document_supplied']),
            $e(['category' => 'training', 'type' => 'program', 'title' => 'Ranch riding program, 90 days', 'details' => 'Trail obstacles, gate work, quiet in the arena and out on the trail.', 'occurred_on' => '2024-06-01', 'date_precision' => 'month', 'provider' => 'Sample Ranch Training']),
            $e(['category' => 'competition', 'type' => 'show', 'title' => 'Ranch riding', 'organization' => 'Sample Horse Association', 'event_name' => 'Sample Fall Classic', 'class_name' => 'Ranch Riding, Amateur', 'discipline' => 'Ranch riding', 'division' => 'Amateur', 'round' => 'Finals', 'rider' => 'Sample Exhibitor A', 'placing' => '2', 'score' => '71.5', 'points' => 3, 'earnings_cents' => 8107, 'occurred_on' => '2025-09-20']),
            $e(['category' => 'competition', 'type' => 'show', 'title' => 'Trail', 'organization' => 'Sample Horse Association', 'event_name' => 'Sample Spring Show', 'class_name' => 'Ranch Trail, Open', 'discipline' => 'Ranch trail', 'division' => 'Open', 'round' => 'Finals', 'rider' => 'Sample Exhibitor B', 'placing' => '1', 'score' => '220', 'points' => 5, 'earnings_cents' => 25000, 'occurred_on' => '2025-04-12', 'status' => 'independently_verified']),
            $e(['category' => 'competition', 'type' => 'show', 'title' => 'Ranch riding', 'organization' => 'Sample Horse Association', 'event_name' => 'Sample Summer Circuit', 'class_name' => 'Ranch Riding, Novice', 'discipline' => 'Ranch riding', 'division' => 'Novice', 'round' => 'Go-round 1', 'rider' => 'Sample Exhibitor A', 'placing' => '3T', 'score' => '70', 'earnings_cents' => 12256, 'occurred_on' => '2024-07-13']),
            $e(['category' => 'ownership', 'type' => 'sale', 'title' => 'Sale', 'sale_venue' => 'Private sale', 'occurred_on' => '2023-05-15', 'date_precision' => 'month', 'amount_cents' => 1250000, 'amount_visibility' => 'shared', 'status' => 'document_supplied']),
            $e(['category' => 'ownership', 'type' => 'breeding', 'title' => 'Foaled', 'sale_venue' => 'Bred by a sample breeder', 'occurred_on' => '2016-04-01', 'date_precision' => 'year']),
        ]);

        $ped = collect([
            's' => ['Sample Gun Smoke', 2008, 'Sorrel'], 'd' => ['Sample Lady Bay', 2010, 'Bay'],
            'ss' => ['Sample Smokin Joe', 1999, null], 'sd' => ['Sample Miss Dually', 2001, null], 'ds' => ['Sample Peppy Bar', 2000, null], 'dd' => ['Sample Lady Lena', 2002, null],
            'sss' => ['Sample Doc Bar Jr', null, null], 'ssd' => ['Sample Poco Rose', null, null], 'sds' => ['Sample Dual Pep', null, null], 'sdd' => ['Sample Hollywood', null, null],
            'dss' => ['Sample Peppy San', null, null], 'dsd' => ['Sample Bar Lady', null, null], 'dds' => ['Sample Lena Doc', null, null], 'ddd' => ['Sample Smart Miss', null, null],
        ])->map(fn ($v, $pos) => (new PedigreeEntry)->forceFill(['position' => $pos, 'name' => $v[0], 'foal_year' => $v[1], 'color' => $v[2], 'registry' => in_array($pos, ['s', 'd']) ? 'Sample Registry' : null, 'registration_number' => in_array($pos, ['s', 'd']) ? '0000000' : null]));

        return [
            'horse' => $horse, 'preview' => false, 'audience' => 'report', 'link' => $link, 'sample' => true,
            'identifiers' => collect([
                (new HorseIdentifier)->forceFill(['kind' => 'registration', 'registry' => 'Sample Breed Registry', 'value' => '0000000', 'status' => 'document_supplied', 'in_conflict' => false]),
                (new HorseIdentifier)->forceFill(['kind' => 'microchip', 'value' => '000000000001234', 'status' => 'self_reported', 'in_conflict' => false]),
            ]),
            'events' => $events->sortByDesc(fn ($x) => $x->occurred_on)->values(),
            'documents' => collect(),
            'names' => collect([(new HorseName)->forceFill(['name' => 'JC Lark', 'kind' => 'alias'])]),
            'ownership' => collect([
                (new Stewardship)->forceFill(['role' => 'owner', 'status' => 'asserted', 'started_on' => '2016-04-01', 'ended_at' => Carbon::parse('2023-05-15'), 'created_at' => Carbon::parse('2016-04-01')]),
                (new Stewardship)->forceFill(['role' => 'owner', 'status' => 'verified', 'started_on' => '2023-05-15', 'created_at' => Carbon::parse('2023-05-15')]),
            ]),
            'photo' => null,
            'pedigree' => $ped,
            'offspring' => collect(),
            'media' => collect(),
        ];
    }
}
