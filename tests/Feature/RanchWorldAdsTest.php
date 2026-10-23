<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Harvest\RanchWorldAdsBlocked;
use App\Harvest\RanchWorldAdsHarvester;
use App\Harvest\RanchWorldAdsParser;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\PedigreeEntry;
use App\Models\ReviewSuggestion;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** RanchWorldAds ads: facts only, held asking prices, registration-number matches, name+sire+dam only proposed (all names fictional). */
class RanchWorldAdsTest extends TestCase
{
    use RefreshDatabase;

    private function adPage(int $id, string $title, array $o = []): string
    {
        $o += ['sold' => false, 'poster' => 'samplefarm', 'price' => '$8,500.00', 'desc' => 'Sample seller description that must never be kept. Call 208-555-1212.', 'pedigree' => true, 'kind' => 'Horses For Sale'];
        $cell = fn ($n) => '<td class="text"><strong><font>'.$n.'</font></strong></TD>';
        $ped = ['Sire:<br>Sample Dun It', 'Sample Grandsire', 'Sample GGS', 'Sample GGD', 'Sample Granddam', 'Unknown', '', 'Dam:<br>Sample Mare', 'Sample Damsire', 'A', 'B', 'C', 'D', 'E'];

        return '<html><body><a class="menu_bar_link" href="https://www.ranchworldads.com/index.php">Home</a> &middot;> <a class="menu_bar_link" href="https://www.ranchworldads.com/index.php?cat_id=1">HORSES FOR SALE</a>'
            .'<div id="adBox"><table><tr><td class="headtd">'.$o['kind'].': '.$title.($o['sold'] ? ' <b>SOLD</b>' : '').' </td><td class="headtd" align="right">#'.$id.'</td></tr>'
            .'<tr><td><b>Posted by:</b> '.$o['poster'].' <a class="small" href="#">[see other ads by this poster]</a><br><b>Price:</b> '.$o['price'].'<br><b>City:</b> Rexburg <a class="small" href="#">[see other ads in this city]</a><br><b>State:</b> Idaho <a class="small" href="#">[x]</a><br></td>'
            .'<td><b>Date Foaled:</b> 2019<br><b>Breed:</b> Quarter Horse<br><b>Gender:</b> Gelding<br><b>Height:</b> 15.2<br><b>Weight:</b> 1250#<br><b>Color:</b> Red Dun<br><b>Registered With:</b> AQHA<br><b>Disciplines:</b> Roping,Ranch Horse,<br><b>Phone#:</b> 208-555-1212<br></td></tr>'
            .'<tr><td><div id="imageViewer"><img src="x_small.jpg"></div></td></tr>'
            .'<tr><td colspan="2"><b>Description:</b><br>'.$o['desc'].'</td></tr>'
            .($o['pedigree'] ? '<table><TR><TD colspan="3"><strong>PEDIGREE</strong></TD></TR>'.implode('', array_map($cell, $ped)).'</TABLE>' : '')
            .'<tr><td>Posted 10-20-26 | 12 visitors have viewed this ad</td></tr></table></div></body></html>';
    }

    private function indexPage(array $rows, int $last = 1): string
    {
        $html = '<span class="ad_text">Horses For Sale<br><a href="https://www.ranchworldads.com/classified.php?listing=300" class="ad_link">Featured</a></span>';
        foreach ($rows as [$id, $title, $sold]) {
            $html .= '<td><div style="text-decoration:none;">Horses For Sale: <a href="https://www.ranchworldads.com/classified.php?listing='.$id.'" class="ad_link">'.$title.'</a>'.($sold ? ' <b>SOLD</b>' : '').'<br>snippet...<br>$8,500.00&nbsp;&nbsp;Video</div></td>';
        }

        return $html.'<a class="page-link" href="https://www.ranchworldads.com/index.php?cat_id=1&pg='.$last.'&sort=date">'.$last.'</a>';
    }

