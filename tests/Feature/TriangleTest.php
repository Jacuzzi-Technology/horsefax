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
}
