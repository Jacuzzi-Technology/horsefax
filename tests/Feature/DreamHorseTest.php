<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Harvest\DreamHorseHarvester;
use App\Harvest\DreamHorseParser;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** DreamHorse ads: facts only, held asking prices, registration-number matches only (all names fictional). */
class DreamHorseTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $label, string $data): string
    {
        return '<tr><td class="horseLabel" style="width:40%">'.$label.'</td><td class="horseData" style="width:60%"> '.$data.' </td></tr>';
    }

    private function adPage(int $id, string $reg = '7654321', string $title = 'SAMPLE DUN IT RIGHT (Dunny)'): string
    {
        return '<html><body><table><tr><td class="titlebar"> '.$title.' </td></tr></table>'
            .'<table>'.$this->row('Location', '<a href="https://www.dreamhorse.com/zip/26704/horses-for-sale-in-augusta-west-virginia.html">Augusta West Virginia 26704</a><br><small>USA</small><br><p class="x-small"><a href="#">Related Searches by State</a></p>')
            .$this->row(' Breed ', 'AQHA Quarter Horse&nbsp;<a href="https://www.aqha.com"><i></i></a><br><br><p class="x-small"><a href="#">Related Searches by Breed</a></p>')
            .$this->row('Date Foaled', 'JUN 2019 <span class="small">(7)</span>').$this->row('Gender', 'Gelding').$this->row('Height', '15.1 hh')
            .$this->row('Color', 'Red Dun').$this->row('Temperament', '3').$this->row('Registered', 'Yes').$this->row('Reg. Assn', 'AQHA')
            .$this->row('Reg. Number', $reg).$this->row('For Sale', 'Yes').$this->row('Asking Price', '$8,500&nbsp;<small><b>USD</b></small>').'</table>'
            .'<div>Sample seller description that must never be kept.</div>'
            .'<table><tr><td colspan="4"><h4>Pedigree for SAMPLE DUN IT RIGHT</h4></td></tr>'
            .'<tr><td rowspan="8" class="sire"><b>Sire:</b><br><a>Sample Dun It</a></td><td rowspan="4" class="sire">Sample Grandsire</td></tr>'
            .'<tr><td rowspan="8" class="dam"><b>Dam:</b><br><a>Sample Mare</a></td><td rowspan="4" class="sire">Sample Damsire</td></tr></table>'
            .'<a href="/ad/'.($id + 1).'.html">similar</a><span class="nameFont">Sample Seller</span>'
            .'<p class="x-small center grayFont">Photos Expire 19-Apr-2027 &bull; 179 Days Remaining</p><div>Photo Added/Renewed: 21-Oct-2026</div></body></html>';
    }

    public function test_ad_page_facts_only(): void
    {
        $a = DreamHorseParser::ad($this->adPage(100), 100);
        $this->assertSame('SAMPLE DUN IT RIGHT', $a['name']);
        $this->assertSame('Dunny', $a['barn_name']);
        $this->assertSame('AQHA Quarter Horse', $a['breed']);
        $this->assertSame(['s' => 'Sample Dun It', 'ss' => 'Sample Grandsire', 'd' => 'Sample Mare', 'ds' => 'Sample Damsire'], $a['pedigree']);
        $this->assertSame([2019, 6, 15.1, 850000, 'Augusta', 'West Virginia', '26704'], [$a['foal_year'], $a['foal_month'], $a['height_hands'], $a['price_cents'], $a['city'], $a['state'], $a['zip']]);
        $this->assertSame(['2026-10-21', '2027-04-19'], [$a['ad_renewed'], $a['ad_expires']]);
        $this->assertStringNotContainsString('description', json_encode($a));
        $this->assertArrayNotHasKey('temperament', $a);
        $this->assertTrue(DreamHorseParser::gone('<p>Ad Not Found.  No Longer available on DreamHorse.</p>'));
        $list = DreamHorseParser::listPage('<!-- total_rows: 81--><a href="/ad/5/x.html"></a><a href="/ad/5.html"></a><a href="/ad/6.html"></a>');
        $this->assertSame(['total' => 81, 'ids' => [5, 6]], $list);
    }

    public function test_run_loads_held_asking_lots_and_matches_on_registration_number_only(): void
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Dun It Right', 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        HorseIdentifier::create(['horse_id' => $h->id, 'kind' => 'registration', 'registry' => 'AQHA', 'value' => '7654321', 'value_normalized' => '7654321', 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);

        $pages = [
            '/sitemap.txt' => "https://www.dreamhorse.com/s/horses-for-sale-in-ohio.html\n",
            '/s/horses-for-sale-in-ohio.html' => '<!-- total_rows: 4--><a href="/ad/100.html"></a><a href="/ad/200.html"></a><a href="/ad/300.html"></a><a href="/ad/400.html"></a>',
            '/ad/100.html' => $this->adPage(100),
            '/ad/101.html' => '<p>Ad Not Found.</p>',
            '/ad/200.html' => $this->adPage(200, '1111111'), // same name, different number: a different horse
            '/ad/300.html' => $this->adPage(300, '', 'SAMPLE STARLIGHT DOC (Star)'), // a registered name no record has
            '/ad/400.html' => $this->adPage(400, '', 'Beginner Safe Gelding'), // an ad headline, not a name
        ];
        Http::fake(function ($request) use (&$pages) {
            return Http::response($pages[parse_url($request->url(), PHP_URL_PATH)] ?? '<!-- total_rows: 0-->');
        });
        $dir = sys_get_temp_dir().'/dh-test-'.uniqid();
        $harvester = new DreamHorseHarvester(0, null, $dir);
        $out = $harvester->run();
        $this->assertSame(4, $out['live']);
        $this->assertStringNotContainsString('never be kept', file_get_contents($dir.'/ads.jsonl'));
        $this->assertFileExists($dir.'/dreamhorse-ads.csv');

        $loaded = $harvester->load();
        $this->assertSame(1, $loaded['matched']);
        $lot = SaleLot::where('external_id', '100')->first();
        $this->assertSame([$h->id, 'registration', 'held', 'asking'], [$lot->horse_id, $lot->match_tier, $lot->review_status, $lot->status]);
        $this->assertFalse($lot->publishable());
        // Registered names get new unverified records (Richard, Oct 22 2026); the same-name horse with a different number is a different horse.
        $this->assertSame(2, $loaded['created_records']);
        $other = SaleLot::where('external_id', '200')->first();
        $this->assertNotNull($other->horse_id);
        $this->assertNotSame($h->id, $other->horse_id);
        $this->assertSame(['created', 'held'], [$other->match_tier, $other->review_status]);
        $star = Horse::find(SaleLot::where('external_id', '300')->value('horse_id'));
        $this->assertSame(['Sample Starlight Doc', 'unverified', 'published'], [$star->registered_name, $star->identity_status, $star->source]);
        $this->assertNull(SaleLot::where('external_id', '400')->value('horse_id'));
        $ads = fn () => Horse::where('source', '!=', 'pedigree')->count(); // sires and dams named in the charts get their own records
        $this->assertSame(3, $ads());
        $all = Horse::count();
        $this->assertSame(3, $harvester->load()['already_matched']); // a rerun makes nothing twice
        $this->assertSame($all, Horse::count());
        $this->assertStringNotContainsString('Sample Seller', (string) SaleLot::all()->toJson());

        // Next run: ad 200 has dropped out of the lists and is gone from the site.
        $pages['/s/horses-for-sale-in-ohio.html'] = '<!-- total_rows: 3--><a href="/ad/100.html"></a><a href="/ad/300.html"></a><a href="/ad/400.html"></a>';
        $pages['/ad/200.html'] = '<p>Ad Not Found.</p>';
        $again = new DreamHorseHarvester(0, null, $dir);
        $this->assertSame(1, $again->run()['gone']);
        $again->load();
        $this->assertStringContainsString('no longer on DreamHorse', SaleLot::where('external_id', '200')->value('note'));
    }
}
