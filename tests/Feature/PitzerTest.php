<?php

namespace Tests\Feature;

use App\Harvest\PitzerHarvester;
use App\Harvest\PitzerParser;
use App\Models\Horse;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pitzer Ranch catalogs read from word positions: facts only, no consignors, phone numbers or notes (all names fictional). */
class PitzerTest extends TestCase
{
    use RefreshDatabase;

    /** `pdftotext -bbox` words for one printed line: about half the type height per character, a fifth for each gap. */
    private function words(float $x, float $y, string $text, float $h = 8.9): string
    {
        $out = '';
        foreach (explode(' ', $text) as $word) {
            $w = mb_strlen($word) * $h * 0.5;
            $out .= sprintf('<word xMin="%.6f" yMin="%.6f" xMax="%.6f" yMax="%.6f">%s</word>', $x, $y, $x + $w, $y + $h, htmlspecialchars($word, ENT_XML1));
            $x += $w + $h * 0.2;
        }

        return $out;
    }

    /** A left-hand lot block starting at $top: hip, consignor, name, foaling line, 14-name chart, notes. */
    private function leftLot(float $top, string $hip, string $name, string $foaled, array $chart, ?string $reg = null, ?string $leftName = null, float $h = 8.9): string
    {
        $s = $this->words(36, $top, 'Hip No.', 12).$this->words(104, $top + 0.6, 'Consigned by Sample Consignor 555.555.0100', 10.3)
            .$this->words(96.9, $top + 8.2, $name, 21.2).$this->words(52.2, $top + 11.1, $hip, 15.9).$this->words(113.8, $top + 27.6, $foaled, 11.3);
        // Gen 1 at x=117, gen 2 at x=189, gen 3 at x=261, top to bottom; braces sit in front of some names.
        $ys = ['g3' => [40.0, 47.5, 55.7, 63.2, 74.5, 82.7, 91.7, 99.2], 'g2' => [43.7, 58.7, 77.5, 94.0], 'g1' => [50.5, 82.0]];
        foreach (['g1' => 117, 'g2' => 189, 'g3' => 261] as $g => $x) {
            foreach ($chart[$g] as $i => $n) {
                $s .= $this->words($x - 5.5, $top + $ys[$g][$i] - 1, '{', $h).$this->words($x, $top + $ys[$g][$i], $n, $h);
            }
        }
        $s .= $this->words(36, $top + 63.2, $leftName ?? $name, 9.0);
        if ($reg) {
            $s .= $this->words(36, $top + 71.1, $reg, 8.5);
        }

        return $s.$this->words(36, $top + 108, 'NOTES: 52x1 - Sample notes about the colt. Call Sample Agent 555.555.0199.', 8.4)
            .$this->words(516, $top + 184, 'QData-CB 7/2025', 8.4);
    }

    private function chart(string $p): array
    {
        return ['g1' => ["$p Sire", "$p Dam"], 'g2' => ["$p SS", "$p SD", "$p DS", "$p DD"],
            'g3' => ["$p SSS", "$p SSD", "$p SDS", "$p Chers Shadow", "$p DSS", "$p DSD", "$p DDS", "$p DDD"]];
    }

    private function page(string $words): string
    {
        return '<page width="612.000000" height="792.000000">'.$words.'</page>';
    }

