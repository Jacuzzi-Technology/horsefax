<?php

namespace Tests\Feature;

use App\Harvest\AuctioneerSoftwareHarvester;
use App\Harvest\AuctioneerSoftwareParser;
use App\Harvest\HarvestBlocked;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AuctioneerSoftware horse auctions: facts only, a high bid is never a sale, people never stored (all names fictional). */
class AuctioneerSoftwareTest extends TestCase
{
    use RefreshDatabase;

    private function lot(int $id, string $number, string $title, array $fields, string $state, string $label, string $amount, int $bids, bool $soldTag = false, bool $notMet = false): string
    {
        $f = '';
        foreach ($fields as $k => $v) {
            $f .= '<div class="dynamic-field-display"><span class="field-label">'.$k.'</span><span class="field-separator">:</span><span class="field-value">'.$v.'</span></div>';
        }

        return '<div class="lot false " data-lotid="'.$id.'" data-lotnumber="'.$number.'"><div><div class="imgWrapper"><a class="imgContainer" href="/auctions/7/lot/'.$id.'-x"><img src="https://example.com/p.jpg"/></a>'
            .($soldTag ? '<div class="imageTag lotImageTag">SOLD!</div>' : '').'</div><div class="details "><div class="lot-title"><span class="lot-number">Lot '.$number.':</span><span class="title">'.$title.'</span></div>'
            .'<div class="display-dynamic-fields">'.$f.'</div><div class="time-remaining"><strong class="countdown '.$state.'">Completed</strong></div>'
            .'<div class="winning-bidder"><strong class="winning-bidder-label">'.$label.'<!-- -->:</strong><span class="winning-bidder-value">j****n</span></div>'
            .'<div class="winning-bid-amount">'.$amount.'</div><div class="bid-count"><div class="bid-count-value"><strong>Bids:</strong><span>'.$bids.'</span></div></div>'
            .'<div class="grouped-lots">'.($notMet ? '<div class="reserveMet"><span class="notMet">Reserve not met</span></div>' : '').'</div></div></div><a class="enterLot" href="/auctions/7/lot/'.$id.'-x">View Lot Details Now</a></div>';
    }

    private function page(): string
    {
        return '<html><head><title>Spring Sample Sale - Sample Horse Sales</title></head><body><div class="list">'
            .$this->lot(1, '00', 'PRACTICE BIDDING', [], 'completedNoBids', 'Current High Bidder', '$0.00', 0)
            .$this->lot(2, '1', 'SAMPLE SMART CHEX | 2018 AQHA Mare | Ranch Horse', ['Consigned By' => 'Sample Consignor', 'Phone' => '(555) 555-0100', 'Story' => 'Seller prose never kept'], 'completedNoReserve', 'Winning Bidder', '$9,500.00', 7, true)
            .$this->lot(3, '2', 'SAMPLE DUN PEPTO', ['Year' => '2020', 'Breed' => 'AQHA', 'Gender' => 'GELDING', 'Color' => 'Bay with star', 'Sire' => 'SAMPLE SIRE', 'Dam' => 'SAMPLE DAM', 'Trainer' => 'Sample Trainer', 'Email' => 'x@example.com'], 'completedReserveNotMet', 'Current High Bidder', '$6,000.00', 3, false, true)
            .$this->lot(4, '3', 'Buck - 8yr - 15hh - Gelding', ['Registered' => 'No'], 'completedNoReserve', 'Current High Bidder', '$2,250.00', 4)
            .$this->lot(5, '4', 'SAMPLE PRICE TAG | 2019 AQHA Gelding', [], 'completedNoBids', 'Current High Bidder', '$15,000.00', 0)
            .$this->lot(6, '5', 'Jenny - 6yr - Mule Mare', [], 'completedNoReserve', 'Winning Bidder', '$1,000.00', 2)
            .'</div></body></html>';
    }

