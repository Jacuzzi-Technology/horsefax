<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Harvest\CongressHarvester;
use App\Harvest\CongressParser;
use App\Models\HorseEvent;
use App\Models\ReviewItem;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The registered-name index fed by published show results (fictional pages in the Congress layout). */
class ShowResultsTest extends TestCase
{
    use RefreshDatabase;

    private function classPage(string $code, string $name, array $rows): string
    {
        $tr = collect($rows)->map(fn ($r) => "<tr><td>{$r[0]}</td><td>1234</td><td>{$r[2]}</td><td>{$r[1]}</td><td>Sample Owner LLC</td><td>Sample Rider - Town, OH</td></tr>")->join('');

        return "<html><head><title>The All American Quarter Horse Congress - Results for Class $code go 1</title></head><body>"
            ."<p>The All American Quarter Horse Congress</p><p>10/01/2024 - 10/20/2024</p><p>Class $code $name</p>"
            ."<table><tr><td>Place</td><td>BackNo</td><td>Earnings</td><td>Horse Name</td><td>Owner(s)</td><td>Exhibitor</td></tr>$tr</table></body></html>";
    }

    private function fakeArchive(): void
    {
        Http::fake([
            'www.quarterhorsecongress.com/historical-congress-results' => Http::response('<a href="https://www.quarterhorsecongress.com/2024-horse-show-results">2024</a>'),
            'www.quarterhorsecongress.com/2024-horse-show-results' => Http::response('<iframe src="https://quarterhorsecongress.net/results/24results.html"></iframe>'),
            'quarterhorsecongress.net/results/24results.html' => Http::response('<a href="24results/reining.html">Reining</a>'),
            'quarterhorsecongress.net/results/24results/reining.html' => Http::response('<a href="r1341go1.htm">1341</a><a href="r6600go1.htm">6600</a><a href="r9999go1.htm">9999</a>'),
            'quarterhorsecongress.net/results/24results/r1341go1.htm' => Http::response($this->classPage('1341', 'Junior Reining', [['1', 'Sample Spark Plug', '$400.00'], ['2', 'Sample Dun It', '$350.00']])),
            'quarterhorsecongress.net/results/24results/r6600go1.htm' => Http::response($this->classPage('6600', 'NRHA Open Reining', [['1', 'Sample Spark Plug', '1,500.00']])),
            'quarterhorsecongress.net/results/24results/r9999go1.htm' => Http::response('<p>Results are not yet available for this class.</p>'),
        ]);
    }

    public function test_parser_reads_horse_and_result_but_not_owner_or_rider(): void
    {
        $r = CongressParser::classPage($this->classPage('1341', 'Junior Reining', [['1', 'Sample Spark Plug', '$400.00']]));
        $this->assertSame(['1341', '1', 'Junior Reining'], [$r['class_code'], $r['round'], $r['class_name']]);
        $this->assertSame([['placing' => '1', 'horse' => 'Sample Spark Plug', 'earnings_cents' => 40000]], $r['rows']);
        $this->assertSame(['AQHA', 'AQHA'], CongressParser::sanction('Junior Reining'));
        $this->assertSame(['NRHA', null], CongressParser::sanction('NRHA Open Reining'));
        $this->assertSame(['Congress stakes or futurity', null], CongressParser::sanction('All Star Reining Stakes Open Futurity Level 3'));
    }

    public function test_harvest_builds_the_name_index_and_is_resumable(): void
    {
        Storage::fake('local');
        $this->fakeArchive();
        $h = new CongressHarvester(0);
        $years = $h->yearPages();
        $this->assertArrayHasKey(2024, $years);
        [$pages, $rows] = $h->year(2024, $years[2024]);
        $this->assertSame([2, 3], [$pages, $rows]);

        $spark = ShowRecord::where('name_key', 'SAMPLESPARKPLUG')->first();
        $this->assertSame([2, 190000, 'AQHA', 2024], [$spark->appearances, (int) $spark->earnings_cents, $spark->registry_inferred, $spark->first_year]);
        $this->assertNull(ShowRecord::where('name_key', 'SAMPLEDUNIT')->value('horse_id'));
        $this->assertSame('NRHA', ShowAppearance::where('class_code', '6600')->value('organization'));
        $this->assertDatabaseMissing('show_appearances', ['horse_name' => 'Sample Owner LLC']);

        // Rerun: nothing refetched or duplicated.
        Http::fake(fn () => throw new \RuntimeException('should not fetch again'));
        [$pages2, $rows2] = (new CongressHarvester(0))->year(2024, $years[2024]);
        $this->assertSame([0, 0], [$pages2, $rows2]);
        $this->assertSame(3, ShowAppearance::count());
    }

