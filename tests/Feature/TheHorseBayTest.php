<?php

namespace Tests\Feature;

use App\Harvest\TheHorseBayImporter;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TheHorseBay: names as listed minus barn names, results never upgraded, no people stored (all names fictional). */
class TheHorseBayTest extends TestCase
{
    use RefreshDatabase;

    private function row(array $o = []): array
    {
        return $o + ['post_id' => '1', 'slug' => 'sample', 'url' => 'https://thehorsebay.com/product/sample/', 'name' => 'Sample Smart Dun “Dusty”',
            'status' => 'expired', 'sold' => 'True', 'reserve_not_met' => 'False', 'n_bids' => '12', 'top_bid' => '9500.0',
            'closed' => '2026-05-26 19:02:00', 'auction_started' => '2026-05-19 18:00:00', 'breed' => 'AQHA', 'registered' => 'Yes',
            'reg_number' => '5960917', 'color' => 'Red Dun', 'foal_date' => '06-10-2019', 'sex' => 'Gelding'];
    }

    public function test_names_numbers_and_registries(): void
    {
        foreach (['Captains Classy Dun “Classy”' => 'Captains Classy Dun', '“Hydro” Plenty Blue Revue' => 'Plenty Blue Revue',
            'Ima Smokey Pep Kid (Rojo)' => 'Ima Smokey Pep Kid', 'Judge Roy Bean aka “Cinnamon”' => 'Judge Roy Bean',
            '“Sample”' => 'Sample', 'BFGS Sample Blues – AQHA Mare' => 'BFGS Sample Blues', 'Poco Sample Jade- Cabarello' => 'Poco Sample Jade',
            'Leo-Sample Proposal' => 'Leo-Sample Proposal', 'Pushers final nightmare ( prince )' => 'Pushers final nightmare'] as $title => $name) {
            $this->assertSame($name, TheHorseBayImporter::name($title), $title);
        }
        $this->assertSame(['TWHBEA', '21710103'], TheHorseBayImporter::registration('Tennessee walking Horse', '21710103/BL/TO'));
        $this->assertSame(['MFTHBA', '15-101387'], TheHorseBayImporter::registration('Missouri Fox Trotter', '15-101387'));
        $this->assertSame(['APHA', '1051808'], TheHorseBayImporter::registration('American Paint Horse', '1,051,808'));
        $this->assertSame(['AHA', '676701'], TheHorseBayImporter::registration('Arabian (AHR)', 'AHR-676701'));
        $this->assertSame(['IALHA', '21227'], TheHorseBayImporter::registration('Andalusian', 'IALHA #21227(S/P)'));
        $this->assertSame([null, null], TheHorseBayImporter::registration('Dutch Warmblood (KWPN)', '840025201200197')); // a microchip
        $this->assertSame([null, '2016201263'], TheHorseBayImporter::registration('Spotted Saddle Horse', 'SHOBA-2016201263'));
        $this->assertSame([null, 'GV08155P'], TheHorseBayImporter::registration('Gypsy Cross', 'GV08155P'));
        $this->assertSame(['AHA', '657095'], TheHorseBayImporter::registration('Arabian', 'AHR*657095'));
        $this->assertSame([null, 'AB00004458'], TheHorseBayImporter::registration('Gypsy Vanner (GHRA)', 'AB00004458'));
        $this->assertSame(['GVHS', 'GV11336'], TheHorseBayImporter::registration('Gypsy Vanner (GVHS)', 'GV11336'));
        $this->assertSame([null, null], TheHorseBayImporter::registration('Mustang', 'Freezemark #14861480'));
        $this->assertSame([null, null], TheHorseBayImporter::registration('Gypsy Vanner Cross', 'IHWHA 2006 HC250 FH'));
        $this->assertSame([null, '6924-2022'], TheHorseBayImporter::registration('Friesian Tennessee Walker Cross', '6924-2022'));
    }

    public function test_results_are_never_upgraded_and_mules_and_tests_are_skipped(): void
    {
        $v = TheHorseBayImporter::values($this->row());
        $this->assertSame(['sold', 950000, '2026-05-26', 'day', 'Sample Smart Dun'], [$v['status'], $v['price_cents'], $v['sale_date'], $v['date_precision'], $v['horse_name']]);
        $this->assertSame(['gelding', '2019-06-10', 2019, 'AQHA', '5960917', true], [$v['sex'], $v['birth_date'], $v['foal_year'], $v['registry'], $v['registration_number'], $v['registered']]);
        $rnm = TheHorseBayImporter::values($this->row(['sold' => 'False', 'reserve_not_met' => 'True', 'top_bid' => '21500']));
        $this->assertSame(['high_bid', 2150000, 'Reserve not met'], [$rnm['status'], $rnm['price_cents'], $rnm['note']]);
        $none = TheHorseBayImporter::values($this->row(['sold' => 'False', 'n_bids' => '0', 'top_bid' => '15000', 'closed' => '']));
        $this->assertSame(['offered', null, '2026-05-01', 'month'], [$none['status'], $none['price_cents'], $none['sale_date'], $none['date_precision']]);
        $this->assertNull(TheHorseBayImporter::values($this->row(['status' => 'live'])));
        $this->assertNull(TheHorseBayImporter::values($this->row(['breed' => 'Gaited Mule', 'sex' => 'John'])));
        $this->assertNull(TheHorseBayImporter::values($this->row(['name' => 'Ralphie TEST'])));
        $grade = TheHorseBayImporter::values($this->row(['registered' => 'No', 'reg_number' => '', 'breed' => 'Quarter Horse']));
        $this->assertSame([false, null, null], [$grade['registered'], $grade['registry'], $grade['registration_number']]);
    }

    public function test_load_stores_facts_only_and_creates_unverified_records_for_registered_names(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'thb');
        $rows = [$this->row(), $this->row(['slug' => 'bob', 'name' => 'Bob', 'registered' => 'No', 'reg_number' => '', 'breed' => 'Paint Horse'])];
        $h = fopen($file, 'w');
        fputcsv($h, array_keys($rows[0]), escape: '');
        foreach ($rows as $r) {
            fputcsv($h, array_map(fn ($k) => $r[$k], array_keys($rows[0])), escape: '');
        }
        fclose($h);

        $out = (new TheHorseBayImporter($file))->load();
        $this->assertSame([2, 2, 1, 1], [$out['loaded'], $out['sold'], $out['created'], $out['unmatched']]);
        $lot = SaleLot::where('external_id', 'thb:sample')->first();
        $this->assertNotNull($lot->horse_id);
        $this->assertSame('TheHorseBay online auction, winning bid as reported by TheHorseBay', $lot->sale_name);
        $this->assertNull(SaleLot::where('external_id', 'thb:bob')->first()->horse_id);
        // A rerun changes nothing.
        $again = (new TheHorseBayImporter($file))->load();
        $this->assertSame(2, $again['already'] + $again['unmatched']);
        unlink($file);
    }
}
