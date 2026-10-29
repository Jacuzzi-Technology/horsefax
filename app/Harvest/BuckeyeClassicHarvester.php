<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;

/**
 * Buckeye Classic Yearling Sale (buckeyeclassicsale.com; Hoosier Classic Sale Company, Springfield OH), Ohio-sired
 * Standardbred yearlings, found by Richard Oct 28 2026. No robots.txt (404), no terms of use or copyright notice, no bot
 * wall. One results page per sale since 2015 ("2025_sale_results.asp"): hip, name, sex and color, sire - dam,
 * consignor, buyer, price ("Out" when the yearling was withdrawn). Facts only: consignors and buyers are never stored.
 * Every horse is a yearling, so its foal year is the year before the sale.
 */
class BuckeyeClassicHarvester
{
    use PoliteFetch;

    public const BASE = 'http://buckeyeclassicsale.com';
    public const FIRST_YEAR = 2015;

    private DataSource $source;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->delay = max($this->delay, app()->runningUnitTests() ? 0 : 3.0);
        $this->source = DataSource::firstOrCreate(['key' => 'buckeyeclassic'], [
            'name' => 'Buckeye Classic Yearling Sale (Standardbred)', 'url' => self::BASE.'/sale_results.asp', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 28 2026: no robots.txt (404); no terms of use or copyright notice; no bot protection. Results pages per year since 2015. Facts only: consignors and buyers are never stored. Found by Richard (Oct 28 2026).',
        ]);
    }

    public static function resultsUrl(int $year): string
    {
        return self::BASE.'/'.$year.'_sale_results.asp';
    }

    /**
     * A results page -> sale date and rows.
     *
     * @return array{date:?string, rows:list<array{hip:string, name:string, sex:?string, color:?string, sire:?string, dam:?string, status:string, price_cents:?int}>}
     */
    public static function parse(string $html, int $year): array
    {
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8, Windows-1252');
        $date = null;
        if (preg_match('/Results[^<]*<br>\s*\w+day,\s*(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})/i', $html, $d)) {
            $date = date('Y-m-d', strtotime($d[1].' '.$d[2].' '.$year));
        }
        $rows = [];
        preg_match_all('#<tr>\s*<td[^>]*>\s*<font[^>]*><b>(\d{1,4}[A-Z]?)</b>.*?</tr>#is', $html, $trs, PREG_SET_ORDER);
        foreach ($trs as $tr) {
            preg_match_all('#<td[^>]*>(.*?)</td>#is', $tr[0], $tds);
            $cells = array_map(fn ($c) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('&nbsp;', ' ', $c)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $tds[1]);
            if (count($cells) < 6) {
                continue;
            }
            [$hip, $horse, $parents, , , $price] = $cells;
            if (! preg_match('/^(.+?)\s*\(([^)]*)\)\s*$/u', $horse, $h)) {
                continue;
            }
            $sex = preg_match('/\b(Colt|Filly|Gelding|Mare|Stallion|Ridgling)\b/i', $h[2], $s) ? strtolower($s[1]) : null;
            $color = trim(preg_replace('/\b(Colt|Filly|Gelding|Mare|Stallion|Ridgling)\b/i', '', $h[2])) ?: null;
            [$sire, $dam] = array_pad(array_map('trim', explode(' - ', $parents, 2)), 2, null);
            $cents = preg_match('/^\$\s*([\d,]+)/', $price, $p) ? (int) str_replace(',', '', $p[1]) * 100 : null;
            $status = $cents ? 'sold' : (preg_match('/^(RNA|No ?Sale|NS|Not Sold)/i', $price) ? 'no_sale' : 'withdrawn');
            $rows[] = ['hip' => $hip, 'name' => trim($h[1]), 'sex' => $sex, 'color' => $color, 'sire' => $sire ?: null, 'dam' => $dam ?: null, 'status' => $status, 'price_cents' => $cents];
        }

        return ['date' => $date, 'rows' => $rows];
    }

    /** Fetch (once) and load every year's results. */
    public function run(?array $years = null): array
    {
        $out = ['years' => 0, 'lots' => 0, 'sold' => 0, 'withdrawn' => 0, 'no_sale' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($years ?? range(self::FIRST_YEAR, (int) date('Y')) as $year) {
            $url = self::resultsUrl($year);
            $html = $this->page($url, 'results', $year);
            if ($html === null) {
                if (in_array($this->harvestPage($url)?->http_status, [403, 429], true)) {
                    throw new HarvestBlocked("Blocked or rate-limited at $url: stopping. No workaround.");
                }
                continue;
            }
            $sale = self::parse($html, $year);
            // Most years show 25 hips a page, with "?offset=25" links to the rest.
            preg_match_all('/href="'.$year.'_sale_results\.asp\?offset=(\d+)"/i', $html, $off);
            foreach (array_unique(array_map('intval', $off[1])) as $o) {
                if ($o > 0 && ($more = $this->page($url.'?offset='.$o, 'results', $year)) !== null) {
                    $sale['rows'] = array_merge($sale['rows'], array_map(fn ($r) => $r + ['url' => $url.'?offset='.$o], self::parse($more, $year)['rows']));
                }
            }
            if (! $sale['rows']) {
                continue;
            }
            $out['years']++;
            foreach ($sale['rows'] as $r) {
                $from = $r['url'] ?? $url;
                $lot = SaleLot::record($this->source->id, 'bcys:'.$year.':'.$r['hip'], [
                    'harvest_page_id' => $this->harvestPage($from)?->id,
                    'sale_name' => $year.' Buckeye Classic Yearling Sale', 'sale_date' => $sale['date'] ?? $year.'-10-01', 'date_precision' => $sale['date'] ? 'day' : 'year', 'lot' => $r['hip'],
                    'horse_name' => mb_substr($r['name'], 0, 255), 'foal_year' => $year - 1, 'sex' => $r['sex'], 'color' => $r['color'], 'breed' => 'Standardbred',
                    'sire_name' => $r['sire'], 'dam_name' => $r['dam'], 'pedigree' => array_filter(['s' => $r['sire'], 'd' => $r['dam']]) ?: null,
                    'status' => $r['status'], 'price_cents' => $r['price_cents'], 'buyers_premium' => 'unknown', 'source_url' => $from,
                    'note' => $r['status'] === 'withdrawn' ? 'Listed as "Out" in the sale results' : null,
                ]);
                $out['lots']++;
                $out[$r['status']]++;
                $out[RegistrationOnlyMatch::place($lot, 'Buckeye Classic')]++;
            }
        }

        return $out;
    }
}
