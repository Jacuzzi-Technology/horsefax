<?php

namespace Tests\Feature;

use App\Harvest\PitzerHarvester;
use App\Harvest\PitzerParser;
use App\Harvest\SaleWatch;
use App\Models\DataSource;
use App\Models\SaleEvent;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The sale calendar: harvesters run around sale dates, not weekly (all sales fictional). */
class SaleWatchTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 05:00:00');
        $this->dir = sys_get_temp_dir().'/salewatch-'.uniqid();
        @mkdir($this->dir.'/robinglenn', 0775, true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inventory(array $rows): void
    {
        $f = fopen($this->dir.'/robinglenn/inventory.csv', 'w');
        fputcsv($f, ['cat_id', 'state', 'sale_company', 'sale_name', 'location', 'date_from', 'date_to', 'contact', 'website', 'lots', 'withdrawn', 'with_results', 'with_price', 'with_buyer', 'with_consignor', 'with_foal_year', 'kind']);
        foreach ($rows as $r) {
            fputcsv($f, [$r[0], $r[1], 'example.com', $r[2], 'Sample Arena', $r[3], $r[4], '', '', $r[5] ?? 0, 0, $r[6] ?? 0, 0, 0, 0, 0, 'sale']);
        }
        fclose($f);
    }

    private function watch(?\Closure $runner = null): SaleWatch
    {
        return new SaleWatch(null, $runner, $this->dir.'/state.json', $this->dir.'/robinglenn');
    }

    public function test_check_dates_follow_the_sale(): void
    {
        $cfg = SaleWatch::SOURCES['robinglenn'];
        $e = new SaleEvent(['source' => 'robinglenn', 'key' => '1', 'name' => 'Sample Sale', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-21']);
        // No catalog yet: the next look-ahead date is 30 days before (Oct 21) has passed, so 21 days before.
        $this->assertSame('2026-10-30', SaleWatch::nextCheck($e, $cfg, now())->toDateString());
        $e->catalog_seen_at = now();
        $this->assertSame('2026-11-18', SaleWatch::nextCheck($e, $cfg, now())->toDateString()); // last look 2 days before
        $e->last_checked_at = Carbon::parse('2026-11-18 05:10');
        $this->assertSame('2026-11-22', SaleWatch::nextCheck($e, $cfg, Carbon::parse('2026-11-19'))->toDateString()); // the day after
        $e->last_checked_at = Carbon::parse('2026-12-21');
        $this->assertNull(SaleWatch::nextCheck($e, $cfg, Carbon::parse('2026-12-22'))); // 30 days after: done
        // A source that posts no results: one check 4 days after, to turn catalog lots into "offered".
        $p = new SaleEvent(['source' => 'pitzer', 'key' => 'fall-2026', 'name' => 'Sample', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21', 'catalog_seen_at' => now()]);
        $this->assertSame('2026-10-25', SaleWatch::nextCheck($p, SaleWatch::SOURCES['pitzer'], now())->toDateString());
    }

    public function test_calendar_from_robin_glenn_and_catalog_lots_and_only_due_catalogs_are_reread(): void
    {
        $this->inventory([
            [3001, 'catalog', 'Sample Fall Sale', '2026-10-17', '2026-10-17', 120],       // a week ago: due today (7 days after)
            [3002, 'empty', 'Sample Winter Sale', '2026-12-05', '2026-12-05'],            // catalog not posted: 45 days out, not yet in the window
            [3003, 'catalog', 'Sample Ranch Horses', '2021-01-01', '2031-01-01', 40],     // a standing list, not a sale
            [3004, 'empty', 'Race Private Orders', '2026-10-22', '2026-10-23'],
            [2900, 'catalog', 'Sample Old Sale', '2026-06-01', '2026-06-01', 50],         // long past
        ]);
        $src = DataSource::create(['key' => 'topeka', 'name' => 'Sample', 'url' => 'https://example.com']);
        SaleLot::record($src->id, 'tla:x:1', ['sale_name' => 'Sample Draft Sale', 'sale_date' => '2026-10-26', 'horse_name' => 'Sample Dolly', 'status' => 'catalog', 'source_url' => 'https://example.com/c.pdf']);
        $runs = [];
        $w = $this->watch(function ($argv) use (&$runs) {
            $runs[] = implode(' ', $argv);

            return 0;
        });
        $w->sync();
        $this->assertSame(['3001', '3002'], SaleEvent::where('source', 'robinglenn')->orderBy('key')->pluck('key')->all());
        $this->assertSame('2026-10-24', SaleEvent::where('source', 'robinglenn')->where('key', '3001')->value('next_check_at')->toDateString());
        $this->assertSame('2026-10-24', SaleEvent::where('source', 'topeka')->value('next_check_at')->toDateString()); // 2 days before the sale
        // First run: every source's discovery run is due too; Robin Glenn re-reads only the catalog that is due.
        $w->run();
        $this->assertContains('hf:harvest-robinglenn --delay=3 --cats=3001', $runs);
        $this->assertContains('hf:harvest-topeka --delay=3', $runs);
        $this->assertNotNull(SaleEvent::where('key', '3001')->value('last_checked_at'));
        $this->assertSame('2026-10-31', SaleEvent::where('key', '3001')->value('next_check_at')->toDateString()); // then 14 days after
        // Same day again: nothing due, nothing runs.
        $runs = [];
        $this->watch(function ($argv) use (&$runs) {
            $runs[] = $argv;

            return 0;
        })->run();
        $this->assertSame([], $runs);
    }

    public function test_pitzer_sales_page_dates_and_catalog_links(): void
    {
        $html = '<p>2027 Spring Sale &#8211; April 24th</p><p>2027&nbsp;Fall Sale &#8211; September 10th &amp; 11th</p>'
            .'<a href="https://pitzerranch.net/wp-content/uploads/2026/07/Fall-26-Web-Catalog.pdf">Fall 26</a>'
            .'<a href="https://pitzerranch.net/wp-content/uploads/2026/03/S26-full-pdf-Catalog.pdf">S26</a>';
        $p = PitzerParser::salesPage($html);
        $this->assertSame(['spring-2027' => ['from' => '2027-04-24', 'to' => '2027-04-24'], 'fall-2027' => ['from' => '2027-09-10', 'to' => '2027-09-11']], $p['dates']);
        $this->assertSame(['fall-2026', 'spring-2026'], array_keys($p['pdfs']));
        // A Pitzer sale Robin Glenn already loaded is left to Robin Glenn.
        $rg = DataSource::create(['key' => 'robinglenn', 'name' => 'Sample', 'url' => 'https://example.com']);
        SaleLot::record($rg->id, '2860:1', ['sale_name' => '2026 Pitzer Ranch Spring Sale', 'sale_date' => '2026-04-25', 'horse_name' => 'Sample Buck', 'status' => 'offered', 'source_url' => 'https://example.com/2860']);
        $this->assertTrue(PitzerHarvester::onRobinGlenn('Spring', 2026));
        $this->assertFalse(PitzerHarvester::onRobinGlenn('Fall', 2026));
    }
}
