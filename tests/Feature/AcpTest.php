<?php

namespace Tests\Feature;

use App\Harvest\AcpHarvester;
use App\Harvest\AcpParser;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseMention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** American Classic Pedigrees profiles (fictional horses in the site's Weebly markup). */
class AcpTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): string
    {
        return '<html><body><div id="wsite-content" class="wsite-elements"><div class="paragraph"><font><strong>Sample Runner (USA)</strong></font><br /><br />'
            .'<strong>February 25, 2008 &ndash; March 3, 2030</strong><br /><br /><strong><a href="/sample-sire.html">Sample Sire</a> (USA) x <a>Sample Dam</a> (USA), by <a>Sample Damsire</a> (IRE)</strong><br /><br />'
            .'<strong>Family 2-f</strong><br /></div><div class="paragraph">&#8203;Sample Runner was a wonderful horse with a lovely temperament.<br /><br /><strong>Race record</strong><br /><br />'
            .'20 starts, 6 wins, 5 seconds, 1 third, $3,090,101<br /><br />2011:<ul><li>Won Sample Stakes (USA-G1, 9.5FD, Pimlico)</li><li>2nd Sample Derby (USA-G2, 9FD, Monmouth)</li></ul>'
            .'<br /><strong>Honors</strong><br /><br />American champion 3-year-old male (2011)<br /><br /><strong>As an individual</strong><br /><br />A chestnut horse with a good shoulder and a calm mind.<br /><br />'
            .'<strong>As a stallion</strong><br /><br />Sample prose about the stud career.<br /><br /><strong>Connections</strong><br /><br />'
            .'Foaled in Kentucky, Sample Runner was bred and owned by Sample J. Breeder and Sample Partner; another partner later bought in. He was trained by Sample Trainer.<br /><br />'
            .'Last updated: January 26, 2026</div></div><div id="footer">© Sample Author</div></body></html>';
    }

    public function test_parser_reads_facts_only(): void
    {
        $p = AcpParser::page($this->profile());
        $this->assertSame(['Sample Runner', 'USA', 2008, '2008-02-25', 2030, '2030-03-03'], [$p['name'], $p['country'], $p['foal_year'], $p['birth_date'], $p['died_year'], $p['died_on']]);
        $this->assertSame(['Sample Sire', 'Sample Dam', 'Sample Damsire (IRE)'], [$p['pedigree']['s']['name'], $p['pedigree']['d']['name'], $p['pedigree']['ds']['name']]);
        $this->assertSame(['stallion', 'Chestnut', '2-f', 309010100], [$p['sex'], $p['color'], $p['family'], $p['earnings_cents']]);
        $this->assertSame(['1st', 'Sample Stakes', 'USA-G1, 9.5FD', 'Pimlico'], [$p['results'][0]['placing'], $p['results'][0]['race'], $p['results'][0]['class'], $p['results'][0]['track']]);
        $this->assertSame(['American champion 3-year-old male (2011)'], $p['honors']);
        $this->assertSame('Sample J. Breeder and Sample Partner', $p['breeder']);
        $this->assertStringNotContainsString('temperament', json_encode($p));
        $this->assertNull(AcpParser::page('<html><div id="wsite-content"><p>A blog post about breeding.</p><p>More text.</p><p>x</p><p>y</p></div></html>'));
    }

    public function test_harvest_loads_records_results_and_link_back(): void
    {
        Storage::fake('local');
        Http::fake([
            'www.americanclassicpedigrees.com/sitemap.xml' => Http::response('<loc>https://www.americanclassicpedigrees.com/sample-runner.html</loc><loc>https://www.americanclassicpedigrees.com/nicking.html</loc>'),
            'www.americanclassicpedigrees.com/sample-runner.html' => Http::response($this->profile()),
            'www.americanclassicpedigrees.com/nicking.html' => Http::response('<div id="wsite-content"><p>Nicking</p><p>a</p><p>b</p><p>c</p></div>'),
        ]);
        $h = new AcpHarvester(0.0);
        $this->assertSame(2, $h->collect()['fetched']);
        $out = $h->load();
        $this->assertSame([1, 1], [$out['created'], $out['not_profiles']]);

        $horse = Horse::where('registered_name', 'Sample Runner')->sole();
        $this->assertSame(['Thoroughbred', 'stallion', 'Chestnut', 'Sample J. Breeder and Sample Partner', 'day'], [$horse->breed, $horse->sex, $horse->color, $horse->breeder, $horse->birth_precision]);
        $this->assertSame(['death', 'earnings', 'race', 'race', 'title'], HorseEvent::where('horse_id', $horse->id)->pluck('type')->sort()->values()->all());
        $this->assertSame('20 starts, 6 wins, 5 seconds, 1 third, $3,090,101', HorseEvent::where('horse_id', $horse->id)->where('type', 'earnings')->value('result'));
        $this->assertSame('https://www.americanclassicpedigrees.com/sample-runner.html', HorseMention::where('horse_id', $horse->id)->value('url'));
        $this->assertSame('skipped', HarvestPage::where('url', 'like', '%nicking.html')->value('status'));

        // Rerun: nothing fetched or added.
        $events = HorseEvent::count();
        $this->assertSame(0, $h->collect()['fetched']);
        $this->assertSame(0, $h->load()['pages']);
        $this->assertSame($events, HorseEvent::count());

        $this->actingAs(User::factory()->create())->get(route('horse.public', $horse))->assertOk()
            ->assertSee('Sample Stakes')->assertSee('American champion 3-year-old male (2011)')->assertSee('American Classic Pedigrees')
            ->assertDontSee('lovely temperament');
    }
}
