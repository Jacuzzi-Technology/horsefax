<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\SaleLot;

/**
 * Premier Horse Sales (premierhorsesales.com, MM Auction Services). Approved by Richard, Oct 17 2026: robots.txt
 * keeps out only form uploads and cart links; no site terms posted; no bot wall. Only the public website is read:
 * the bid site's /api/ is disallowed by its robots.txt and is never used.
 *
 *  - Past sales: /past-sale-results/ -> each sale's results page -> its "Full Sale Results" PDF (lot, horse, price
 *    or No Sale). Premier publishes no-sales by name, so a lot without a price is reported as no sale.
 *  - The coming sale: the catalogued horses (post-sitemap.xml) with registered name, sex, breed, color, year born and
 *    a three-generation pedigree. They're kept as catalog lots; when that sale's results PDF appears, each result is
 *    joined to its catalog lot by lot number and name, which gives the result a pedigree to match on.
 * Consignor names are in both and are never stored.
 */
class PremierHarvester
{
    use PoliteFetch;

    public const BASE = 'https://www.premierhorsesales.com';

    private DataSource $source;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'premier'], [
            'name' => 'Premier Horse Sales', 'url' => self::BASE.'/past-sale-results/', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 17 2026: robots.txt disallows only wpforms uploads and add-to-cart links; no website terms posted; no bot protection. The bid site (bid.premieronlinesales.com) disallows /api/ and is not used. Richard approved collecting sale results (Oct 17 2026).',
        ]);
    }

    /** Returns [catalog horses read, sales read, result lots stored]. */
    public function run(?int $maxSales = null): array
    {
        $catalog = $this->catalog();
        $sales = 0;
        $lots = 0;
        foreach ($this->salePages() as $url) {
            if ($maxSales !== null && $sales >= $maxSales) {
                break;
            }
            $n = $this->sale($url);
            if ($n !== null) {
                $sales++;
                $lots += $n;
            }
        }

        return [$catalog, $sales, $lots];
    }

    /** @return list<string> each past sale's results page */
    public function salePages(): array
    {
        $body = $this->fetch(self::BASE.'/past-sale-results/')?->body() ?? '';
        preg_match_all('#href="('.preg_quote(self::BASE, '#').'/(20\d\d-[a-z0-9-]+)/)"#', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** One past sale. Null when there's nothing new (already read, or no results PDF yet). */
    public function sale(string $url): ?int
    {
        if (in_array(HarvestPage::where('url', $url)->value('status'), ['parsed', 'skipped'], true)) {
            return null;
        }
        $html = $this->page($url, 'index');
        if ($html === null) {
            return null;
        }
        $index = $this->harvestPage($url);
        if (! preg_match('#href="([^"]+\.pdf)"[^>]*>[^<]*(full|sale)[^<]*result#i', $html, $m)
            && ! preg_match('#href="([^"]*/wp-content/uploads/[^"]*result[^"]*\.pdf)"#i', $html, $m)) {
            $index->fill(['status' => 'skipped', 'error' => 'No full results PDF on the page'])->save();

            return null;
        }
        $pdfUrl = html_entity_decode($m[1]);
        $slug = trim(parse_url($url, PHP_URL_PATH), '/');
        $year = (int) substr($slug, 0, 4);
        $pdf = $this->page($pdfUrl, 'class', $year, 'pdf');
        if ($pdf === null) {
            return null;
        }
        $rows = PremierParser::results(PremierParser::words($pdf));
        $saleName = PremierParser::saleName($slug);
        // The results page is posted right after the sale: its publish month stands in for the sale date.
        $posted = preg_match('/article:published_time" content="(\d{4}-\d{2}-\d{2})/', $html, $p) ? $p[1] : null;
        $date = $posted && (int) substr($posted, 0, 4) === $year ? $posted : $year.'-01-01';
        $pdfPage = $this->harvestPage($pdfUrl);

        foreach ($rows as $row) {
            $name = NrchaHarvester::display($row['horse']);
            $values = [
                'harvest_page_id' => $pdfPage?->id, 'sale_name' => $saleName, 'sale_date' => $date, 'sale_year' => $year,
                'date_precision' => $posted ? 'month' : 'year', 'lot' => $row['lot'] ?: null, 'horse_name' => $name,
                'status' => $row['sold'] ? 'sold' : 'no_sale', 'price_cents' => $row['sold'] ? $row['price_cents'] : null,
                'source_url' => $pdfUrl,
            ];
            $catalogLot = $row['lot'] ? SaleLot::where('data_source_id', $this->source->id)->where('status', 'catalog')
                ->where('lot', $row['lot'])->where('name_key', \App\Models\ShowRecord::key($name))
                ->whereBetween('sale_year', [$year - 1, $year])->first() : null;
            if ($catalogLot) {
                $catalogLot->fill($values + ['note' => 'Catalog details from '.$catalogLot->source_url])->save();
            } else {
                SaleLot::record($this->source->id, 'result:'.$slug.':'.($row['lot'] ?: \App\Models\ShowRecord::key($name)), $values);
            }
        }
        $pdfPage?->fill(['status' => 'parsed', 'rows' => count($rows)])->save();
        $index->fill(['status' => 'parsed', 'rows' => count($rows), 'year' => $year])->save();
        $this->say("$saleName: ".count($rows).' lots');

        return count($rows);
    }

    /** The catalogued horses for the coming sale. Each horse page is read once. Returns horses stored. */
    public function catalog(): int
    {
        $xml = $this->fetch(self::BASE.'/post-sitemap.xml')?->body() ?? '';
        preg_match_all('#<loc>\s*('.preg_quote(self::BASE, '#').'/[a-z0-9-]+/)\s*</loc>#', $xml, $m);
        $n = 0;
        foreach ($m[1] as $url) {
            $known = HarvestPage::where('url', $url)->value('status');
            if (in_array($known, ['parsed', 'skipped'], true)) {
                continue;
            }
            $html = $this->page($url, 'lot', (int) now()->format('Y'));
            $horse = $html ? PremierParser::catalogHorse($html) : null;
            $page = $this->harvestPage($url);
            if (! $horse || ! $horse['lot']) {
                $page?->fill(['status' => 'skipped', 'error' => 'Not a catalogued horse'])->save();
                continue;
            }
            $sale = preg_match('/<a[^>]*rel="category tag"[^>]*>([^<]+)</', $html, $c) ? trim(html_entity_decode($c[1])) : 'coming sale';
            SaleLot::record($this->source->id, 'catalog:'.trim(parse_url($url, PHP_URL_PATH), '/'), [
                'harvest_page_id' => $page?->id, 'sale_name' => 'Premier Horse Sales '.now()->format('Y').' '.$sale.' (catalog)',
                'sale_date' => null, 'sale_year' => (int) now()->format('Y'), 'lot' => $horse['lot'],
                'horse_name' => NrchaHarvester::display($horse['name']), 'registered' => $horse['registry'] ? true : null,
                'registry' => LiveAgParser::registry($horse['registry'] ?? $horse['breed']), 'foal_year' => $horse['foal_year'],
                'sex' => $horse['sex'] ? ucfirst(strtolower($horse['sex'])) : null, 'color' => $horse['color'], 'breed' => $horse['breed'],
                'sire_name' => $horse['pedigree']['s'] ?? null, 'dam_name' => $horse['pedigree']['d'] ?? null,
                'dam_sire_name' => $horse['pedigree']['ds'] ?? null, 'pedigree' => $horse['pedigree'] ?: null,
                'status' => 'catalog', 'source_url' => $url,
            ]);
            $page?->fill(['status' => 'parsed', 'rows' => 1])->save();
            $n++;
        }

        return $n;
    }
}