    public function test_results_are_never_upgraded_and_people_are_dropped(): void
    {
        $lots = AuctioneerSoftwareParser::lots($this->page());
        $this->assertCount(6, $lots);
        $this->assertSame(['sold', 950000, 'Marked SOLD by the sale'], AuctioneerSoftwareParser::result($lots[1]));
        $this->assertSame(['high_bid', 600000, 'Reserve not met'], AuctioneerSoftwareParser::result($lots[2]));
        $this->assertSame('high_bid', AuctioneerSoftwareParser::result($lots[3])[0]); // a high bid, never upgraded to a sale
        $this->assertSame(['no_sale', null, 'No bids'], AuctioneerSoftwareParser::result($lots[4])); // a price with no bids is an asking price: not kept
        $this->assertStringNotContainsString('Sample Consignor', json_encode($lots));
        $this->assertStringNotContainsString('555-0100', json_encode($lots));
        $this->assertStringNotContainsString('prose', json_encode($lots));
        $this->assertStringNotContainsString('Sample Trainer', json_encode($lots));
        $this->assertStringNotContainsString('j****n', json_encode($lots));

        $h = AuctioneerSoftwareParser::horse($lots[1], 2026);
        $this->assertSame(['SAMPLE SMART CHEX', 2018, 'mare', 'AQHA', true, 'Quarter Horse'], [$h['name'], $h['foal_year'], $h['sex'], $h['registry'], $h['registered'], $h['breed']]);
        $h = AuctioneerSoftwareParser::horse($lots[2], 2026);
        $this->assertSame(['SAMPLE DUN PEPTO', 2020, 'gelding', 'Bay', 'Sample Sire', 'Sample Dam'], [$h['name'], $h['foal_year'], $h['sex'], $h['color'], $h['sire'], $h['dam']]);
        $h = AuctioneerSoftwareParser::horse($lots[3], 2026);
        $this->assertSame(['Buck', 2018, false], [$h['name'], $h['foal_year'], $h['registered']]); // age 8 at a 2026 sale
        $this->assertTrue(AuctioneerSoftwareParser::notAHorse($lots[0], AuctioneerSoftwareParser::horse($lots[0], 2026)));
        $this->assertTrue(AuctioneerSoftwareParser::notAHorse($lots[5], AuctioneerSoftwareParser::horse($lots[5], 2026)));

        // A mixed sale barn's vaguely titled auction counts as a horse sale when most lots describe horses.
        $this->assertTrue(AuctioneerSoftwareHarvester::mostlyHorses($lots));
        $equipment = AuctioneerSoftwareParser::lots($this->lot(9, '1', 'John Deere 4020 tractor', [], 'completedNoReserve', 'Winning Bidder', '$9,000.00', 3)
            .$this->lot(10, '2', 'Pallet of fence posts', [], 'completedNoReserve', 'Winning Bidder', '$90.00', 2));
        $this->assertFalse(AuctioneerSoftwareHarvester::mostlyHorses($equipment));
    }

    public function test_collects_finished_auctions_once_and_loads_registered_names(): void
    {
        $page = $this->page();
        $lotPage = '<html><body>Lot Number: 1 Completed Start Time: 4/11/2026 2:00:00 PM</body></html>';
        $requests = 0;
        Http::fake(function ($request) use ($page, $lotPage, &$requests) {
            $requests++;
            $u = $request->url();

            return match (true) {
                str_ends_with($u, '/sitemap/past-auctions') => Http::response('https://bid.bigskyhorsesales.com/sitemap/auction/7 https://bid.bigskyhorsesales.com/sitemap/auction/8'),
                str_ends_with($u, '/sitemap/auction/7') => Http::response('https://bid.bigskyhorsesales.com/auctions/7-spring-sample-sale https://bid.bigskyhorsesales.com/auctions/7/lot/1 https://bid.bigskyhorsesales.com/auctions/7/lot/2 https://bid.bigskyhorsesales.com/auctions/7/lot/3 https://bid.bigskyhorsesales.com/auctions/7/lot/4 https://bid.bigskyhorsesales.com/auctions/7/lot/5 https://bid.bigskyhorsesales.com/auctions/7/lot/6'),
                str_ends_with($u, '/sitemap/auction/8') => Http::response('https://bid.bigskyhorsesales.com/auctions/8-2026-stallion-service-auction https://bid.bigskyhorsesales.com/auctions/8/lot/9'),
                str_contains($u, '/auctions/7-spring-sample-sale') => Http::response($page),
                str_contains($u, '/auctions/7/lot/') => Http::response($lotPage),
                default => Http::response('Not found', 404),
            };
        });
        $dir = sys_get_temp_dir().'/as-test-'.uniqid();
        $h = new AuctioneerSoftwareHarvester('bigsky', 0, null, $dir);
        $out = $h->collect();
        $this->assertSame([2, 1, 1], [$out['auctions_listed'], $out['fetched'], $out['skipped_not_horses']]);
        $this->assertSame(0, $h->collect()['fetched']); // finished auctions are kept: a rerun fetches nothing new

        $loaded = $h->load();
        $this->assertSame([4, 1, 2, 1], [$loaded['loaded'], $loaded['sold'], $loaded['high_bid'], $loaded['no_sale']]);
        $lot = SaleLot::where('horse_name', 'Sample Smart Chex')->first();
        $this->assertSame(['sold', 950000, '2026-04-11', 'Big Sky Horse Sales: Spring Sample Sale'], [$lot->status, $lot->price_cents, $lot->sale_date->toDateString(), $lot->sale_name]);
        $this->assertSame('https://bid.bigskyhorsesales.com/auctions/7/lot/2-x', $lot->source_url);
        // A registered name (AQHA, two words) with no record of that name: a new unverified record (Richard's rule).
        $this->assertSame(['created', 'Sample Smart Chex', 'unverified'], [$lot->match_tier, $lot->horse->registered_name, $lot->horse->identity_status]);
        // A grade horse with a one-word name builds nothing.
        $this->assertNull(SaleLot::where('horse_name', 'Buck')->first()->horse_id);
        $json = SaleLot::all()->toJson();
        foreach (['Sample Consignor', 'Sample Trainer', '555-0100', 'x@example.com', 'j****n', 'prose'] as $gone) {
            $this->assertStringNotContainsString($gone, $json);
        }
    }

    public function test_stops_on_a_block(): void
    {
        Http::fake(fn () => Http::response('<html><title>Just a moment...</title></html>', 403));
        $this->expectException(HarvestBlocked::class);
        (new AuctioneerSoftwareHarvester('eugene', 0, null, sys_get_temp_dir().'/as-test-'.uniqid()))->collect();
    }
}
