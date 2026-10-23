<?php

namespace Tests\Feature;

use App\Harvest\HarvestBlocked;
use App\Harvest\HorseWebHarvester;
use App\Harvest\HorseWebParser;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** HorseWeb.com: facts only, asking prices only, ad pages only where they matter (all names fictional). */
class HorseWebTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $id, string $name, string $breed, string $sex, string $age, string $price, string $tag = ''): string
    {
        return '<div class="li" title="Headline"> <div class="image ss ss_0" data-status="'.$tag.'"> </div> <div class="max"> <div class="table-cell"> <div class="name">'.$name.'</div>'
            .'<div class="title"><a rel="nofollow" href="/horse-'.$id.'/" data-id="/horse-'.$id.'/">Headline for '.$name.'</a></div> <div class="description">Seller prose never kept, call (555) 555-0100</div>'
            .'<div class="location">Sample Town, Texas</div> </div> </div> <div class="desktop color l nw">Bay</div> <div class="tablet breed l nw">'.$breed.'</div>'
            .'<div class="tablet gender l">'.$sex.'</div> <div class="tablet age c">'.$age.'</div> <div class="tablet city nw">Sample Town, TX</div> <div class="tablet location c">TX</div> <div class="price r">'.$price.'</div> </div>';
    }

    private function adPage(string $name, string $registry, string $reg): string
    {
        $f = fn ($k, $v) => "<div><span>$k</span></div><div><span>$v</span></div>";

        return '<html><body><h1>Headline</h1>'.$f('Name', $name).$f('Breed', 'Quarter Horse').$f('Gender', 'Mare').$f('Color', 'Bay').$f('Registry', $registry)
            .$f('Reg Number', $reg).$f('Height', '15.1 hh').$f('Foal Date', 'April, 2018').$f('Location Map', ', TX 75001').$f('Ad Status', 'Available').$f('Price', '$12,500')
            .'<p>Seller prose never kept. Call (555) 555-0100</p></body></html>';
    }

    public function test_listing_and_ad_page_facts_only(): void
    {
        $rows = HorseWebParser::listing('<div class="results">'.$this->row('3y7unm', 'Sample Smart Lena', 'Quarter Horse', 'Mare', '8', '$12,500').$this->row('3y7tdo', 'Dutton', 'Rocky Mountain', 'Gelding', '5', 'Contact', 'Sold').'</div>');
        $this->assertSame(['3y7unm', 'Sample Smart Lena', null, 'Quarter Horse', 'Mare', 8, 'TX', 1250000], array_values(array_intersect_key($rows[0], array_flip(['ad_id', 'name', 'breed', 'sex', 'age', 'state', 'price_cents', 'tag']))));
        $this->assertSame(['Sold', null], [$rows[1]['tag'], $rows[1]['price_cents']]);
        $ad = HorseWebParser::ad($this->adPage('Sample Smart Lena', 'AQHA', '5512345'));
        $this->assertSame(['Sample Smart Lena', 'AQHA', '5512345', 2018, 4, 1250000], [$ad['name'], $ad['registry'], $ad['registration_number'], $ad['foal_year'], $ad['foal_month'], $ad['price_cents']]);
        $this->assertNull(HorseWebParser::ad($this->adPage('Dutton', 'NA', 'NA'))['registry']);
        foreach (['prose', '555-0100', 'Sample Town'] as $gone) {
            $this->assertStringNotContainsString($gone, json_encode([$rows, $ad]));
        }
    }

    public function test_run_stops_at_the_repeated_last_page_and_loads_asking_prices(): void
    {
        $p1 = '<div class="results">'.$this->row('aaaaaa', 'Sample Smart Lena', 'Quarter Horse', 'Mare', '8', '$12,500').$this->row('bbbbbb', 'Dutton', 'Rocky Mountain', 'Gelding', '5', '$4,000', 'Sold').'</div>';
        $p2 = '<div class="results">'.$this->row('cccccc', 'Sample Stud Muffin', 'Paint', 'Stallion', '9', '$900', 'Stud').'</div>';
        Http::fake(function ($request) use ($p1, $p2) {
            $u = $request->url();

            return match (true) {
                str_ends_with($u, '/horses/') => Http::response($p1),
                (bool) preg_match('#/horses/\d+/$#', $u) => Http::response($p2), // every later page repeats the last one
                str_ends_with($u, '/horse-aaaaaa/') => Http::response($this->adPage('Sample Smart Lena', 'AQHA', '')),
                str_ends_with($u, '/horse-cccccc/') => Http::response($this->adPage('Sample Stud Muffin', 'APHA', '')),
                default => Http::response('Not found', 404),
            };
        });
        $h = new HorseWebHarvester(0, null, sys_get_temp_dir().'/hw-test-'.uniqid());
        $out = $h->run();
        $this->assertSame([2, 3, 2], [$out['pages'], $out['listed'], $out['ad_pages']]); // Dutton (one word) needs no ad page
        $loaded = $h->load();
        $this->assertSame([2, 1, 1], [$loaded['loaded'], $loaded['skipped_stud'], $loaded['created']]);
        $lot = SaleLot::where('horse_name', 'Sample Smart Lena')->first();
        $this->assertSame(['asking', 1250000, 'AQHA', 2018, 'mare'], [$lot->status, $lot->price_cents, $lot->registry, $lot->foal_year, $lot->sex]);
        $this->assertSame(['created', 'unverified'], [$lot->match_tier, $lot->horse->identity_status]);
        $dutton = SaleLot::where('horse_name', 'Dutton')->first();
        $this->assertSame('asking', $dutton->status); // a "Sold" tag never turns an asking price into a sale
        $this->assertStringContainsString('marked sold', $dutton->note);
        $this->assertNull($dutton->horse_id);
        $this->assertStringNotContainsString('555-0100', SaleLot::all()->toJson());
    }

    public function test_stops_on_a_block(): void
    {
        Http::fake(fn () => Http::response('Too many requests', 429));
        $this->expectException(HarvestBlocked::class);
        (new HorseWebHarvester(0, null, sys_get_temp_dir().'/hw-test-'.uniqid()))->run();
    }
}