    public function test_catalog_lots_from_word_positions_without_people(): void
    {
        $xhtml = '<doc>'.$this->page($this->words(36, 30, 'Friday SEPTEMBER 5 & Saturday SEPTEMBER 6, 2025 @ 9am (cst)', 20))
            .$this->page($this->leftLot(34.5, '20', 'Sample Likely Shiner', 'June 4, 2024 Brown Yrl Stud', $this->chart('Alpha'), '6000001')
                .$this->leftLot(277.5, '21', 'SX x Sample Jessie', 'May 15, 2025 Red Roan Wn Stud', $this->chart('Beta'))
                .$this->leftLot(520.5, '22', 'Sample Old Mare', '2014 Palomino Broodmare', $this->chart('Gamma'))).'</doc>';
        $pages = PitzerParser::pages($xhtml);
        $lots = PitzerParser::catalog($pages);
        $this->assertCount(3, $lots);
        [$a, $b, $c] = $lots;
        $this->assertSame(['20', 'Sample Likely Shiner', '6000001', 2024, '2024-06-04', 'Brown', 'yearling', 'colt', false],
            [$a['lot'], $a['name'], $a['registration_number'], $a['foal_year'], $a['birth_date'], $a['color'], $a['age_class'], $a['sex'], $a['placeholder']]);
        $this->assertCount(14, $a['pedigree']);
        $this->assertSame(['Alpha Sire', 'Alpha Dam', 'Alpha DS', 'Alpha Chers Shadow', 'Alpha DDD'], [$a['pedigree']['s'], $a['pedigree']['d'], $a['pedigree']['ds'], $a['pedigree']['sdd'], $a['pedigree']['ddd']]);
        $this->assertSame(['SX x Sample Jessie', true, 'colt', 'weanling'], [$b['name'], $b['placeholder'], $b['sex'], $b['age_class']]); // an unnamed foal's label
        $this->assertSame([2014, null, 'mare', 'Palomino'], [$c['foal_year'], $c['birth_date'], $c['sex'], $c['color']]);
        foreach (['Consign', '555.555', 'notes', 'Agent', 'QData'] as $gone) {
            $this->assertStringNotContainsString($gone, json_encode($lots));
        }
        $sale = PitzerParser::sale($pages);
        $this->assertSame(['fall-2025', 'Pitzer Ranch Fall Sale 2025', '2025-09-05', '2025-09-06'], [$sale['key'], $sale['name'], $sale['from'], $sale['to']]);
    }

    public function test_right_hand_hip_and_hip_read_into_a_scanned_name(): void
    {
        // A scanned page: the hip number is read into the heading ("Sample Dutchess 333"), boxes are tall, the chart is garbled.
        $words = $this->words(536, 33, 'Hip No.', 15.2).$this->words(307, 41.3, 'Sample Dutchess 333', 23.6).$this->words(344, 61.6, 'May 6, 2026 Red Roan Wn Filly', 13.3)
            .$this->words(279.5, 89.8, 'Sample Dutchess', 18.5).$this->words(362, 77, 'Samp1e Sjre', 18.5).$this->words(504, 66, 'Ova Drifly', 18.5)
            .$this->words(279.4, 134.9, 'NOTES: about her', 18.5)
            // A second scanned lot whose two printings of the name disagree: dropped.
            .$this->words(536, 276, 'Hip No.', 15.2).$this->words(544, 284.6, '334', 22.9).$this->words(319, 284.6, 'Sample Patty', 23.6)
            .$this->words(359, 303.8, 'May 7, 2026 Bay Wn Stud', 14.6).$this->words(279.2, 333.2, 'Samp1e Patty', 18.5);
        $lots = PitzerParser::catalog([PitzerParser::pages($this->page($words))[0]], [true]);
        $this->assertCount(1, $lots);
        $this->assertSame(['333', 'Sample Dutchess', true, [], null, 'filly', '2026-05-06'], [$lots[0]['lot'], $lots[0]['name'], $lots[0]['ocr'], $lots[0]['pedigree'], $lots[0]['registration_number'], $lots[0]['sex'], $lots[0]['birth_date']]);
    }

