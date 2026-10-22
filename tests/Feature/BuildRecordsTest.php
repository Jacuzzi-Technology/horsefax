<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Horse records built from the registered-name index (fictional names). */
class BuildRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function published(string $source, string $name, int $year, array $extra = []): ShowAppearance
    {
        $ds = DataSource::firstOrCreate(['key' => $source], ['name' => $source, 'url' => 'https://example.test/'.$source]);
        $page = HarvestPage::firstOrCreate(['url' => 'https://example.test/'.$source.'/'.$year], ['data_source_id' => $ds->id, 'kind' => 'class', 'status' => 'parsed']);
        $rec = ShowRecord::firstOrCreate(['name_key' => ShowRecord::key($name)], ['display_name' => $name]);
        $a = ShowAppearance::create($extra + [
            'show_record_id' => $rec->id, 'data_source_id' => $ds->id, 'harvest_page_id' => $page->id, 'horse_name' => $name,
            'event_name' => 'Sample Show '.$year.' '.uniqid(), 'event_year' => $year, 'class_name' => 'Sample Class', 'placing' => '1',
            'organization' => 'AQHA', 'registry_inferred' => 'AQHA', 'source_url' => $page->url,
        ]);
        \App\Harvest\CongressHarvester::refresh($rec->id);

        return $a;
    }

    public function test_names_become_unverified_records_with_results_and_published_parentage(): void
    {
        $this->published('nrcha', 'Sample Leavin', 2016, ['organization' => 'NRCHA', 'registry_inferred' => null, 'sire_name' => 'Sample Lean', 'dam_name' => 'Sample Dunny Dee', 'dam_sire_name' => 'Sample Impressive']);
        $this->published('nqha', 'Sample Leavin', 2018);
        $this->published('equestrianlive', 'Sample Leavin', 2026, ['organization' => 'Jumper', 'registry_inferred' => null]); // a jumper with the same show name
        $this->published('equestrianlive', 'Sample Goldfish', 2026, ['organization' => 'Hunter/equitation', 'registry_inferred' => null]);
        $this->published('nqha', 'Sample Old Name', 1990);
        $this->published('nqha', 'Sample Old Name', 2024);
        $owner = User::factory()->create();
        app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned', 'birth_precision' => 'unknown']);
        $this->published('nqha', 'Sample Owned', 2024);
        // The sire already has its own results: its pedigree-made record picks them up.
        $this->published('nqha', 'Sample Lean', 2010);

        $out = app(BuildRecordsFromResults::class)();
        $this->assertSame(1, $out['skipped_span']);
        $this->assertSame(1, $out['skipped_ambiguous']);

        $h = Horse::where('registered_name', 'Sample Leavin')->first();
        $this->assertSame(['results', 'unverified', true, true], [$h->source, $h->identity_status, $h->listed, $h->public_summary]);
        $this->assertSame(0, $h->activeStewards()->count());
        $this->assertSame(2, $h->events()->where('category', 'competition')->where('source_type', 'historical')->count()); // not the jumper
        $this->assertSame(['d' => 'Sample Dunny Dee', 'ds' => 'Sample Impressive', 's' => 'Sample Lean'], $h->pedigree()->orderBy('position')->pluck('name', 'position')->all());
        $sire = Horse::where('registered_name', 'Sample Lean')->sole();
        $this->assertSame(1, $sire->events()->count());
        $this->assertTrue($sire->public_summary);
        $this->assertNotNull(Horse::where('registered_name', 'Sample Dunny Dee')->first());
        $this->assertNull(Horse::where('registered_name', 'Sample Goldfish')->first());
        $this->assertNull(ShowRecord::where('name_key', 'SAMPLEOWNED')->value('horse_id'));

        $this->actingAs($this->reader())->get(route('horse.public', $h))->assertOk()->assertSee('built this record from published show results')->assertSee('Sample Lean');
        $this->get(route('show-records.show', ShowRecord::where('name_key', 'SAMPLELEAVIN')->first()))->assertRedirect(route('horse.public', $h));

        // A later result for the same name is attached on the next run; nothing is duplicated.
        $this->published('nqha', 'Sample Leavin', 2019);
        $again = app(BuildRecordsFromResults::class)();
        $this->assertSame([0, 1], [$again['created'], $again['attached']]);
        $this->assertSame(3, $h->events()->count());
        $this->assertSame(1, Horse::where('registered_name', 'Sample Leavin')->count());
    }
}
