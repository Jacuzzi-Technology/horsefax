<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Harvest\HarvestBlocked;
use App\Harvest\HeritageHarvester;
use App\Harvest\HeritageParser;
use App\Harvest\RegistrationOnlyMatch;
use App\Harvest\RobinGlennHarvester;
use App\Harvest\RobinGlennParser;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Heritage Livestock + Robin Glenn QData catalogs: facts only, registration-number matches only (all names fictional). */
class SaleCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private function rgRow(string $hip, string $name, string $sire, string $results = '', string $price = '', string $buyer = ''): string
    {
        $td = fn ($v, $hidden = false) => '<td'.($hidden ? ' style="display:none"' : '').'><span>'.$v.'</span></td>';

        return '<tr class="odd">'.$td($hip)
            .'<td><div id="showLink"><a href="https://catalogs.robinglenn.com/account/RGPCatBTNew.aspx?hid=900'.$hip.'&wv=1&catID=5">'.$name.'</a></div><div id="showName" style="display:none"><a href="">'.$name.'</a></div></td>'
            .$td('3/14/2019').$td('Bay').$td('G').$td($sire).$td('Sample Dam').$td('Sample Damsire').$td('Sample Consignor')
            .$td('', true).$td('Seller notes that must never be kept.', true).'<td></td><td><a href="">Click For Video</a></td>'
            .$td('Main Session', true).$td($results, true).$td($price, true).$td($buyer, true).'</tr>';
    }

    private function rgPage(string $name, string $rows): string
    {
        return '<html><body><table id="headerStuff"><tr><td><br />'.$name.'<br />LOCATION: Sample Arena<br />SALE DATE: 4/12/2025 through 4/13/2025<br />CONTACT: <a href="mailto:x@example.com">Sample Manager</a><br /><a href="examplesales.com" target="_blank">examplesales.com</a><br /></td><td></td></tr></table>'
            .'<table><tr><th>Hip</th></tr>'.$rows.'</table></body></html>';
    }

    public function test_robin_glenn_parser_reads_header_and_rows_without_notes(): void
    {
        $p = RobinGlennParser::parse($this->rgPage('Sample Spring Sale', $this->rgRow('12', 'Sample Dun It', 'Sample Sire', 'Purchased', '$8,500.00', 'Sample Buyer')));
        $this->assertSame(['Sample Spring Sale', 'Sample Arena', '2025-04-12', '2025-04-13', 'Sample Manager', 'examplesales.com'], array_values($p['sale']));
        $r = $p['rows'][0];
        $this->assertSame(['12', 'Sample Dun It', '90012', 2019, '2019-03-14', 'gelding', 'Sample Sire', 'Sample Damsire', 850000, 'Purchased', true, true],
            [$r['hip'], $r['name'], $r['rg_horse_id'], $r['foal_year'], $r['birth_date'], $r['sex'], $r['sire'], $r['dam_sire'], $r['price_cents'], $r['results'], $r['has_buyer'], $r['has_consignor']]);
        foreach (['never be kept', 'Sample Buyer', 'Sample Consignor'] as $gone) {
            $this->assertStringNotContainsString($gone, json_encode($p));
        }
        $this->assertSame('sold', RobinGlennHarvester::status($r, '2025-04-12'));
        $this->assertSame('high_bid', RobinGlennHarvester::status(['results' => 'Repurchased', 'price_cents' => 50000, 'name' => 'X'], '2025-04-12'));
        $this->assertSame(250000, RobinGlennHarvester::price(['results' => 'Repurchased 2500', 'price_cents' => null]));
        $this->assertSame('withdrawn', RobinGlennHarvester::status(['results' => 'Out', 'price_cents' => null, 'name' => 'X'], '2025-04-12'));
        $this->assertSame('offered', RobinGlennHarvester::status(['results' => '', 'price_cents' => null, 'name' => 'X'], '2025-04-12'));
        $this->assertSame('Purchased 5500', RobinGlennParser::parse($this->rgPage('Sample', $this->rgRow('2', 'Sample Two', 'Sample Sire', 'Purchased 5500; buyer Sample Person')))['rows'][0]['results']);
        $this->assertSame('private_order', RobinGlennHarvester::kind('PO-Sample Ranch-10292025'));
        $this->assertSame('test', RobinGlennHarvester::kind('TEST PARG CL SALE RG'));
    }

    public function test_robin_glenn_crawl_stops_past_the_last_catalog_and_loads_without_names(): void
    {
        $pages = [
            1 => $this->rgPage('Sample Spring Sale', $this->rgRow('1', 'Sample Dun It', 'Sample Sire', 'Purchased', '$8,500.00', 'Sample Buyer')),
            2 => $this->rgPage('PO-Sample Ranch-10292025', $this->rgRow('', 'Private Horse', 'Sample Sire')),
            3 => $this->rgPage('Sample Fall Sale', ''),
        ];
        Http::fake(fn ($request) => Http::response($pages[(int) preg_replace('/.*catID=/', '', $request->url())] ?? '<html><table id="headerStuff"><tr><td><br /><br />LOCATION: <br /></td></tr></table></html>'));
        $dir = sys_get_temp_dir().'/rg-test-'.uniqid();
        $h = new RobinGlennHarvester(0, null, $dir);
        $out = $h->crawl(); // to the end: stops after PROBE_GAP missing numbers past catID 3
        $this->assertSame([2, 1, 3 + RobinGlennHarvester::PROBE_GAP], [$out['catalogs'], $out['empty'], $out['requests']]);
        $this->assertSame(3, $h->highest());
        $tot = $h->export();
        $this->assertSame([1, 1], [$tot['sale_catalogs_with_lots'], $tot['private_orders']]);
        $this->assertStringNotContainsString('Sample Buyer', file_get_contents($dir.'/robinglenn-lots.csv')); // never stored, not even in the files

        $loaded = $h->load();
        $this->assertSame([1, 1], [$loaded['loaded'], $loaded['skipped_private_orders']]);
        $lot = SaleLot::where('external_id', '1:1')->first();
        $this->assertSame(['sold', 850000, 'Sample Spring Sale'], [$lot->status, $lot->price_cents, $lot->sale_name]);
        // A registered name (sire + dam given, two words or more) with no record of that name: a new unverified record.
        $this->assertSame([1, 'created'], [$loaded['created'], $lot->match_tier]);
        $this->assertSame(['Sample Dun It', 'unverified'], [$lot->horse->registered_name, $lot->horse->identity_status]);
        $this->assertStringNotContainsString('Sample Buyer', SaleLot::all()->toJson());
        $this->assertStringNotContainsString('Sample Consignor', SaleLot::all()->toJson());
    }

    public function test_robin_glenn_stops_on_a_block(): void
    {
        Http::fake(fn () => Http::response('Too many requests', 429));
        $this->expectException(HarvestBlocked::class);
        (new RobinGlennHarvester(0, null, sys_get_temp_dir().'/rg-block-'.uniqid()))->crawl(1, 3);
    }

    public function test_heritage_results_page_and_registry_suffixes(): void
    {
        $html = '<div><p>Heritage Livestock\'s Spring Catalog Sale</p><p>April 13</p><p>, 2025 | Sample Livestock Auction | Parsons, KS</p>'
            .'<p>TOP SELLER:</p><p>Lot #45</p><p>Sample Driftwood</p><p>$17,000</p><p>Consigned by</p><p>Sample Consignor</p>'
            .'<p>Lot #74 Finished Rope Gelding</p><p>$15,500</p><p>Top 5 Average: $15,230</p>'
            .'<p>Lot #24 Sample Bell 208 Consigned by Sample Person</p><p>Sold for $12,250</p></div>';
        $sales = HeritageParser::results($html);
        $this->assertCount(1, $sales);
        $this->assertSame(['2025-04-13', 'Sample Livestock Auction', 'Parsons, KS'], [$sales[0]['date'], $sales[0]['venue'], $sales[0]['city']]);
        $lots = collect($sales[0]['lots'])->keyBy('hip');
        $this->assertSame(['Sample Driftwood', 1700000], [$lots['45']['name'], $lots['45']['price_cents']]);
        $this->assertSame(['Sample Bell 208', 1225000], [$lots['24']['name'], $lots['24']['price_cents']]);
        $this->assertNull(HeritageParser::sameHorse('Finished Rope Gelding', 'Sample Driftwood')); // a description, not a name
        $this->assertFalse(HeritageParser::sameHorse('Sample Other', 'Sample Driftwood'));
        $this->assertSame(['Sample Spot', 'ApHC'], HeritageHarvester::registryOf('Sample Spot (ApHC)', true));
        $this->assertSame(['Sample Paint', 'APHA'], HeritageHarvester::registryOf('Sample Paint (P)', true));
        $this->assertSame(['Sample Dun', 'AQHA'], HeritageHarvester::registryOf('Sample Dun', true));
        $this->assertNull(HeritageHarvester::cleanName('has a cut on her back right Consigned foot that'));
        $this->assertSame('2023-06-17', HeritageParser::coverDate('Preview: June 16, 2023 at 10:00am | Sale: June 17, 2023 at 12:30pm'));
    }

    public function test_registration_only_match(): void
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Dun It', 'birth_precision' => 'year', 'birth_year' => 2019]);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        HorseIdentifier::create(['horse_id' => $h->id, 'kind' => 'registration', 'registry' => 'AQHA', 'value' => '5551234', 'value_normalized' => '5551234', 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);
        $source = (new HeritageHarvester(0, null, sys_get_temp_dir().'/hl-'.uniqid()))->source();
        $lot = fn ($id, $values) => SaleLot::record($source->id, $id, $values + ['sale_name' => 'Sample Sale', 'sale_date' => '2025-04-13', 'status' => 'offered', 'source_url' => 'https://example.com/x.pdf']);

        $this->assertSame('matched', RegistrationOnlyMatch::apply($lot('a', ['horse_name' => 'Sample Dun It', 'registration_number' => '5551234', 'registry' => 'AQHA', 'foal_year' => 2019])));
        $this->assertSame('conflict', RegistrationOnlyMatch::apply($lot('b', ['horse_name' => 'Sample Dun It', 'registration_number' => '5551234', 'registry' => 'AQHA', 'foal_year' => 2015])));
        $this->assertSame('unmatched', RegistrationOnlyMatch::apply($lot('c', ['horse_name' => 'Sample Dun It', 'registration_number' => '5559999', 'registry' => 'AQHA'])));
        $this->assertSame('no_number', RegistrationOnlyMatch::apply($lot('d', ['horse_name' => 'Sample Dun It', 'foal_year' => 2019]))); // same name: still not matched
        $this->assertNull(SaleLot::where('external_id', 'd')->value('horse_id'));
        $this->assertSame($h->id, SaleLot::where('external_id', 'a')->value('horse_id'));
    }

    private function horse(string $name, ?string $reg = null, ?string $sire = null, ?string $dam = null): Horse
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        if ($reg) {
            HorseIdentifier::create(['horse_id' => $h->id, 'kind' => 'registration', 'registry' => 'AQHA', 'value' => $reg, 'value_normalized' => $reg, 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);
        }
        if ($sire) {
            app(\App\Actions\Record\AddPublishedIdentity::class)($h, $system, ['pedigree' => ['s' => $sire, 'd' => $dam]], 'test');
        }

        return $h;
    }

    public function test_registered_names_get_unverified_records_after_registration_numbers(): void
    {
        $source = (new HeritageHarvester(0, null, sys_get_temp_dir().'/hl-'.uniqid()))->source();
        $lot = fn ($id, $values) => SaleLot::record($source->id, $id, $values + ['sale_name' => 'Sample Sale', 'sale_date' => '2025-04-13', 'status' => 'offered', 'source_url' => 'https://example.com/x.pdf']);
        $byNumber = $this->horse('Sample Numbered One', '5551234');
        $byParents = $this->horse('Sample Proposed One', null, 'Sample Sire', 'Sample Dam');

        // 1. The number wins, even under another name.
        $this->assertSame('matched', RegistrationOnlyMatch::place($lot('a', ['horse_name' => 'Sample Renamed', 'registration_number' => '5551234', 'registry' => 'AQHA']), 'Sample'));
        $this->assertSame($byNumber->id, SaleLot::where('external_id', 'a')->value('horse_id'));
        // 2. An AQHA number no record holds: a new unverified record, carrying the number.
        $this->assertSame('created', RegistrationOnlyMatch::place($lot('b', ['horse_name' => 'Sample New Horse', 'registration_number' => '5550001', 'registry' => 'AQHA', 'registered' => true]), 'Sample'));
        $created = SaleLot::where('external_id', 'b')->first()->horse;
        $this->assertSame(['Sample New Horse', 'unverified'], [$created->registered_name, $created->identity_status]);
        $this->assertTrue(HorseIdentifier::where('horse_id', $created->id)->where('value_normalized', '5550001')->exists());
        // 3. The same horse in a later sale of this source: linked to that record, not a duplicate.
        $this->assertSame('matched', RegistrationOnlyMatch::place($lot('c', ['horse_name' => 'Sample New Horse', 'registration_number' => '5550001', 'registry' => 'AQHA', 'registered' => true]), 'Sample'));
        // 4. Name + parentage agree with an existing record: proposed to staff, never attached.
        $this->assertSame('proposed', RegistrationOnlyMatch::place($lot('d', ['horse_name' => 'Sample Proposed One', 'sire_name' => 'Sample Sire', 'dam_name' => 'Sample Dam']), 'Sample'));
        $this->assertNull(SaleLot::where('external_id', 'd')->value('horse_id'));
        $this->assertSame('queued', SaleLot::where('external_id', 'd')->value('review_status'));
        // 5. Grade horses, one-word names and bare names don't qualify: they wait unmatched.
        $this->assertSame('unmatched', RegistrationOnlyMatch::place($lot('e', ['horse_name' => 'Sample Grade', 'registered' => false]), 'Sample'));
        $this->assertSame('unmatched', RegistrationOnlyMatch::place($lot('f', ['horse_name' => 'Trigger', 'sire_name' => 'Sample Sire', 'dam_name' => 'Sample Dam']), 'Sample'));
        $this->assertSame(RegistrationOnlyMatch::UNMATCHED, SaleLot::where('external_id', 'f')->value('review_status'));
        // 6. A rerun leaves staff-queued lots alone and makes nothing twice.
        $before = Horse::count();
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $id) {
            RegistrationOnlyMatch::place(SaleLot::where('external_id', $id)->first(), 'Sample');
        }
        $this->assertSame($before, Horse::count());
    }
}