    public function test_descriptions_placeholders_and_line_up_list(): void
    {
        $this->assertSame(['Red Roan', 'weanling', 'colt'], PitzerParser::description('Red Roan Wn Stud'));
        $this->assertSame(['Sorrel', null, 'stallion'], PitzerParser::description('Sorrel Stud'));
        $this->assertSame(['Bay', null, 'gelding'], PitzerParser::description('Bay Gelding'));
        $this->assertSame(['Gray', 'yearling', 'mare'], PitzerParser::description('Gray Yrl Mare'));
        foreach (['MB x Sample Jackie', 'SAMPLE CHEX x SMRB', '"Sample Blazer"', 'Pending'] as $p) {
            $this->assertTrue(PitzerParser::placeholder($p), $p);
        }
        $this->assertFalse(PitzerParser::placeholder('Sample Xavier Jack'));
        $list = "   Pitzer Ranch 2022 Fall Sale Line Up\nLOT #           HORSE NAME           Year   COLOR         SEX        LAST Name\n"
            ." 188    SAMPLE STARLIGHT             2014   CHESTNUT    GELDING       SAMPLE HORSE CO\n"
            ." 528    SAMPLE CHEX x MBJ            2022   PALOMINO    WN FILLY         SAMPLEMAN\n"
            ." 546    SAMPLE DEL ALGO              2007   BAY ROAN   BROODMARE         SAMPLESON\n";
        $lots = PitzerParser::lineUp($list);
        $this->assertSame([['188', 'Sample Starlight', 2014, 'Chestnut', 'gelding', false], ['528', 'Sample Chex x MBJ', 2022, 'Palomino', 'filly', true], ['546', 'Sample Del Algo', 2007, 'Bay Roan', 'mare', false]],
            array_map(fn ($l) => [$l['lot'], $l['name'], $l['foal_year'], $l['color'], $l['sex'], $l['placeholder']], $lots));
        $this->assertStringNotContainsString('SAMPLEMAN', json_encode($lots));
    }

    public function test_load_creates_records_only_from_registered_names(): void
    {
        $ped = ['s' => 'Sample Sire', 'd' => 'Sample Dam', 'ss' => 'Sample SS', 'sd' => 'Sample SD', 'ds' => 'Sample DS', 'dd' => 'Sample DD'];
        $lot = fn ($hip, $name, $placeholder, $ped, $ocr = false) => ['lot' => $hip, 'name' => $name, 'placeholder' => $placeholder, 'ocr' => $ocr, 'registration_number' => null,
            'foal_year' => 2025, 'birth_date' => '2025-05-01', 'color' => 'Bay', 'age_class' => 'weanling', 'sex' => 'colt', 'breed' => 'Quarter Horse', 'pedigree' => $ped];
        $rows = ['fall-2025' => [$lot('1', 'Sample Named Colt', false, $ped), $lot('2', 'SX x Sample Dam', true, $ped), $lot('3', 'Sample Scanned', false, [], true)]];
        $out = (new PitzerHarvester(0, null, sys_get_temp_dir().'/pitzer-test'))->load($rows);
        $this->assertSame([3, 3, 1, 1, 1, 2], [$out['loaded'], $out['offered'], $out['created'], $out['not_a_registered_name'], $out['scanned_page'], $out['unmatched']]);
        $this->assertSame(['pitzer:fall-2025:1', 'Pitzer Ranch Fall Sale 2025', '2025-09-05', 'Sample Sire'],
            [SaleLot::where('lot', '1')->value('external_id'), SaleLot::where('lot', '1')->value('sale_name'), SaleLot::where('lot', '1')->first()->sale_date->toDateString(), SaleLot::where('lot', '1')->value('sire_name')]);
        $this->assertNull(SaleLot::where('lot', '2')->value('horse_id'));
        $this->assertSame(1, Horse::where('registered_name', 'Sample Named Colt')->count());
        $this->assertSame(0, Horse::where('registered_name', 'like', '%Sample Dam%')->where('registered_name', 'like', '% x %')->count());
        $this->assertSame(0, Horse::where('registered_name', 'Sample Scanned')->count()); // no parentage: no record
        // A rerun changes nothing.
        $horses = Horse::count();
        $again = (new PitzerHarvester(0, null, sys_get_temp_dir().'/pitzer-test'))->load($rows);
        $this->assertSame([0, $horses], [$again['created'], Horse::count()]);
        $this->assertSame(3, SaleLot::count());
    }
}