    public function test_results_show_in_search_and_on_their_own_page_without_owner_or_rider_names(): void
    {
        Storage::fake('local');
        $this->fakeArchive();
        $h = new CongressHarvester(0);
        $h->year(2024, $h->yearPages()[2024]);
        $record = ShowRecord::where('name_key', 'SAMPLESPARKPLUG')->first();

        $this->get('/search?name=spark+plug')->assertOk()->assertSee('Also found in published show results')->assertSee('Sample Spark Plug');
        $this->get('/show-records/'.$record->id)->assertOk()->assertSee('Junior Reining')->assertSee('$1,500')
            ->assertSee('Historical / third-party')->assertDontSee('Sample Owner LLC')->assertDontSee('Sample Rider');
    }

    public function test_owner_claims_results_and_staff_attach_them(): void
    {
        Storage::fake('local');
        $this->fakeArchive();
        $h = new CongressHarvester(0);
        $h->year(2024, $h->yearPages()[2024]);
        $record = ShowRecord::where('name_key', 'SAMPLESPARKPLUG')->first();

        $owner = User::factory()->create();
        $horse = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Spark Plug', 'birth_precision' => 'unknown']);
        $this->actingAs($owner)->get("/horses/{$horse->horsefax_id}")->assertOk()->assertSee('2 published show results');
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/results/{$record->id}")->assertRedirect();

        $item = ReviewItem::where('kind', 'results_match')->first();
        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->post("/admin/reviews/{$item->id}/approve", ['note' => 'Registration number matches'])->assertRedirect();

        $this->assertSame($horse->id, $record->fresh()->horse_id);
        $events = HorseEvent::where('horse_id', $horse->id)->where('category', 'competition')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['historical', 'unverified'], [$events[0]->source_type, $events[0]->status]);
        $this->actingAs($staff)->get('/admin/show-records')->assertOk()->assertSee('Sample Spark Plug');
    }

    public function test_the_1990s_layout_never_reads_a_rider_as_a_horse(): void
    {
        $html = '<HTML><TITLE>All American Quarter Horse Congress Results for Class1053 1st Go</TITLE><BODY><H2>Class: 1053 1996 Fillies 1st Go </H2> <br>Shown: 25<BR>'
            .'<TABLE><TR><TH>Place</TH><TH>Back No</TH><TH>Earnings</TH><TH>Horse Name</TH><TH>Owner(s)</TH></TR>'
            .'<TR><TH ColSpan="3"></TH><TH>Exhibitor</TH><TH>Owner Address</TH></TR>'
            .'<TR><TD> 1</TD><TD>4596</TD><TD> $200.00</TD><TD>Sample Filly</TD><TD>Sample Owners</TD></TR>'
            .'<TR><TD colspan="3"> </TD><TD>Sample Rider</TD><TD>Town, GA</TD></TR>'
            .'<TR><TD> 2</TD><TD>5412</TD><TD> $180.00</TD><TD>Sample Mare Two</TD><TD>Sample Owners</TD></TR>'
            .'<TR><TD colspan="3"> </TD><TD>Another Rider</TD><TD>Town, OH</TD></TR></TABLE>';
        $this->assertTrue(CongressParser::isClassPage($html));
        $r = CongressParser::classPage($html);
        $this->assertSame(['1053', '1', '1996 Fillies'], [$r['class_code'], $r['round'], $r['class_name']]);
        $this->assertSame(['Sample Filly', 'Sample Mare Two'], array_column($r['rows'], 'horse'));
    }
}
