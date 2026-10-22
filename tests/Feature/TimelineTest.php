<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Life Timeline: the record's own entries by year, nothing invented, nothing private (fictional horse). */
class TimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_timeline_groups_by_year_without_inventing_dates_or_showing_private_entries(): void
    {
        $sys = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Timeline', 'birth_precision' => 'year', 'birth_year' => 2015]);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $sys->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true])->save();
        $e = fn (array $a) => HorseEvent::create($a + ['horse_id' => $h->id, 'contributor_id' => $sys->id, 'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public']);
        foreach ([['2018', '1', 30000], ['2018', '3', 10000], ['2021', '2', 5000]] as [$y, $p, $c]) {
            $e(['category' => 'competition', 'type' => 'show', 'title' => 'Sample Open', 'class_name' => 'Sample Open', 'event_name' => 'Sample Futurity', 'placing' => $p,
                'earnings_cents' => $c, 'occurred_on' => $y.'-10-01', 'date_precision' => 'year', 'organization' => 'NRHA']);
        }
        $e(['category' => 'ownership', 'type' => 'auction', 'title' => 'Reported auction sale', 'result' => 'reported_sale', 'amount_cents' => 2500000, 'amount_visibility' => 'public',
            'sale_venue' => 'Sample Sale, lot 5', 'occurred_on' => '2022-03-12', 'date_precision' => 'day', 'details' => 'From the published sale results: https://example.test/lot/5']);
        $listing = $e(['category' => 'ownership', 'type' => 'sale', 'title' => 'Listed for sale by Horse of My Dreams', 'sale_venue' => 'Horse of My Dreams', 'occurred_on' => '2024-04-10', 'date_precision' => 'month']);
        Media::create(['horse_id' => $h->id, 'uploaded_by' => $sys->id, 'kind' => 'video_link', 'url' => 'https://youtu.be/abcdefghijk', 'rights_attested' => true, 'visibility' => 'public']);
        $e(['category' => 'health', 'type' => 'exam', 'title' => 'Sample private vet exam', 'occurred_on' => '2023-05-01', 'visibility' => 'private']);
        $e(['category' => 'training', 'type' => 'program', 'title' => 'Sample undated training note', 'occurred_on' => null]);

        $page = $this->actingAs($this->reader())->get(route('horse.public', [$h, 'view' => 'timeline']))->assertOk()
            ->assertSee('Life timeline')->assertSee('Foaled')
            ->assertSee('2 show results')->assertSee('$400 earnings')->assertSee('best placing 1')
            ->assertSee('First recorded show result')->assertSee('Most recent recorded show result')
            ->assertSee('Reported auction sale — $25,000')->assertSee('Mar 12, 2022')
            ->assertSee('1 video from the Horse of My Dreams listing')
            ->assertSee('Undated')->assertSee('Sample undated training note')
            ->assertDontSee('Sample private vet exam')
            ->assertDontSee('Oct 2018'); // year-only results never get a month
        $this->actingAs($this->reader())->get(route('horse.public', $h))->assertOk()->assertSee('Standard report')->assertDontSee('data-timeline', false);
    }
}
