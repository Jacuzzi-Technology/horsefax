<?php

namespace Tests\Feature;

use App\Harvest\TriangleHarvester;
use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Triangle Horse Sales: which files are catalogs, which version is used, sale days, and no double counting with Robin Glenn. */
class TriangleTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_files_and_newest_version(): void
    {
        $this->assertSame([2026, 'Fall'], TriangleHarvester::saleOf('26TFS_Web_Final.pdf'));
        $this->assertSame([2026, 'Winter'], TriangleHarvester::saleOf('2026_Triangle_Winter_Sale_Digital_Catalog.pdf'));
        $this->assertNull(TriangleHarvester::saleOf('2026-Triangle-Fall-Sale-Consignment-Form.pdf'));
        $this->assertNull(TriangleHarvester::saleOf('2025_Triangle_Spring_Sale_stall_chart_v3.pdf'));
        $v = TriangleHarvester::versions([
            ['source_url' => 'https://x/2026/04/2026_Triangle_Spring_Sale_Digital_Catalog.pdf', 'modified' => '2026-04-15T10:00:00'],
            ['source_url' => 'https://x/2026/04/2026_Triangle_Spring_Sale_Digital_Catalog_v3.pdf', 'modified' => '2026-04-15T14:00:00'],
            ['source_url' => 'https://x/2026/04/TSS26_Stall-Map.pdf', 'modified' => '2026-04-24T10:00:00'],
        ]);
        $this->assertSame(['2026-spring'], array_keys($v));
        $this->assertSame(['2026_Triangle_Spring_Sale_Digital_Catalog_v3.pdf', '2026_Triangle_Spring_Sale_Digital_Catalog.pdf'], array_column($v['2026-spring'], 'file')); // newest first, older kept as fallback
    }

    public function test_sale_day_from_cover_or_schedule_in_season(): void
    {
        $this->assertSame('2026-10-30', TriangleHarvester::firstDate("47 TH TRIANGLE\nFALL SALE\nOCTOBER 30-31, 2026", 2026, '', 'Fall'));
        $this->assertSame('2024-01-25', TriangleHarvester::firstDate('JANUARY 25, 26 & 27, 2024', 2024, '', 'Winter'));
        // An ad for the next sale on the cover is skipped; the schedule's most-mentioned day wins.
        $this->assertSame('2023-05-06', TriangleHarvester::firstDate('Consign now for October 26-28, 2023', 2023, 'Saturday, May 6th Sale … SATURDAY, MAY 6 … Sunday, May 7 … Saturday, May 6', 'Spring'));
    }

    public function test_lots_already_in_robin_glenn_are_not_counted_twice(): void
    {
        $rg = DataSource::create(['key' => 'robinglenn', 'name' => 'Robin Glenn', 'url' => 'https://example.com', 'permission' => 'no_terms_posted']);
        SaleLot::record($rg->id, '5:12', ['sale_name' => 'Triangle Fall Sale', 'sale_date' => '2025-10-24', 'lot' => '12', 'horse_name' => 'Sample Smart Cat', 'status' => 'offered', 'source_url' => 'https://example.com']);
        $this->assertTrue(TriangleHarvester::inRobinGlenn(['sale_date' => '2025-10-25', 'hip' => '12', 'name' => 'Sample Smart Cat']));
        $this->assertFalse(TriangleHarvester::inRobinGlenn(['sale_date' => '2025-10-25', 'hip' => '13', 'name' => 'Sample Smart Cat']));
        $this->assertFalse(TriangleHarvester::inRobinGlenn(['sale_date' => '2026-10-30', 'hip' => '12', 'name' => 'Sample Smart Cat']));
    }

    private function tsv(array $lines): string
    {
        $rows = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];
        foreach ($lines as $i => $words) {
            foreach ($words as $j => [$w, $conf]) {
                $rows[] = implode("\t", [5, 1, 1, 1, $i + 1, $j + 1, 10 * $j, 20 * $i, 9, 9, $conf, $w]);
            }
        }

        return implode("\n", $rows);
    }

    public function test_ocr_names_compare_through_marks_and_one_slip(): void
    {
        $this->assertTrue(TriangleHarvester::sameOcrName('Somemoredelta', 'Somemoredelta (P)'));
        $this->assertTrue(TriangleHarvester::sameOcrName('Blue Duck (Grade)', 'Blue Duck'));
        $this->assertTrue(TriangleHarvester::sameOcrName('lfwhizscouldtalk', 'Ifwhizscouldtalk'));
        $this->assertFalse(TriangleHarvester::sameOcrName('TSU Hot N Cold', 'Chino Hickory'));
        $this->assertFalse(TriangleHarvester::sameOcrName('Name Pending', 'Pending/Anna Frame'));
        $this->assertFalse(TriangleHarvester::sameOcrName('Duke', 'Dike')); // short names must match exactly
    }

    public function test_ocr_numbers_are_checked_before_use(): void
    {
        $years = [2021 => [6100000, 6125972, 6150000, 6200000]];
        $h = fn ($name, $n, $y = 2021, $reg = 'AQHA') => ['name' => $name, 'registration_number' => $n, 'foal_year' => $y, 'registry' => $reg];
        $this->assertSame(['AQHA', '6125000'], TriangleHarvester::checkedNumber($h('Sample Bug', '6125000'), 'Sample Bug', $years));
        $this->assertSame(['APHA', '1111022'], TriangleHarvester::checkedNumber($h('Sample Diamond', '1111022'), 'Sample Diamond (P)', $years)); // a Paint number
        $this->assertSame(['AQHA', null], TriangleHarvester::checkedNumber($h('Sample Diamond', '1111022'), 'Sample Diamond', $years)); // AQHA can't be that low
        $this->assertSame(['AQHA', null], TriangleHarvester::checkedNumber($h('Dutchess (Grade)', '6179256'), 'Dutchess', $years)); // grade: no number
        $this->assertSame(['AQHA', null], TriangleHarvester::checkedNumber($h('Sample Far', '5500000'), 'Sample Far', $years)); // far from its year
    }

    public function test_ocr_reads_the_hip_header_and_only_a_confident_number(): void
    {
        $w = fn (string $line, float $conf = 96) => array_map(fn ($x) => [$x, $conf], explode(' ', $line));
        $page = $this->tsv([$w('Hip No. Consigned by Sample Consignor Hip No.'), $w('9 Sample Lassie Doc 9'), $w('2011 Chestnut Mare'),
            $w('Sample Brow Cat'), $w('Sample Lassie Doc Tari Sugar'), [['5423101', 95.5]], $w('NOTES: never kept')]);
        $h = TriangleHarvester::ocrHip(TriangleHarvester::ocrLines($page));
        $this->assertSame(['9', 'Sample Lassie Doc', 2011, 'Chestnut', 'mare', '5423101', 'AQHA'],
            [$h['hip'], $h['name'], $h['foal_year'], $h['color'], $h['sex'], $h['registration_number'], $h['registry']]);
        // A number the OCR isn't sure of is not kept; "(P)" marks a Paint.
        $page = $this->tsv([$w('12 Sample Paint Lady (P) 12'), $w('2019 Bay Filly'), [['3301234', 71.0]]]);
        $h = TriangleHarvester::ocrHip(TriangleHarvester::ocrLines($page));
        $this->assertSame(['Sample Paint Lady', null, 'APHA'], [$h['name'], $h['registration_number'], $h['registry']]);
        $h = TriangleHarvester::ocrHip(TriangleHarvester::ocrLines($this->tsv([$w('1 Sample Ruby Shoes 1'), $w('January 14, 2021 Red Roan Filly')])));
        $this->assertSame([2021, '2021-01-14', 'Red roan', 'filly'], [$h['foal_year'], $h['birth_date'], $h['color'], $h['sex']]);
        $this->assertNull(TriangleHarvester::ocrHip(TriangleHarvester::ocrLines($this->tsv([$w('Index to Sires'), $w('Sample Sire 1, 2, 3')]))));
    }
}
