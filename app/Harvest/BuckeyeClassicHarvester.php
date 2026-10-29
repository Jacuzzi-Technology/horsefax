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

    /**
     * The same company's sales, on the same site software. The Hoosier Classic (Indiana-sired yearlings, Indianapolis)
     * ran to 2024; in 2025 it became the Midwest Standardbred Horse Sale under new owners (not collected here).
     */
    public const SITES = [
        'buckeye' => ['key' => 'buckeyeclassic', 'base' => self::BASE, 'prefix' => 'bcys', 'sale' => 'Buckeye Classic Yearling Sale', 'first' => 2015, 'last' => null,
            'name' => 'Buckeye Classic Yearling Sale (Standardbred)'],
        'hoosier' => ['key' => 'hoosierclassic', 'base' => 'http://hoosierclassicyearlingsale.com', 'prefix' => 'hcys', 'sale' => 'Hoosier Classic Yearling Sale', 'first' => 2013, 'last' => 2024,
            'name' => 'Hoosier Classic Yearling Sale (Standardbred)'],
        // The renamed sale (2025 on): yearling sessions plus a mixed session of horses of any age, so foal years and sex
        // come from the hip pages ("hips/2025/Hip1.pdf"), never assumed from the sale year.
        'midwest' => ['key' => 'midweststandardbred', 'base' => 'https://www.midweststandardbredhorsesale.com', 'prefix' => 'mshs', 'sale' => 'Midwest Standardbred Horse Sale', 'first' => 2025, 'last' => null,
            'name' => 'Midwest Standardbred Horse Sale (formerly Hoosier Classic)', 'mixed' => true, 'hip' => '/hips/%d/Hip%s.pdf'],
        // Hoosier Classic 2010-2012: the old site has only PDFs; the Midwest site keeps them as results pages.
        'hoosier-archive' => ['key' => 'hoosierclassic', 'base' => 'https://www.midweststandardbredhorsesale.com', 'prefix' => 'hcys', 'sale' => 'Hoosier Classic Yearling Sale', 'first' => 2010, 'last' => 2012,
            'name' => 'Hoosier Classic Yearling Sale (Standardbred)'],
        // The 2012 Hoosier Heritage Sale, a separate one-day sale: not assumed to be yearlings only.
        'hoosier-heritage' => ['key' => 'hoosierclassic', 'base' => 'https://www.midweststandardbredhorsesale.com', 'prefix' => 'hhs', 'sale' => 'Hoosier Heritage Sale', 'first' => 2012, 'last' => 2012,
            'name' => 'Hoosier Classic Yearling Sale (Standardbred)', 'mixed' => true, 'results' => '/%d_heritage_sale_results.asp'],
    ];

    private DataSource $source;

    private array $site;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, string $site = 'buckeye')
    {
        $this->delay = max($this->delay, app()->runningUnitTests() ? 0 : 3.0);
        $this->site = self::SITES[$site];
        $this->source = DataSource::firstOrCreate(['key' => $this->site['key']], [
            'name' => $this->site['name'], 'url' => $this->site['base'].'/sale_results.asp', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 28 2026: no robots.txt; no terms of use or copyright notice; no bot protection. Results pages per year since '.$this->site['first'].'. Facts only: consignors and buyers are never stored. Found by Richard (Oct 28 2026).',
        ]);
    }

    public function resultsUrl(int $year): string
    {
        return $this->site['base'].sprintf($this->site['results'] ?? '/%d_sale_results.asp', $year);
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
        // Two-day sales: "Friday, October 18: Selling Hips 1-175" / "Saturday, October 19: Selling Hips 176-350";
        // Midwest: "October 16, 2025 - Selling Hips 1-197 | October 17 - Selling Hips 205-520".
        $days = [];
        if (preg_match_all('/(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:,\s*\d{4})?\s*[:\-–]\s*Selling Hips\s+(\d+)\s*-\s*(\d+)/i', $html, $dm, PREG_SET_ORDER)) {
            foreach ($dm as $x) {
                $days[] = [(int) $x[3], (int) $x[4], date('Y-m-d', strtotime($x[1].' '.$x[2].' '.$year))];
            }
            $date ??= $days[0][2];
        }
        $rows = [];
        preg_match_all('#<tr>\s*<td[^>]*>\s*<font[^>]*><b>(\d{1,4}[A-Z]?)</b>.*?</tr>#is', $html, $trs, PREG_SET_ORDER);
        foreach ($trs as $tr) {
            preg_match_all('#<td[^>]*>(.*?)</td>#is', $tr[0], $tds);
            $cells = array_map(fn ($c) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('&nbsp;', ' ', $c)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $tds[1]);
            if (count($cells) === 8) { // Midwest: Day, Hip, Name, Sire, Dam, Consignor, Buyer, Price (sex and color only on the hip page)
                [, $hip, $horse, $sire, $dam, , , $price] = $cells;
                $horse .= ' ()';
                $parents = $sire.' - '.$dam;
            } elseif (count($cells) >= 6) {
                [$hip, $horse, $parents, , , $price] = $cells;
            } else {
                continue;
            }
            if (! preg_match('/^(.+?)\s*\(([^)]*)\)\s*$/u', $horse, $h)) {
                continue;
            }
            if (preg_match('/^(b|br|blk|bl|ch|gr|ro|dun|pal)\.\s*(c|f|g|h|r|m)\.?$/i', $h[2], $ab)) { // Hoosier: "b.f.", "br.c."
                $color = ['b' => 'Bay', 'br' => 'Brown', 'blk' => 'Black', 'bl' => 'Black', 'ch' => 'Chestnut', 'gr' => 'Gray', 'ro' => 'Roan', 'dun' => 'Dun', 'pal' => 'Palomino'][strtolower($ab[1])];
                $sex = ['c' => 'colt', 'f' => 'filly', 'g' => 'gelding', 'h' => 'stallion', 'r' => 'colt', 'm' => 'mare'][strtolower($ab[2])];
            } else {
                $sex = preg_match('/\b(Colt|Filly|Gelding|Mare|Stallion|Ridgling)\b/i', $h[2], $s) ? strtolower($s[1]) : null;
                $color = trim(preg_replace('/\b(Colt|Filly|Gelding|Mare|Stallion|Ridgling)\b/i', '', $h[2])) ?: null;
            }
            $sex = $sex === 'ridgling' ? 'colt' : $sex;
            [$sire, $dam] = array_pad(array_map('trim', explode(' - ', $parents, 2)), 2, null);
            $cents = preg_match('/^\$\s*([\d,]+)/', $price, $p) ? (int) str_replace(',', '', $p[1]) * 100 : null;
            $status = $cents ? 'sold' : (preg_match('/^(RNA|No ?Sale|NS|Not Sold)/i', $price) ? 'no_sale' : 'withdrawn');
            $day = null;
            foreach ($days as [$from, $to, $d]) {
                $day = (int) $hip >= $from && (int) $hip <= $to ? $d : $day;
            }
            $rows[] = ['hip' => $hip, 'name' => trim($h[1]), 'sex' => $sex, 'color' => $color, 'sire' => $sire ?: null, 'dam' => $dam ?: null, 'status' => $status, 'price_cents' => $cents] + ($day ? ['date' => $day] : []);
        }

        // "Friday, October 29 & Saturday, October 30" (or "Thursday, October 13 - Saturday, October 15") with no hip
        // ranges: the day each hip sold isn't known.
        $twoDays = ! $days && preg_match('/Results[^<]*<br>\s*\w+day,[^<&\-–]*(?:&|-|–|and)\s*\w+day/i', $html); // "... & Saturday", "Thursday ... - Saturday ..." 

        return ['date' => $date, 'rows' => $rows, 'two_days' => (bool) $twoDays];
    }

    /** Fetch (once) and load every year's results. */
    public function run(?array $years = null): array
    {
        $out = ['years' => 0, 'lots' => 0, 'sold' => 0, 'withdrawn' => 0, 'no_sale' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($years ?? range($this->site['first'], $this->site['last'] ?? (int) date('Y')) as $year) {
            $url = $this->resultsUrl($year);
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
                $lot = SaleLot::record($this->source->id, $this->site['prefix'].':'.$year.':'.$r['hip'], [
                    'harvest_page_id' => $this->harvestPage($from)?->id,
                    'sale_name' => $year.' '.$this->site['sale'], 'sale_date' => $r['date'] ?? $sale['date'] ?? $year.'-10-01', 'date_precision' => isset($r['date']) ? 'day' : ($sale['date'] ? (! empty($sale['two_days']) ? 'month' : 'day') : 'year'), 'lot' => $r['hip'],
                    'horse_name' => mb_substr($r['name'], 0, 255), 'foal_year' => empty($this->site['mixed']) ? $year - 1 : null, 'sex' => $r['sex'], 'color' => $r['color'], 'breed' => 'Standardbred',
                    'sire_name' => $r['sire'], 'dam_name' => $r['dam'], 'pedigree' => array_filter(['s' => $r['sire'], 'd' => $r['dam']]) ?: null,
                    'status' => $r['status'], 'price_cents' => $r['price_cents'], 'buyers_premium' => 'unknown', 'source_url' => $from,
                    'note' => $r['status'] === 'withdrawn' ? 'Listed as "Out" in the sale results' : null,
                ]);
                $out['lots']++;
                $out[$r['status']]++;
                $out[RegistrationOnlyMatch::place($lot, $this->site['sale'])]++;
            }
        }

        return $out;
    }

    /**
     * The hip page and the results row name the same horse: the same name, or a near spelling ("CLASSIE GRIL" /
     * "Classie Girl", "SWARTZ BROS RALLY" / "Schwartz Bros Rally") with the same sire. The results spelling stays on the lot.
     */
    public static function sameHorse(array $h, SaleLot $lot): bool
    {
        if (TopekaParser::sameName($h['name'], $lot->horse_name)) {
            return true;
        }
        $k = fn ($n) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $n));
        [$a, $b] = [$k($h['name']), $k($lot->horse_name)];
        similar_text($a, $b, $pct);

        return $a !== '' && $pct >= 80 && $lot->sire_name && isset($h['pedigree']['s']) && $k($h['pedigree']['s']) === $k($lot->sire_name);
    }

    /** @var array<int, array<string, array>> year => printed hip => parsed page */
    private array $printed = [];

    /** Every kept hip page of a year, keyed by the hip number printed on it. */
    private function printedHips(int $year): array
    {
        if (! isset($this->printed[$year])) {
            $this->printed[$year] = [];
            foreach (glob(dirname($this->hipPath($year, '0')).'/*.pdf') as $f) {
                $h = self::parseHip(file_get_contents($f));
                if ($h['hip'] !== null) {
                    $this->printed[$year][$h['hip']] = $h;
                }
            }
        }

        return $this->printed[$year];
    }

    public const FIRST_HIP_YEAR = 2020; // hip pages before 2020 are gone (404)

    /** Shown beside a microchip read from a printed catalog: "Microchip (Sale catalog)". Never a scan. */
    public const CATALOG_CHIP = 'Sale catalog';

    public function hipUrl(int $year, string $hip): string
    {
        return $this->site['base'].sprintf($this->site['hip'] ?? '/hips/%d/%s.pdf', $year, $hip);
    }

    private function hipPath(int $year, string $hip): string
    {
        return storage_path('app/private/'.($this->site['key'] === 'buckeyeclassic' ? 'buckeye' : $this->site['key']).'/hips/'.$year.'/'.$hip.'.pdf');
    }

    /**
     * A hip's catalog page: "Bay Colt - Foaled April 19, 2025 - Registration #62R55" (2020: "Freeze Brand #8T394", the
     * same USTA identifier), "Microchip #985141008602332", and the three-generation tree, the yearling in the middle,
     * race marks after each name ("Sweet Lou p,1:47.0f ......"). The dam's produce record below the tree ("1st dam ...")
     * is not read.
     *
     * @return array{name:?string, birth_date:?string, registration_number:?string, microchip:?string, pedigree:array<string,string>}
     */
    public static function parseHip(string $pdf): array
    {
        $pages = LolliParser::words($pdf);
        $segs = $pages ? LolliParser::segments($pages[0]) : [];
        $out = ['hip' => null, 'name' => null, 'birth_date' => null, 'registration_number' => null, 'microchip' => null, 'sex' => null, 'color' => null, 'pedigree' => []];
        $idLine = null;
        $stop = INF;
        foreach ($segs as $s) {
            if ($idLine === null && preg_match('/Foaled\s+([A-Z][a-z]+ \d{1,2}, (?:19|20)\d\d)(?:\s*-\s*(?:Registration|Freeze Brand)\s*#\s*([0-9A-Z]{4,8}))?/', $s['t'], $m)) {
                $idLine = $s;
                $out['birth_date'] = date('Y-m-d', strtotime($m[1]));
                $out['registration_number'] = $m[2] ?? null;
                if (preg_match('/^(.*?)\s+(Colt|Filly|Gelding|Mare|Stallion|Horse|Ridgling)\s*-\s*Foaled/i', $s['t'], $sc)) { // "Bay Colt - Foaled ..."
                    [$out['color'], $out['sex']] = [ucwords(strtolower(trim($sc[1]))) ?: null, ['horse' => 'stallion', 'ridgling' => 'colt'][strtolower($sc[2])] ?? strtolower($sc[2])];
                }
            }
            if ($out['microchip'] === null && preg_match('/Microchip\s*#\s*(\d{9,15})\b/i', $s['t'], $mc)) {
                $out['microchip'] = $mc[1];
            }
            if (preg_match('/^1st dam\b/i', $s['t'])) {
                $stop = min($stop, $s['y']);
            }
        }
        if (! $idLine) {
            return $out;
        }
        // The name: the largest run above the foaled line ("(OHIO ELIGIBLE)" and the consignor sit there too).
        $best = 0;
        foreach ($segs as $s) {
            if ($s['y'] < $idLine['y'] - 8 && $s['h'] > $best && ! preg_match('/Consigned|Aisle|Annex|ELIGIBLE|^\d+$/i', $s['t'])) {
                [$out['name'], $best] = [LolliParser::cleanName($s['t']), $s['h']];
            }
        }
        // The hip number printed beside the name (left or right of it): files aren't always numbered by hip.
        $nameSeg = null;
        foreach ($segs as $s) {
            if ($out['name'] && LolliParser::cleanName($s['t']) === $out['name'] && $s['y'] < $idLine['y']) {
                $nameSeg = $s;
            }
        }
        foreach ($nameSeg ? $segs : [] as $s) {
            if (preg_match('/^(\d{1,4})$/', $s['t'], $hm) && abs($s['yc'] - $nameSeg['yc']) < 12) {
                $out['hip'] = $hm[1];
            }
        }
        $below = $idLine['y'] + 12; // past the microchip line
        $tree = [];
        foreach ($segs as $s) {
            if ($s['y'] > $below && $s['y'] < $stop) {
                // "Lous Pearlman p, 3, 1:47.4 ......" -> "Lous Pearlman"; "Cambest p,T1:46.1" -> "Cambest"
                $s['t'] = trim(preg_replace('/\s*(\.{2,}.*|\s(p|t),?\s*.*|\s[QT]?\d:\d\d.*|\s\d,\s*.*)$/u', '', $s['t']));
                if ($s['t'] !== '') {
                    $tree[] = $s;
                }
            }
        }
        if ($out['name']) {
            $out['pedigree'] = LolliParser::pedigree(array_values(array_filter($tree, fn ($s) => LolliParser::cleanName($s['t']) !== $out['name'])), $below, $out['name']);
        }

        return $out;
    }

    /**
     * Read each 2020+ lot's hip page (fetched once, kept under storage/app/private/buckeye/hips) and add what the
     * results page lacks: foaling date, USTA registration number, the three-generation pedigree. Only when the page
     * names the same horse. The lot's record (if any) gets what it's missing (AddPublishedIdentity), and the printed
     * microchip (Richard, Oct 28 2026: "store microchips as catalog-printed"): kind microchip, registry "Sale catalog",
     * unverified, historical, private and masked like every microchip, never on a record someone looks after; the same
     * number on another record goes to staff as a conflict (AddIdentifier). A lot still without a record is placed
     * again, now with its registration number.
     */
    public function enrich(?int $year = null, ?int $max = null): array
    {
        $out = ['lots' => 0, 'fetched' => 0, 'missing' => 0, 'other_name' => 0, 'with_reg' => 0, 'pedigrees' => 0, 'records_filled' => 0, 'microchips' => 0, 'placed' => []];
        $lots = SaleLot::where('data_source_id', $this->source->id)->where('sale_year', '>=', self::FIRST_HIP_YEAR)
            ->when($year, fn ($q) => $q->where('sale_year', $year))->orderBy('sale_year')->orderByRaw('cast(lot as integer)')->get();
        $system = \App\Actions\Record\BuildRecordsFromResults::system();
        foreach ($lots as $lot) {
            if ($max !== null && $out['fetched'] >= $max) {
                break;
            }
            $path = $this->hipPath($lot->sale_year, $lot->lot);
            if (! is_file($path)) {
                $r = $this->fetch($this->hipUrl($lot->sale_year, $lot->lot));
                if ($r && in_array($r->status(), [403, 429], true)) {
                    throw new HarvestBlocked('Blocked or rate-limited at '.$this->hipUrl($lot->sale_year, $lot->lot).': stopping. No workaround.');
                }
                if (! $r || ! $r->successful() || ! str_starts_with($r->body(), '%PDF')) {
                    $out['missing']++;
                    continue;
                }
                @mkdir(dirname($path), 0775, true);
                file_put_contents($path, $r->body());
                $out['fetched']++;
            }
            $out['lots']++;
            $h = self::parseHip(file_get_contents($path));
            if ($h['hip'] !== null && $h['hip'] !== (string) $lot->lot) {
                // This file holds another hip (2026 Midwest session 2 numbers its files straight through): use the
                // kept page that prints this lot's hip, if any.
                $h = $this->printedHips($lot->sale_year)[(string) $lot->lot] ?? null;
                if (! $h) {
                    $out['other_name']++;
                    continue;
                }
            }
            if (! $h['name'] || ! self::sameHorse($h, $lot)) {
                $out['other_name']++;
                continue;
            }
            $lot->forceFill(array_filter([
                'birth_date' => $h['birth_date'], 'registration_number' => $h['registration_number'], 'registry' => $h['registration_number'] ? 'USTA' : null,
                'registered' => $h['registration_number'] ? true : null,
                'foal_year' => $lot->foal_year || ! $h['birth_date'] ? null : (int) substr($h['birth_date'], 0, 4),
                'sex' => $lot->sex ? null : $h['sex'], 'color' => $lot->color ? null : $h['color'],
                'pedigree' => $h['pedigree'] ?: null, 'dam_sire_name' => $h['pedigree']['ds'] ?? null,
                'source_url' => $this->hipUrl($lot->sale_year, $lot->lot),
            ], fn ($v) => $v !== null))->save();
            $out['with_reg'] += $h['registration_number'] ? 1 : 0;
            $out['pedigrees'] += $h['pedigree'] ? 1 : 0;
            if ($lot->horse) {
                $filled = app(\App\Actions\Record\AddPublishedIdentity::class)($lot->horse, $system, [
                    'registry' => $lot->registry, 'registration_number' => $lot->registration_number, 'foal_year' => $lot->foal_year,
                    'pedigree' => $lot->pedigree ?: [],
                ], 'sale lot '.$lot->id);
                $out['records_filled'] += $filled ? 1 : 0;
                if ($h['microchip'] && ! $lot->horse->activeStewards()->exists()
                    && ! $lot->horse->identifiers()->where('kind', 'microchip')->where('value_normalized', \App\Support\Record::normalizeIdentifier($h['microchip']))->exists()) {
                    app(\App\Actions\Record\AddIdentifier::class)($lot->horse, $system, ['kind' => 'microchip', 'registry' => self::CATALOG_CHIP,
                        'value' => $h['microchip'], 'visibility' => 'private', 'source_type' => 'historical']);
                    $out['microchips']++;
                }
            } else {
                $p = RegistrationOnlyMatch::place($lot, $this->site['sale']);
                $out['placed'][$p] = ($out['placed'][$p] ?? 0) + 1;
            }
        }

        return $out;
    }
}
