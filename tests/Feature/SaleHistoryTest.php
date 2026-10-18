<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\MatchSaleLots;
use App\Harvest\KdlResultsParser;
use App\Harvest\LiveAgParser;
use App\Harvest\PlatinumParser;
use App\Harvest\PremierParser;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\PedigreeEntry;
use App\Models\SaleLot;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sale history from published auction results, and WSQHA's registration numbers (all names fictional). */
class SaleHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function horse(string $name, ?string $sire = null, ?string $dam = null, ?int $year = null): Horse
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => $name, 'birth_precision' => $year ? 'year' : 'unknown', 'birth_year' => $year]);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        foreach (['s' => $sire, 'd' => $dam] as $pos => $n) {
            if ($n) {
                PedigreeEntry::create(['horse_id' => $h->id, 'position' => $pos, 'name' => $n, 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);
            }
        }

        return $h;
    }

    private function lot(string $name, array $extra = []): SaleLot
    {
        $ds = DataSource::firstOrCreate(['key' => 'liveag'], ['name' => 'Live Ag', 'url' => 'https://bid.live-ag.com']);

        return SaleLot::record($ds->id, uniqid(), $extra + [
            'sale_name' => 'Sample Super Sale', 'sale_date' => '2025-10-18', 'lot' => '5', 'horse_name' => $name,
            'status' => 'sold', 'price_cents' => 470000, 'source_url' => 'https://bid.live-ag.com/events/listing/1/sample',
        ]);
    }

    public function test_live_ag_pages_are_read_without_people(): void
    {
        $props = ['listing' => ['status' => 'sold', 'is_sold' => true, 'sold_price' => '4700.00', 'highest_bid_amount' => '4700.00', 'currency' => 'USD', 'meta' => []],
            'dynamic_imports' => ['top' => ['Hip No:' => 5, 'Name:' => 'Sample Beach Boy', 'Reg#:' => 1234567, 'Year:' => '2/9/24', 'Sex:' => 'G', 'Breed:' => 'AQHA',
                'Sire:' => 'Sample Loper', 'Dam:' => 'Sample Beach', 'Dam Sire:' => 'Sample Only', 'Agent:' => 'Somebody', 'Seller:' => 'Consigned by Somebody']]];
        $id = LiveAgParser::identity($props);
        $this->assertSame(['hip' => '5', 'name' => 'Sample Beach Boy', 'reg' => '1234567', 'year' => '2/9/24', 'sex' => 'G', 'breed' => 'AQHA', 'sire' => 'Sample Loper', 'dam' => 'Sample Beach', 'dam_sire' => 'Sample Only'], $id);
        $this->assertSame([2024, '2024-02-09'], LiveAgParser::birth('2/9/24'));
        $this->assertSame(['status' => 'sold', 'price_cents' => 470000, 'note' => null, 'currency' => 'USD'], LiveAgParser::result($props));

        $passed = ['listing' => ['status' => 'passed', 'is_sold' => false, 'sold_price' => 0, 'highest_bid_amount' => '12000.00', 'meta' => []]];
        $this->assertSame('high_bid', LiveAgParser::result($passed)['status']);
        $this->assertSame(1200000, LiveAgParser::result($passed)['price_cents']);
        $buyback = ['listing' => ['status' => 'sold', 'is_sold' => true, 'sold_price' => '9000', 'highest_bid_amount' => '9000', 'meta' => ['is_buyback' => true]]];
        $this->assertSame('high_bid', LiveAgParser::result($buyback)['status']); // never a reported sale
        $hidden = ['exclude_sale_prices_from_results' => true] + $props;
        $this->assertNull(LiveAgParser::result($hidden)['price_cents']);

        $this->assertTrue(LiveAgParser::isHorseSale('2025 congress super sale'));
        $this->assertTrue(LiveAgParser::isHorseSale('23rd annual western wyoming select gelding sale'));
        $this->assertFalse(LiveAgParser::isHorseSale('express ranches fall bull sale'));
        $this->assertFalse(LiveAgParser::isHorseSale('thomas angus ranch annual production sale'));
    }

    public function test_kdl_results_keep_registration_and_foal_year_but_no_people(): void
    {
        $text = <<<'TXT'
                                                 Results by Class Report
                                               Show #917 Sample Emerald #1
                                                     Monroe WA
                                                      9/17/2026

Showbill: 339 Class: AQHA 412001 RK Youth Showmanship at Halter                                                     2 shown
                 Go: 1                                                                                             2 entered
Place        Back # Horse's Name                         Exhibitor's Name                       Owner(s)
1           420      Sample Big Finale                   Child Exhibitor                     Some Owner
                        AQHA - 5605881       2014      AQHA - 4650325                      E Lynden, WA
2           390      Sample Hot Option                   Another Child                       Other Owner
                        AQHA - X0712156      2015      AQHA - 4652268                      E Bellingham, WA
             1st Judge: 2nd Judge:
        1           420         420
        2           390         390
TXT;
        $rows = KdlResultsParser::parse($text);
        $this->assertCount(2, $rows);
        $this->assertSame(['Sample Big Finale', '5605881', 2014, '1', '412001', 2026], [$rows[0]['horse'], $rows[0]['registration_number'], $rows[0]['foal_year'], $rows[0]['placing'], $rows[0]['class_code'], $rows[0]['year']]);
        $this->assertSame('X0712156', $rows[1]['registration_number']);
        $this->assertStringNotContainsString('Child', json_encode($rows));
    }

    public function test_premier_catalog_and_platinum_cards(): void
    {
        $cells = implode('', array_map(fn ($n) => '<td>'.$n.'</td>', ['SAMPLE TRASHY', 'SAMPLE SIRE', 'SS', 'SSS', 'SSD', 'SD', 'SDS', 'SDD', 'SAMPLE DAM', 'SAMPLE DAMSIRE', 'DSS', 'DSD', 'DD', 'DDS', 'DDD']));
        $html = '<p>Lot #</p><p>12</p><tr><td>Registered Name:</td><td>SAMPLE TRASHY</td></tr><tr><td>Gender:</td><td>gelding</td></tr><tr><td>Year Born:</td><td>2015</td></tr>'
            .'<tr><td>Registration Status:</td><td>AQHA</td></tr><table class="pedigree_table">'.$cells.'</table>';
        $c = PremierParser::catalogHorse($html);
        $this->assertSame(['12', 'SAMPLE TRASHY', 2015, 'Sample Sire', 'Sample Dam', 'Sample Damsire'], [$c['lot'], $c['name'], $c['foal_year'], $c['pedigree']['s'], $c['pedigree']['d'], $c['pedigree']['ds']]);
        $this->assertSame('Premier Horse Sales 2025 SoCal', PremierParser::saleName('2025-socal-results'));

        $card = fn ($slug, $title, $price) => '<li class="product type-product"><a href="https://platinumequineauction.com/product/'.$slug.'/">x</a><h2 class="woocommerce-loop-product__title">'.$title.'</h2>'
            .'<table><tr><th>Contact</th><td><p>Seller Person<br />555-0100</p></td></tr><tr><th>Breed</th><td><p>AQHA Quarter Horse</p></td></tr><tr><th>Registered</th><td><p>Yes</p></td></tr><tr><th>Foal Date</th><td><p>2019</p></td></tr></table>'
            .'<p class="uwa_auction_end_time"><strong>Ending On:</strong> October 11, 2026 9:49 pm EST</p>'.$price.'</li>';
        $cards = PlatinumParser::cards($card('sample-ale', 'SAMPLE ALE (Ginger)', '<span class="woo-ua-winned-for winning_bid">Winning Bid</span>: <span class="amount"><bdi><span>$</span>8,750.00</bdi></span>')
            .$card('sample-pair', 'SAMPLE **2 In 1 Deal**', '<span class="woo-ua-winned-for reserve_not_met">Reserve price Not met!</span>'));
        $this->assertSame(['SAMPLE ALE', 'high_bid', 875000, 2019, true, '2026-10-11'], [$cards[0]['name'], $cards[0]['status'], $cards[0]['price_cents'], $cards[0]['foal_year'], $cards[0]['registered'], $cards[0]['ended']]);
        $this->assertNull($cards[1]['name']); // two horses in one listing
        $this->assertSame("DON'T SAMPLE ON ME", PlatinumParser::name('DON’T SAMPLE ON ME “Reyn”'));
        $this->assertSame('no_sale', $cards[1]['status']);
        $this->assertStringNotContainsString('Seller', json_encode($cards));
    }

    public function test_lots_attach_only_on_registration_or_parentage_and_show_labeled_prices(): void
    {
        $match = $this->horse('Sample Beach Boy', 'Sample Loper', 'Sample Beach');
        $this->lot('Sample Beach Boy', ['sire_name' => 'Sample Loper', 'dam_name' => 'Sample Beach', 'registration_number' => '1234567', 'registry' => 'AQHA', 'foal_year' => 2024]);
        $this->lot('Sample Beach Boy', ['status' => 'high_bid', 'price_cents' => 1200000, 'sale_date' => '2026-02-10', 'sire_name' => 'Sample Loper', 'dam_name' => 'Sample Beach']);

        $other = $this->horse('Sample Twin Name', 'Sample Different Sire', null);
        $conflict = $this->lot('Sample Twin Name', ['sire_name' => 'Sample Loper', 'dam_name' => 'Sample Beach']);
        $this->horse('Sample Bare Name');
        $queued = $this->lot('Sample Bare Name', ['foal_year' => 2018]);
        $byReg = $this->horse('Sample Renamed');
        \App\Models\HorseIdentifier::create(['horse_id' => $byReg->id, 'kind' => 'registration', 'registry' => 'AQHA', 'value' => '7654321', 'value_normalized' => '7654321', 'visibility' => 'public', 'added_by' => BuildRecordsFromResults::system()->id, 'source_type' => 'historical']);
        $this->lot('Sample New Name', ['registration_number' => '7654321', 'registry' => 'AQHA', 'status' => 'no_sale', 'price_cents' => null]);

        // Launch order: reported sales are published first; the high bid and the no-sale are matched but held.
        $this->assertSame(['attached' => 1, 'held' => 2, 'queued' => 1, 'conflict' => 1, 'unmatched' => 0], app(MatchSaleLots::class)());
        $this->assertSame(1, $match->events()->count());
        config(['horsefax.published_sale_kinds' => ['reported_sale', 'high_bid', 'no_sale']]);
        $out = app(MatchSaleLots::class)();
        $this->assertSame(['attached' => 2, 'held' => 0, 'queued' => 1, 'conflict' => 1, 'unmatched' => 0], $out);
        $this->assertSame('conflict', $conflict->fresh()->review_status);
        $this->assertStringContainsString('sire', $conflict->fresh()->review_note);
        $this->assertSame('queued', $queued->fresh()->review_status);
        $this->assertSame(0, $other->events()->count());

        $sale = $match->events()->where('result', 'reported_sale')->sole();
        $this->assertSame(['ownership', 'auction', 'public', 'public', 'historical', 'unverified', 470000], [$sale->category, $sale->type, $sale->visibility, $sale->amount_visibility, $sale->source_type, $sale->status, $sale->amount_cents]);
        $this->assertSame('Reported auction sale — $4,700', $sale->priceLine());
        $this->assertSame('Recorded high bid — $12,000 · Sale unconfirmed', $match->events()->where('result', 'high_bid')->sole()->priceLine());
        $this->assertSame('No sale', $byReg->events()->sole()->priceLine());
        $this->assertNull($byReg->events()->sole()->amount_cents);
        // The lot's registration number and foal year fill the unowned record's gaps.
        $this->assertSame('1234567', $match->identifiers()->where('kind', 'registration')->value('value'));
        $this->assertSame(2024, $match->fresh()->birth_year);

        $this->get(route('horse.public', $match))->assertOk()
            ->assertSee('Reported auction sale — $4,700')->assertSee('Recorded high bid — $12,000 · Sale unconfirmed')
            ->assertSee('Sample Super Sale, lot 5')->assertSee(HorseEvent::PRICE_NOTE);

        // Rerunning changes nothing.
        $this->assertSame(['attached' => 0, 'held' => 0, 'queued' => 1, 'conflict' => 1, 'unmatched' => 0], app(MatchSaleLots::class)());
        $this->assertSame(2, $match->events()->count());
    }

    public function test_staff_attach_a_queued_lot_but_never_one_the_record_contradicts(): void
    {
        $staff = User::factory()->create(['is_staff' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $h = $this->horse('Sample Bare Name');
        $lot = $this->lot('Sample Bare Name', ['foal_year' => 2018]);
        $wrong = $this->horse('Sample Wrong Year', null, null, 2010);
        $lot2 = $this->lot('Sample Wrong Year', ['foal_year' => 2018]);
        app(MatchSaleLots::class)();

        $this->actingAs($staff)->get(route('admin.sale-lots'))->assertOk()->assertSee('Sample Bare Name');
        $this->actingAs($staff)->post(route('admin.sale-lots.attach', $lot2), ['horsefax_id' => $wrong->horsefax_id, 'note' => 'checked'])->assertSessionHasErrors('horsefax_id');
        $this->assertNull($lot2->fresh()->horse_id);
        $this->actingAs($staff)->post(route('admin.sale-lots.attach', $lot), ['horsefax_id' => $h->horsefax_id, 'note' => 'Owner sent papers'])->assertSessionHasNoErrors();
        $this->assertSame(['staff', $h->id], [$lot->fresh()->match_tier, $lot->fresh()->horse_id]);
        $this->assertSame(1, $h->events()->count());
    }

    public function test_wsqha_registration_numbers_fill_records_and_split_shared_names(): void
    {
        $ds = DataSource::firstOrCreate(['key' => 'wsqha'], ['name' => 'WSQHA', 'url' => 'https://www.wsqharesults.com/']);
        $page = HarvestPage::create(['url' => 'https://www.wsqharesults.com/_files/ugd/sample.pdf', 'data_source_id' => $ds->id, 'kind' => 'class', 'status' => 'parsed']);
        $add = function (string $name, string $reg, int $foal, string $class) use ($ds, $page) {
            $rec = ShowRecord::firstOrCreate(['name_key' => ShowRecord::key($name)], ['display_name' => $name]);
            ShowAppearance::create(['show_record_id' => $rec->id, 'data_source_id' => $ds->id, 'harvest_page_id' => $page->id, 'horse_name' => $name,
                'event_name' => 'Sample Emerald', 'event_year' => 2026, 'class_name' => $class, 'placing' => '1', 'organization' => 'AQHA', 'registry_inferred' => 'AQHA',
                'registration_number' => $reg, 'foal_year' => $foal, 'source_url' => $page->url]);
            \App\Harvest\CongressHarvester::refresh($rec->id);
        };
        $add('Sample Finale', '5605881', 2014, 'Showmanship');
        $add('Sample Finale', '5605881', 2014, 'Horsemanship');
        $add('Sample Common', '1111111', 2012, 'Trail');
        $add('Sample Common', '2222222', 2019, 'Western Pleasure'); // two horses, one name

        $out = app(BuildRecordsFromResults::class)();
        $this->assertSame(1, $out['skipped_ambiguous']);
        $h = Horse::where('registered_name', 'Sample Finale')->sole();
        $this->assertSame(2, $h->events()->count());
        $this->assertSame(['AQHA', '5605881'], [$h->identifiers()->value('registry'), $h->identifiers()->value('value')]);
        $this->assertSame(2014, $h->fresh()->birth_year);
        $this->assertNull(Horse::where('registered_name', 'Sample Common')->first());
    }

    public function test_national_registry_list_horses_are_never_matched_by_name(): void
    {
        $ifce = $this->horse('Sample Jolie', 'Sample Pere', 'Sample Mere');
        $ifce->forceFill(['source' => 'ifce'])->save();
        $lot = $this->lot('Sample Jolie', ['sire_name' => 'Sample Pere', 'dam_name' => 'Sample Mere']);

        $this->assertSame(['attached' => 0, 'held' => 0, 'queued' => 0, 'conflict' => 0, 'unmatched' => 1], app(MatchSaleLots::class)());
        $this->assertNull($lot->fresh()->horse_id);
        $this->assertSame(0, $ifce->events()->count());
    }
}