    public function test_ad_page_facts_only(): void
    {
        $a = RanchWorldAdsParser::ad($this->adPage(100, 'Sample Dun It Right ~ Broke Ranch Gelding', ['sold' => true, 'desc' => 'Papers: AQHA #5123456, phone 2085551212.']), 100);
        $this->assertSame(['Sample Dun It Right', true, 850000, 2019, 'gelding', '15.2', 1250, 'AQHA', 'Rexburg', 'Idaho', 'samplefarm', '2026-10-20'],
            [$a['name'], $a['sold'], $a['price_cents'], $a['foal_year'], $a['sex'], $a['height_hands'], $a['weight_lbs'], $a['registry'], $a['city'], $a['state'], $a['poster'], $a['posted']]);
        $this->assertSame(['s' => 'Sample Dun It', 'ss' => 'Sample Grandsire', 'sss' => 'Sample GGS', 'ssd' => 'Sample GGD', 'sd' => 'Sample Granddam',
            'd' => 'Sample Mare', 'ds' => 'Sample Damsire', 'dss' => 'A', 'dsd' => 'B', 'dd' => 'C', 'dds' => 'D', 'ddd' => 'E'], $a['pedigree']);
        $this->assertSame('5123456', $a['registration_number']);
        $this->assertSame(['Roping', 'Ranch Horse'], $a['disciplines']);
        $json = json_encode($a);
        $this->assertStringNotContainsString('Papers', $json);
        $this->assertStringNotContainsString('555', $json);
        $this->assertStringNotContainsString('.jpg', $json);

        $t = RanchWorldAdsParser::ad($this->adPage(101, 'SAMPLE BOON – 2023 AQHA Sorrel Gelding x Sample Sire x Sample GS out of Sample Dam x Sample DS!', ['pedigree' => false, 'price' => 'For Bid at Auction', 'kind' => 'Horses For Bid at Auction', 'poster' => 'info@horsebid.com']), 101);
        $this->assertSame(['SAMPLE BOON', true, true, null], [$t['name'], $t['auction'], $t['horsebid'], $t['price_cents']]);
        $this->assertSame(['s' => 'Sample Sire', 'ss' => 'Sample GS', 'd' => 'Sample Dam', 'ds' => 'Sample DS'], $t['pedigree']);
        $this->assertSame('title', $t['pedigree_from']);

        $phone = RanchWorldAdsParser::ad(str_replace('<b>Registered With:</b> AQHA', '<b>Registered With:</b> 417-555-0100', $this->adPage(102, 'Sample Phone Field')), 102);
        $this->assertNull($phone['registry_text']);
        $this->assertStringNotContainsString('555', json_encode($phone));
        $this->assertNull(RanchWorldAdsParser::name('2021 AQHA RANCH/ROPE GELDING'));
        $this->assertNull(RanchWorldAdsParser::name('Great Moving Big 3 Year Old Gelding'));
        $this->assertSame('Top Swing Sample', RanchWorldAdsParser::name('Top Swing Sample'));
        $this->assertTrue(RanchWorldAdsParser::gone("\t<META HTTP-EQUIV=\"refresh\" CONTENT=0;URL=\"https://www.ranchworldads.com/index.php\">"));
        $cattle = RanchWorldAdsParser::ad('<div id="adBox"><a class="menu_bar_link" href="https://www.ranchworldads.com/index.php?cat_id=4">CATTLE for SALE</a>', 5);
        $this->assertFalse($cattle['horse']);

        $list = RanchWorldAdsParser::listPage($this->indexPage([[100, 'A', true], [101, 'B', false]], 912));
        $this->assertSame([912, [100, 101, 300], [true, false, false], [false, false, true]], [$list['last_page'], array_column($list['rows'], 'id'), array_column($list['rows'], 'sold'), array_column($list['rows'], 'featured')]);
    }

