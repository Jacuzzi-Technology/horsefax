<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Actions\Record\FindNameVariants;
use App\Actions\Record\SuggestFromSearch;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\NameReview;
use App\Models\PedigreeEntry;
use App\Models\ReviewSuggestion;
use App\Models\SaleLot;
use App\Models\User;
use App\Search\WebEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Search-backed suggestions (pilot, Oct 19 2026). All names fictional; no real request leaves the test. */
class SearchSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['horsefax.search.brave_key' => 'test-key']);
    }

    private function horse(string $name, string $source, array $extra = [], ?string $sire = null): Horse
    {
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = User::factory()->create()->id;
        $h->forceFill(['source' => $source, 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'] + $extra)->save();
        if ($sire) {
            PedigreeEntry::create(['horse_id' => $h->id, 'position' => 's', 'name' => $sire, 'visibility' => 'public', 'added_by' => $h->created_by]);
        }

        return $h;
    }

    /** Brave returns these URLs; the allowed page says $page. */
    private function fakeWeb(string $page, string $robots = "User-agent: *\nDisallow: /private/"): void
    {
        Http::fake([
            'api.search.brave.com/*' => Http::response(['web' => ['results' => [
                ['url' => 'https://www.facebook.com/sample.page/posts/1', 'title' => 'Sample', 'description' => 'snippet text'],
                ['url' => 'https://www.aqha.com/sample', 'title' => 'AQHA', 'description' => 'snippet text'],
                ['url' => 'https://www.example-unchecked.com/horse', 'title' => 'Other', 'description' => 'snippet text'],
                ['url' => 'https://www.billingslivestock.com/private/catalog.html', 'title' => 'Blocked by robots'],
                ['url' => 'https://www.billingslivestock.com/sample-catalog.html', 'title' => 'Catalog', 'description' => 'snippet text'],
            ]]]),
            'www.billingslivestock.com/robots.txt' => Http::response($robots),
            'www.billingslivestock.com/sample-catalog.html' => Http::response($page),
            '*' => Http::response('should not be fetched', 500),
        ]);
    }

    public function test_facts_are_read_from_the_entry_beside_the_name(): void
    {
        $text = WebEvidence::htmlText('<p>Hip 12 <b>SAMPLE DREAMY ASSETT</b> 2021 Sorrel Gelding AQHA #6123456<br>Sire: Sample Smart Sire<br>Dam: Sample Dam Lady</p>'
            .'<p>Hip 13 Sample Other Horse 2015 Bay Mare</p>');
        $f = WebEvidence::facts($text, 'Sample Dreamy Assett');
        $this->assertSame([['registration' => '6123456', 'foal_year' => 2021, 'sex' => 'gelding', 'sire' => 'Sample Smart Sire', 'dam' => 'Sample Dam Lady']], $f);
        $this->assertSame([], WebEvidence::facts('Sample Dreamy Assett is a great horse.', 'Sample Dreamy Assett'));
        $this->assertSame([[ 'foal_year' => 2015, 'sex' => 'mare']], WebEvidence::facts($text, 'Sample Other Horse'));
    }

    public function test_two_published_horses_of_one_name_suggest_different_horses_and_only_allowed_pages_are_read(): void
    {
        $a = $this->horse('Sample Dreamy Assett', 'results', ['birth_precision' => 'year', 'birth_year' => 2021, 'sex' => 'gelding']);
        $b = $this->horse('Sample Dreamy Assett', 'published', ['birth_precision' => 'year', 'birth_year' => 2020]);
        app(FindNameVariants::class)();
        $this->fakeWeb('<p>Hip 12 Sample Dreamy Assett 2021 Sorrel Gelding Sire: Sample Smart Sire</p><p>Hip 40 Sample Dreamy Assett 2020 Bay Mare Sire: Sample Other Sire</p>');

        $s = (new SuggestFromSearch(new WebEvidence))->forPair(NameReview::sole());
        $this->assertSame('different_horses', $s->suggestion);
        $this->assertSame([1, 1], [$s->searches, $s->pages_fetched]);
        $this->assertEqualsWithDelta(0.5, $s->cost_cents, 0.001);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'facebook.com') || str_contains($r->url(), 'aqha.com')
            || str_contains($r->url(), 'example-unchecked') || str_contains($r->url(), '/private/'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sample-catalog') && $r->header('User-Agent')[0] === \App\Harvest\CongressHarvester::UA);
        // Nothing from the search results is kept: no titles, snippets or other URLs; only the page read, as evidence.
        $stored = json_encode($s->evidence).$s->summary;
        $this->assertStringNotContainsString('snippet', $stored);
        $this->assertStringNotContainsString('facebook', $stored);
        $this->assertSame(['https://www.billingslivestock.com/sample-catalog.html'], collect($s->evidence)->pluck('url')->unique()->values()->all());
        $page = \App\Models\HarvestPage::where('url', 'https://www.billingslivestock.com/sample-catalog.html')->sole();
        $this->assertSame('Sample Dreamy Assett', trim(strip_tags(substr(gzdecode(Storage::disk('local')->get($page->path)), 10, 21))));

        // Staff accept: marked different horses, nothing merged.
        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->get(route('admin.names'))->assertOk()->assertSee('Search suggestion: Different horses');
        $this->actingAs($staff)->post(route('admin.suggestions.decide', $s), ['decision' => 'accept'])->assertRedirect();
        $this->assertSame(['different_horses', 'accepted'], [NameReview::sole()->status, $s->fresh()->status]);
        $this->assertNull($b->fresh()->merged_into_id);
    }

    public function test_a_page_that_fits_both_records_suggests_same_horse_and_accepting_merges(): void
    {
        $show = $this->horse('Sample Living Tobe Pretty', 'results', ['birth_precision' => 'year', 'birth_year' => 2019]);
        $sale = $this->horse('Sample Living To Be Pretty', 'published', [], 'Sample Pretty Sire');
        app(FindNameVariants::class)();
        $this->fakeWeb('<p>Sample Living Tobe Pretty, 2019 grey mare by Sample Pretty Sire out of Sample Pretty Dam.</p>');

        $s = (new SuggestFromSearch(new WebEvidence))->forPair(NameReview::sole());
        $this->assertSame(['same_horse', $show->id], [$s->suggestion, $s->horse_id]);

        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->assertNull($sale->fresh()->merged_into_id); // nothing happens until staff click
        $this->actingAs($staff)->post(route('admin.suggestions.decide', $s), ['decision' => 'accept'])->assertRedirect();
        $this->assertSame($show->id, $sale->fresh()->merged_into_id);
        $this->assertSame('merged', NameReview::sole()->status);
    }

    public function test_sale_lot_suggestion_attaches_on_accept_and_reject_leaves_it(): void
    {
        $ds = DataSource::firstOrCreate(['key' => 'platinum'], ['name' => 'Platinum', 'url' => 'https://platinumequineauction.com']);
        $rec = $this->horse('Sample Ranch Blue', 'results', [], 'Sample Blue Sire');
        $lot = SaleLot::record($ds->id, 'x1', ['sale_name' => 'Sample Sale', 'sale_date' => '2026-05-01', 'horse_name' => 'Sample Ranch Blue', 'foal_year' => 2018,
            'status' => 'sold', 'price_cents' => 1000000, 'source_url' => 'https://platinumequineauction.com/sample']);
        app(\App\Actions\Record\MatchSaleLots::class)();
        $this->assertSame('queued', $lot->fresh()->review_status);
        $this->fakeWeb('<p>Sample Ranch Blue (2018) Sire: Sample Blue Sire</p>');

        $s = (new SuggestFromSearch(new WebEvidence))->forLot($lot->fresh());
        $this->assertSame(['lot_belongs', $rec->id], [$s->suggestion, $s->horse_id]);

        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->get(route('admin.sale-lots'))->assertOk()->assertSee('Search suggestion: This sale belongs to');
        $this->actingAs($staff)->post(route('admin.suggestions.decide', $s), ['decision' => 'reject'])->assertRedirect();
        $this->assertSame(['rejected', null], [$s->fresh()->status, $lot->fresh()->horse_id]);
        $this->actingAs($staff)->post(route('admin.suggestions.decide', $s), ['decision' => 'accept'])->assertStatus(409);

        $s->fresh()->forceFill(['status' => 'pending'])->save(); // staff changed their mind
        $this->actingAs($staff)->post(route('admin.suggestions.decide', $s), ['decision' => 'accept'])->assertRedirect();
        $this->assertSame($rec->id, $lot->fresh()->horse_id);
        $this->assertSame(1, $rec->events()->where('category', 'ownership')->count());
    }

    public function test_robots_txt_naming_horsefaxbot_is_obeyed(): void
    {
        $this->horse('Sample Quiet Name', 'results', ['birth_precision' => 'year', 'birth_year' => 2019]);
        $this->horse('Sample QuietName', 'published', ['birth_precision' => 'year', 'birth_year' => 2018]);
        app(FindNameVariants::class)();
        $this->fakeWeb('<p>Sample Quiet Name 2019 gelding</p>', "User-agent: HorsefaxBot\nDisallow: /\n\nUser-agent: *\nAllow: /");

        $s = (new SuggestFromSearch(new WebEvidence))->forPair(NameReview::sole());
        $this->assertSame(['unsure', 0], [$s->suggestion, $s->pages_fetched]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'sample-catalog'));
    }
}
