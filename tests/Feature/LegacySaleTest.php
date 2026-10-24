<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Harvest\LegacySaleHarvester;
use App\Harvest\LegacySaleParser;
use App\Harvest\RegistrationOnlyMatch;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Legacy Reining Breeders Sale results tables: facts only, Robin Glenn lots of the same horse paired (all names fictional). */
class LegacySaleTest extends TestCase
{
    use RefreshDatabase;

    private function table(array $head, array $rows): string
    {
        $h = '<table class="tablepress"><thead><tr>'.implode('', array_map(fn ($c) => '<th><strong>'.$c.'</strong></th>', $head)).'</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr>'.implode('', array_map(fn ($c) => '<td>'.$c.'</td>', $r)).'</tr>';
        }

        return $h.'</tbody></table>';
    }

    /** 2014–2018: name and foaling line in one cell, sire and dam columns, pass-outs and scratches. */
    private function oldPage(): string
    {
        $head = ['Hip #', 'Horse', 'Sire', 'Dam', 'Purchaser', 'Final Bid'];

        return '<html><body><h1>2014 Sale Results</h1>'.$this->table($head, [
            ['1', 'SAMPLE SPARK DOC|<br />'."\n".'2013 PALOMINO STALLION', 'SAMPLE SHINER', 'SAMPLE JACKED UP', 'Pass Out', '$15,000'],
            ['2', 'Sample Wally Rae<br />2013 Grulla Mare', 'Sample Walla', 'Sample Enterprise', 'Ann Example', '$6,700'],
            ['11-X', 'Sample Gotta Spark *<br />2013 Bay APHA Mare*Bred to Sample Stud', 'Sample Gotta', 'Sample Lena', 'Bob Example', '$21,500'],
            ['12', 'OUT', '', '', 'OUT', ''],
            ['13', 'PENDING<br />2013 STALLION', 'SAMPLE MAGNUM', 'SAMPLE SPRING', 'Cara Example', '$9,500'],
            ['14', 'SAMPLE BITTERS (PENDING)<br />2013 BUCKSKIN STALLION', 'SAMPLE WHIZ', 'SAMPLE SONITA', 'PO', '$21,000'],
            ['15', 'Sample Quiet Night<br />2013 Sorrel Mare', 'Sample Gunner', 'Sample Freckle', '', ''],
        ]).$this->table($head, [
            ['201', 'Sample Lil Cash<br />2010 Sorrel Mare', 'Sample Chex', 'Sample Joyce', 'Dan Example', '$5,500'],
            ['', '', '', '', 'TOTAL', '$57,700'],
        ]).'</body></html>';
    }

    /** 2024–2026: consignor | description | amount | buyer, with "Pending/<dam>" and totals rows. */
    private function newPage(): string
    {
        return '<html><body>'.$this->table(['HIP No', 'Consignor', 'Description', 'Sale Amount', 'Buyer'], [
            ['1', 'Example, Ann', 'Sl Sample Smartya', '$15,500.00 ', 'EXAMPLE, BOB'],
            ['2', 'Example Ranch Llc,', 'Pending/Sample Makeup', '$8,000.00 ', 'EXAMPLE, CARA'],
            ['3', 'Example, Dan', 'Sample Gold Is Electric', '$0.00 ', '-PASS OUT,'],
            ['4', 'Example, Eve', 'Sample Nite Payday - Out', '$0.00 ', '-OUT OF SALE,'],
            ['6', 'Example, Fay', 'Pending A/Sample Exit', '$16,000.00 ', 'EXAMPLE, GUS'],
            ['7', 'Example, Hal', 'Selling On Reg App', '$4,000.00 ', 'EXAMPLE, IVY'],
            ['Total', '', '', '$43,500.00', ''],
            ['Average', '', '', '$10,875.00', ''],
        ]).'</body></html>';
    }

    public function test_old_layout_reads_pedigree_columns_and_outcomes(): void
    {
        $rows = collect(LegacySaleParser::rows($this->oldPage()))->keyBy('hip');
        $this->assertEquals(['1', '2', '11X', '12', '13', '14', '15', '201'], $rows->keys()->all());

        $this->assertSame(['Sample Spark Doc', 2013, 'stallion', 'Palomino', 'Sample Shiner', 'Sample Jacked Up', 'no_sale', null, 1500000],
            [$rows['1']['name'], $rows['1']['foal_year'], $rows['1']['sex'], $rows['1']['color'], $rows['1']['sire'], $rows['1']['dam'], $rows['1']['status'], $rows['1']['price_cents'], $rows['1']['last_bid_cents']]);
        $this->assertSame(['Sample Wally Rae', 'sold', 670000, true], [$rows['2']['name'], $rows['2']['status'], $rows['2']['price_cents'], $rows['2']['has_buyer']]);
        // "*" and "*Bred to ..." dropped; a lone APHA is the registry.
        $this->assertSame(['Sample Gotta Spark', 'APHA', 'mare', 'Bay'], [$rows['11X']['name'], $rows['11X']['registry'], $rows['11X']['sex'], $rows['11X']['color']]);
        $this->assertSame('withdrawn', $rows['12']['status']);
        $this->assertSame([null, true, 'sold'], [$rows['13']['name'], $rows['13']['pending'], $rows['13']['status']]);
        $this->assertSame([null, true, 'no_sale'], [$rows['14']['name'], $rows['14']['pending'], $rows['14']['status']]); // "PO" = passed out
        $this->assertSame('offered', $rows['15']['status']);
        $this->assertSame(['sold', 550000], [$rows['201']['status'], $rows['201']['price_cents']]);
    }

    public function test_new_layout_and_no_people_are_kept(): void
    {
        $rows = collect(LegacySaleParser::rows($this->newPage()))->keyBy('hip');
        $this->assertEquals(['1', '2', '3', '4', '6', '7'], $rows->keys()->all());
        $this->assertSame(['Sl Sample Smartya', 'sold', 1550000], [$rows['1']['name'], $rows['1']['status'], $rows['1']['price_cents']]);
        $this->assertSame([null, true, 'Sample Makeup', 800000], [$rows['2']['name'], $rows['2']['pending'], $rows['2']['dam'], $rows['2']['price_cents']]);
        $this->assertSame(['no_sale', null, null], [$rows['3']['status'], $rows['3']['price_cents'], $rows['3']['last_bid_cents']]);
        $this->assertSame(['withdrawn', 'Sample Nite Payday'], [$rows['4']['status'], $rows['4']['name']]);
        $this->assertSame([true, 'Sample Exit'], [$rows['6']['pending'], $rows['6']['dam']]);
        $this->assertSame([null, true], [$rows['7']['name'], $rows['7']['pending']]);
        $this->assertStringNotContainsString('EXAMPLE', json_encode($rows->all()));
        $this->assertStringNotContainsString('Example', json_encode($rows->all()));
    }

    public function test_load_keeps_facts_and_never_matches_unnamed_rows(): void
    {
        $h = new LegacySaleHarvester(0, null, sys_get_temp_dir().'/legacy-'.uniqid());
        $out = $h->load(['2014' => LegacySaleParser::rows($this->oldPage()), '2024' => LegacySaleParser::rows($this->newPage())]);
        $this->assertSame(1 + 1, $out['withdrawn_skipped']);
        $this->assertSame(12, $out['loaded']);
        $this->assertSame(5, $out['unnamed']);

        $lot = SaleLot::where('external_id', 'legacy:2014:2')->first();
        $this->assertSame(['Legacy Reining Breeders Sale 2014', '2014-09-01', 'month', 'sold', 670000, 'Sample Walla', 2013],
            [$lot->sale_name, $lot->sale_date->toDateString(), $lot->date_precision, $lot->status, $lot->price_cents, $lot->sire_name, $lot->foal_year]);
        // Registered name with sire and dam: the name rule makes an unverified record.
        $this->assertNotNull($lot->horse_id);

        $pending = SaleLot::where('external_id', 'legacy:2024:2')->first();
        $this->assertSame(['Pending', false, 800000, null, RegistrationOnlyMatch::UNMATCHED], [$pending->horse_name, $pending->registered, $pending->price_cents, $pending->horse_id, $pending->review_status]);
        $this->assertStringContainsString('passed out', SaleLot::where('external_id', 'legacy:2014:1')->value('note'));
        $this->assertStringContainsString('$15,000', SaleLot::where('external_id', 'legacy:2014:1')->value('note'));
        // No-pedigree rows (2024 on) stay unmatched; no buyer or consignor anywhere.
        $this->assertNull(SaleLot::where('external_id', 'legacy:2024:1')->value('horse_id'));
        $this->assertStringNotContainsString('Example', SaleLot::all()->toJson());
    }

    public function test_results_row_pairs_with_the_robin_glenn_lot_of_the_same_hip(): void
    {
        $system = BuildRecordsFromResults::system();
        $rg = DataSource::firstOrCreate(['key' => 'robinglenn'], ['name' => 'Robin Glenn QData catalogs', 'url' => 'https://example.test', 'permission' => 'no_terms_posted']);
        $horse = new Horse(['registered_name' => 'SL Sample Smartya', 'birth_precision' => 'unknown']);
        $horse->horsefax_id = CreateHorse::newId();
        $horse->created_by = $system->id;
        $horse->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        $rgLot = fn ($id, $lot, $name, $year, $values = []) => SaleLot::record($rg->id, $id, $values + ['sale_name' => 'Legacy Reining Breeders Sale'.($year === 2026 ? ' 2026' : ''),
            'sale_date' => $year.'-09-20', 'lot' => $lot, 'horse_name' => $name, 'status' => 'offered', 'source_url' => 'https://example.test/rg']);
        $onRecord = $rgLot('2650:1', '1', 'SL Sample Smartya', 2025, ['horse_id' => $horse->id, 'match_tier' => 'created', 'sire_name' => 'Sample Smart', 'dam_name' => 'Sample Ya']);
        $pendingRg = $rgLot('2650:2', '2', 'Pending/Sample Makeup', 2025, ['horse_id' => $horse->id]); // never paired by name
        $waiting = $rgLot('3061:5', '5', 'Sample Tempting Crush', 2026, ['review_status' => 'unmatched', 'sire_name' => 'Sample Mega', 'dam_name' => 'Sample Temptress', 'foal_year' => 2025]);

        $designer = $this->table(['HIP No', 'Horse Name', 'Consignor', 'Sex', 'Foaled', 'Color', 'Sire', 'Dam', 'Sale Amount', 'Buyer'], [
            ['5', 'Sample Tempting Crush', 'Example Reining,', 'Stallion', '2025', 'Buckskin', 'Sample Mega', 'Sample Temptress', '$35,000.00', 'EXAMPLE, JO'],
        ]);
        $rows = ['2025' => LegacySaleParser::rows($this->newPage()), '2026-designer' => LegacySaleParser::rows($designer)];
        $out = (new LegacySaleHarvester(0, null, sys_get_temp_dir().'/legacy-'.uniqid()))->load($rows);
        $this->assertSame(2, $out['same_horse_as_robin_glenn_lot']);

        // 1. The Robin Glenn lot is on a record: the results row joins it and carries the price; the RG lot is same_sale.
        $r1 = SaleLot::where('external_id', 'legacy:2025:1')->first();
        $this->assertSame([$horse->id, 'same_lot', null, 'sold', 1550000, '2025-09-20', 'day'],
            [$r1->horse_id, $r1->match_tier, $r1->review_status, $r1->status, $r1->price_cents, $r1->sale_date->toDateString(), $r1->date_precision]);
        $this->assertSame([LegacySaleHarvester::SAME_SALE, null], [$onRecord->fresh()->review_status, $onRecord->fresh()->price_cents]);
        // A "Pending/..." row is never paired, even on the same hip.
        $this->assertNull(SaleLot::where('external_id', 'legacy:2025:2')->value('horse_id'));
        $this->assertNull($pendingRg->fresh()->review_status);
        // 2. The Designer row makes the record (name, sire, dam); the waiting RG lot of the same year and hip goes onto it.
        $r5 = SaleLot::where('external_id', 'legacy:2026-designer:5')->first();
        $this->assertNotNull($r5->horse_id);
        $this->assertSame([$r5->horse_id, 'same_lot', LegacySaleHarvester::SAME_SALE], [$waiting->fresh()->horse_id, $waiting->fresh()->match_tier, $waiting->fresh()->review_status]);
        $this->assertSame('2026-09-18', $r5->sale_date->toDateString());

        // A rerun changes nothing.
        $again = (new LegacySaleHarvester(0, null, sys_get_temp_dir().'/legacy-'.uniqid()))->load($rows);
        $this->assertSame(0, $again['same_horse_as_robin_glenn_lot']);
        $this->assertSame($horse->id, $r1->fresh()->horse_id);
    }

    public function test_collect_reads_robots_keeps_pages_gzipped_and_stops_on_a_block(): void
    {
        $dir = sys_get_temp_dir().'/legacy-'.uniqid();
        Http::fake([
            'legacysale.com/robots.txt' => Http::response("User-agent: *\nDisallow: /wp-admin/\n"),
            'legacysale.com/2014-sale-results/' => Http::response($this->oldPage()),
            'legacysale.com/2015-sale-results/' => Http::response('', 429),
            '*' => Http::response($this->newPage()),
        ]);
        try {
            (new LegacySaleHarvester(0, null, $dir))->collect();
            $this->fail('A 429 must stop the run');
        } catch (\App\Harvest\HarvestBlocked) {
        }
        $this->assertFileExists($dir.'/pages/2014-sale-results.html.gz');
        $this->assertSame($this->oldPage(), gzdecode(file_get_contents($dir.'/pages/2014-sale-results.html.gz')));
        Http::assertSent(fn ($r) => str_contains($r->header('User-Agent')[0] ?? '', 'HorsefaxBot'));
    }
}