    public function test_run_loads_lots_matches_on_registration_proposes_on_name_and_creates_records_for_registered_names(): void
    {
        $system = BuildRecordsFromResults::system();
        $make = function (string $name) use ($system) {
            $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
            $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
            $h->created_by = $system->id;
            $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();

            return $h;
        };
        $byReg = $make('Sample Dun It Right');
        HorseIdentifier::create(['horse_id' => $byReg->id, 'kind' => 'registration', 'registry' => 'AQHA', 'value' => '5123456', 'value_normalized' => '5123456', 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);
        $byPed = $make('Sample Pep Cat');
        PedigreeEntry::create(['horse_id' => $byPed->id, 'position' => 's', 'name' => 'Sample Dun It', 'visibility' => 'public', 'added_by' => $system->id]);
        PedigreeEntry::create(['horse_id' => $byPed->id, 'position' => 'd', 'name' => 'Sample Mare', 'visibility' => 'public', 'added_by' => $system->id]);

        $pages = [
            '1' => $this->indexPage([[100, 'Sample Dun It Right ~ Ranch Gelding', true], [200, 'Sample Pep Cat', false], [250, 'Gentle Kids Horse', false], [260, 'x', false], [265, 'x', false], [266, 'x', false], [280, 'x', false]]),
            '100' => $this->adPage(100, 'Sample Dun It Right ~ Ranch Gelding', ['sold' => true, 'desc' => 'AQHA 5123456']),
            '200' => $this->adPage(200, 'Sample Pep Cat', ['poster' => 'info@horsebid.com']),
            '250' => $this->adPage(250, 'Gentle Kids Horse', ['pedigree' => false]),
            '260' => $this->adPage(260, 'Sample Smart Lena ~ Ranch Gelding', ['pedigree' => false]), // registered (AQHA), no parents
            '265' => $this->adPage(265, 'Sample Pedigreed Star'),
            '266' => $this->adPage(266, 'Sample Pedigreed Star'), // the same horse advertised again
            '280' => $this->adPage(280, 'Rojo ~ Ranch Gelding'), // a barn name
            '300' => '<META HTTP-EQUIV="refresh" CONTENT=0;URL="https://www.ranchworldads.com/index.php">',
        ];
        Http::fake(function ($request) use (&$pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response($pages[$q['listing'] ?? $q['pg'] ?? ''] ?? $this->indexPage([]));
        });
        $dir = sys_get_temp_dir().'/rwa-test-'.uniqid();
        $harvester = new RanchWorldAdsHarvester(0, null, $dir);
        $out = $harvester->run();
        $this->assertSame([7, 1, 5, 1, 1], [$out['live'], $out['sold'], $out['with_pedigree'], $out['with_reg_number'], $out['horsebid']]);
        $this->assertStringNotContainsString('never be kept', file_get_contents($dir.'/ads.jsonl'));
        $this->assertFileExists($dir.'/ranchworldads-ads.csv');
        $this->assertFileExists($dir.'/html/0/100.html.gz');

        $loaded = $harvester->load();
        $this->assertSame([7, 1, 1, 2, 1, 2], [$loaded['loaded'], $loaded['matched_registration'], $loaded['proposed_match'], $loaded['created_records'], $loaded['linked_same_horse'], $loaded['unmatched']]);
        // Registered names no record has: new unverified records, the ad tied to them and held. Barn names never.
        $created = Horse::where('registered_name', 'Sample Pedigreed Star')->first();
        $this->assertSame(['unverified', 'published'], [$created->identity_status, $created->source]);
        $this->assertSame([$created->id, $created->id], SaleLot::whereIn('external_id', ['265', '266'])->orderBy('external_id')->pluck('horse_id')->all());
        $this->assertSame(['created', 'held'], [SaleLot::where('external_id', '265')->value('match_tier'), SaleLot::where('external_id', '265')->value('review_status')]);
        $this->assertSame(1, Horse::where('registered_name', 'Sample Smart Lena')->count());
        $this->assertSame(0, Horse::where('registered_name', 'Rojo')->count());
        $lot = SaleLot::where('external_id', '100')->first();
        $this->assertSame([$byReg->id, 'registration', 'held', 'asking'], [$lot->horse_id, $lot->match_tier, $lot->review_status, $lot->status]);
        $this->assertFalse($lot->publishable());
        $this->assertStringContainsString('marked SOLD', $lot->note);
        $proposed = SaleLot::where('external_id', '200')->first();
        $this->assertSame([null, 'queued'], [$proposed->horse_id, $proposed->review_status]); // never attached on a name
        $this->assertStringContainsString('HorseBid lot', $proposed->note);
        $this->assertSame($byPed->id, ReviewSuggestion::where('subject_id', $proposed->id)->value('horse_id'));
        $this->assertSame('Unnamed', SaleLot::where('external_id', '250')->value('horse_name'));
        $this->assertStringNotContainsString('samplefarm', (string) SaleLot::all()->toJson());

        // Next run: ad 250 dropped out of the index and is gone from the site. Only it and the listed-but-gone featured
        // ad 300 are requested again; the ads already on file are not.
        $pages['1'] = $this->indexPage([[100, 'Sample Dun It Right ~ Ranch Gelding', true], [200, 'Sample Pep Cat', false], [260, 'x', false], [265, 'x', false], [266, 'x', false], [280, 'x', false]]);
        $pages['250'] = '<META HTTP-EQUIV="refresh" CONTENT=0;URL="https://www.ranchworldads.com/index.php">';
        touch($dir.'/html/index-1.html.gz', time() - 13 * 3600); // a day later: the cached index page is too old to reuse
        $again = new RanchWorldAdsHarvester(0, null, $dir);
        $second = $again->run();
        $this->assertSame([1, 2], [$second['gone'], $second['fetched']]);
        $this->assertSame(0, $again->load()['created_records']); // a rerun creates nothing twice
        $this->assertStringContainsString('no longer on RanchWorldAds', SaleLot::where('external_id', '250')->value('note'));
    }

    public function test_a_block_stops_the_run(): void
    {
        Http::fake(fn () => Http::response('Forbidden', 403));
        $this->expectException(RanchWorldAdsBlocked::class);
        (new RanchWorldAdsHarvester(0, null, sys_get_temp_dir().'/rwa-block-'.uniqid()))->run();
    }
}
