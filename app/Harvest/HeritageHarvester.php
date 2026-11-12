<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Heritage Livestock horse sales (heritagelivestock.com; Raucher family, Mount Vernon MO), Richard's decision
 * Oct 22 2026: "Import everything". Wix site, robots.txt allows everything, no terms of use posted, no bot wall.
 *
 * Catalog PDFs live at /_files/ugd/1b95cd_<32 hex>.pdf and stay up after the site unlinks them. They are found on the
 * site's pages (sitemap.xml plus the sale and results pages) and in the Internet Archive's list of files it has seen
 * under /_files/ugd/ (the CDX API). Each is read from Heritage's own server; the Archive copy only if it's gone.
 * Consignment forms and the "Riders for Hire" list are not catalogs and are skipped (fewer than 5 hips).
 *
 * Which sale a catalog belongs to: the sale day on its cover when there is one, else the results-page sale whose
 * top-seller hips and names agree with the catalog, else the first sale on or after the catalog's newest QData stamp
 * month. Prices come from the results pages (top sellers only) and attach to the same sale's hip. Everything else
 * catalogued from a past sale is "offered" (Heritage publishes no other results).
 *
 * Facts only (name, registration number, foal year, color, sex, pedigree, hip, sale). The NOTES text, phone numbers
 * and photos are never kept, nor consignor names (house rule; the kept PDFs themselves still print them).
 * One request at a time, $delay seconds apart (at least 3), as HorsefaxBot; a 403/429 or a bot check stops the run.
 */
class HeritageHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://www.heritagelivestock.com';
    public const UGD = self::BASE.'/_files/ugd/';
    /** Sale and results pages read even when the sitemap leaves them out. */
    public const PAGES = ['/', '/horse-auctions-schedule', '/2021-catalog-sales', '/2022-catalog-sales', '/2023-sale-results', '/2024-sale-results',
        '/copy-of-2024-sale-results', '/winter-select-sale', '/april-parsons-26', '/26-summer-parsons', '/october-26-luckyj', '/26-fall-parsons'];
    public const CSV = ['catalog_file', 'sale_date', 'sale_name', 'location', 'date_basis', 'hip', 'name', 'grade', 'registry', 'registration_number',
        'foal_year', 'birth_date', 'color', 'sex', 'sire', 'dam', 'dam_sire', 'pedigree', 'status', 'price_cents', 'price_source', 'has_consignor', 'source_url', 'page'];

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY); // house rule: at least 3 s between requests (faked HTTP in tests)
        }
        $this->dir = $dir ?? storage_path('app/private/heritage');
        @mkdir($this->dir.'/pdf', 0775, true);
        @mkdir($this->dir.'/pages', 0775, true);
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'heritage'], [
            'name' => 'Heritage Livestock horse sales', 'url' => self::BASE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 22 2026: robots.txt allows everything; no terms of use posted; no bot protection. Catalog PDFs (made by Robin Glenn QData) and the results pages (top sellers only); earlier PDF names from the Internet Archive, read from Heritage\'s own server. Facts only with a link back; notes text, photos and consignor names are never stored (the kept PDFs still print them). Richard approved (Oct 22 2026).',
        ]);
    }

    /** @return array<string, int> */
    public function collect(bool $archive = true, ?string $seedDir = null): array
    {
        $out = ['pages' => 0, 'pdfs_found' => 0, 'pdfs_downloaded' => 0, 'pdfs_cached' => 0, 'pdfs_unavailable' => 0];
        if ($seedDir && is_dir($seedDir)) {
            foreach (glob(rtrim($seedDir, '/').'/1b95cd_*.pdf') as $f) {
                if (! is_file($this->dir.'/pdf/'.basename($f))) {
                    copy($f, $this->dir.'/pdf/'.basename($f));
                }
            }
        }
        $pages = self::PAGES;
        $sitemap = $this->get(self::BASE.'/sitemap.xml');
        foreach ($this->sitemapUrls($sitemap ?? '') as $u) {
            $pages[] = parse_url($u, PHP_URL_PATH) ?: '/';
        }
        $pdfs = [];
        foreach (array_unique($pages) as $p) {
            $html = $this->get(self::BASE.$p);
            if ($html === null) {
                continue;
            }
            $out['pages']++;
            file_put_contents($this->dir.'/pages/'.(str_replace('/', '__', trim($p, '/')) ?: 'home').'.html.gz', gzencode($html, 9));
            preg_match_all('#1b95cd_[0-9a-f]{32}\.pdf#', $html, $m);
            foreach ($m[0] as $f) {
                $pdfs[$f] = ['file' => $f, 'linked_from' => $p, 'archived' => null];
            }
        }
        if ($archive) {
            $cdx = $this->get('https://web.archive.org/cdx/search/cdx?url=heritagelivestock.com/_files/ugd/*&output=json&fl=original,timestamp&collapse=urlkey&limit=5000');
            foreach (array_slice(json_decode((string) $cdx, true) ?: [], 1) as [$original, $ts]) {
                if (preg_match('#(1b95cd_[0-9a-f]{32}\.pdf)#', $original, $m)) {
                    $pdfs[$m[1]] ??= ['file' => $m[1], 'linked_from' => null, 'archived' => null];
                    $pdfs[$m[1]]['archived'] = 'https://web.archive.org/web/'.$ts.'id_/'.preg_replace('#^http:#', 'https:', $original);
                }
            }
        }
        $out['pdfs_found'] = count($pdfs);
        foreach ($pdfs as $f => $info) {
            $path = $this->dir.'/pdf/'.$f;
            if (is_file($path) && filesize($path) > 0) {
                $out['pdfs_cached']++;
                continue;
            }
            $body = $this->get(self::UGD.$f);
            if ($body === null && $info['archived']) {
                $body = $this->get($info['archived']);
            }
            if ($body === null || ! str_starts_with($body, '%PDF')) {
                $out['pdfs_unavailable']++;
                $this->say("unavailable: $f");
                continue;
            }
            file_put_contents($path, $body);
            $out['pdfs_downloaded']++;
        }
        file_put_contents($this->dir.'/pdf-index.json', json_encode(array_values($pdfs), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    /** @return list<string> */
    private function sitemapUrls(string $xml): array
    {
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $xml, $m);
        $urls = [];
        foreach ($m[1] as $u) {
            if (str_contains($u, 'store-products') || str_contains($u, '/product-page/')) {
                continue; // the tack store, not sales
            }
            if (str_ends_with($u, '.xml')) {
                foreach ($this->sitemapUrls($this->get(html_entity_decode($u)) ?? '') as $x) {
                    $urls[] = $x;
                }
            } elseif (str_contains($u, 'heritagelivestock.com')) {
                $urls[] = html_entity_decode($u);
            }
        }

        return $urls;
    }

    private function get(string $url): ?string
    {
        for ($attempt = 0, $backoff = 30; ; $attempt++, $backoff *= 2) {
            $wait = $this->delay - (microtime(true) - $this->lastRequest);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $this->lastRequest = microtime(true);
            $this->requests++;
            try {
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(120)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            $this->say($status.' '.$url);
            // A real bot check (Wix pages mention reCAPTCHA and Cloudflare's script path in normal use, so those don't count).
            $challenge = ! str_starts_with($body, '%PDF') && preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!)|cf-chl-|id="challenge-form"/i', substr($body, 0, 20000));
            if ($status === 200 && ! $challenge) {
                return $body;
            }
            if (in_array($status, [403, 429], true) || ($status === 200 && $challenge)) {
                throw new HarvestBlocked("Blocked or rate-limited at $url (HTTP $status): stopping. No workaround.");
            }
            if ($status === 404 || $attempt >= 3) {
                return null;
            }
            sleep($backoff);
        }
    }

    /** Every sale on the kept results/sale pages, newest first, de-duplicated by day. */
    public function sales(): array
    {
        $sales = [];
        foreach (glob($this->dir.'/pages/*.html.gz') as $f) {
            foreach (HeritageParser::results(gzdecode(file_get_contents($f))) as $s) {
                $page = self::BASE.'/'.str_replace('__', '/', preg_replace('/^home$/', '', basename($f, '.html.gz')));
                $s['page'] = $page;
                $k = $s['date'];
                // The same sale can appear on two pages; keep the one with the most prices.
                if (! isset($sales[$k]) || count($s['lots']) > count($sales[$k]['lots'])) {
                    $sales[$k] = $s;
                }
            }
        }
        krsort($sales);

        return $sales;
    }

    /** Parse every kept PDF: list of catalogs, each with its sale and hips (best reading per hip). */
    public function catalogs(): array
    {
        $sales = $this->sales();
        $out = [];
        foreach (glob($this->dir.'/pdf/1b95cd_*.pdf') as $f) {
            $pdf = file_get_contents($f);
            $words = PremierParser::words($pdf);
            $hips = HeritageParser::catalog($words);
            if (count($hips) < 5) {
                $out[] = ['file' => basename($f), 'catalog' => false, 'hips' => []];
                continue;
            }
            $text = StatePdfHarvester::text($pdf);
            [$sale, $basis] = $this->saleFor($text, $hips, $sales);
            $out[] = ['file' => basename($f), 'catalog' => true, 'sale' => $sale, 'date_basis' => $basis, 'hips' => self::bestPerHip($hips), 'readings' => count($hips)];
        }

        return $out;
    }

    /** Overlaid pages can yield a hip twice: keep the fullest reading. */
    public static function bestPerHip(array $hips): array
    {
        $best = [];
        foreach ($hips as $h) {
            $h['name'] = self::cleanName($h['name']);
            $score = ($h['name'] ? 4 : -10) + ($h['reg'] ? 2 : 0) + count($h['pedigree']) / 14 + ($h['foal_year'] ? 1 : 0) + ($h['sex'] ? 1 : 0);
            if (! isset($best[$h['hip']]) || $score > $best[$h['hip']]['_score']) {
                $best[$h['hip']] = $h + ['_score' => $score];
            }
        }
        uksort($best, fn ($a, $b) => (int) $a <=> (int) $b ?: strcmp($a, $b));

        return array_values(array_map(fn ($h) => array_diff_key($h, ['_score' => 1]), array_filter($best, fn ($h) => $h['name'] !== null)));
    }

    /** A name as printed, or null when the reading caught prose instead. */
    public static function cleanName(?string $n): ?string
    {
        $n = trim((string) $n);
        if ($n === '' || mb_strlen($n) > 50 || str_word_count($n) > 8 || preg_match('/Hip No\.|Consigned|NOTES|QData|^\d{4}\s/i', $n)) {
            return null;
        }

        return $n;
    }

    /** "Maybe I Will (ApHC)" -> ["Maybe I Will", "ApHC"]; the catalogs mark only non-AQHA horses. */
    public static function registryOf(string $name, bool $hasNumber): array
    {
        $map = ['P' => 'APHA', 'APHA' => 'APHA', 'PTHA' => 'PtHA', 'APHC' => 'ApHC', 'AP' => 'ApHC', 'Q' => 'AQHA', 'AQHA' => 'AQHA', 'PHBA' => 'PHBA', 'ABRA' => 'ABRA', 'IBHA' => 'IBHA', 'TB' => 'JC', 'JC' => 'JC'];
        if (preg_match('/^(.*?)\s*\(\s*([A-Za-z]{1,5})\s*\)\s*$/', $name, $m) && isset($map[strtoupper($m[2])])) {
            return [trim($m[1]), $map[strtoupper($m[2])]];
        }

        return [$name, $hasNumber ? 'AQHA' : null];
    }

    /** [sale, basis] for a catalog. */
    private function saleFor(string $text, array $hips, array $sales): array
    {
        $cover = substr($text, 0, (int) (strpos($text, 'Hip No.') ?: 6000));
        $date = HeritageParser::coverDate($cover);
        if ($date) {
            foreach ($sales as $s) {
                if (abs(strtotime($s['date']) - strtotime($date)) <= 2 * 86400) {
                    return [$s, 'cover date + results page'];
                }
            }

            return [['date' => $date, 'title' => null, 'venue' => null, 'city' => self::coverCity($cover), 'lots' => [], 'page' => null], 'cover date'];
        }
        // Agreement with each sale's published top sellers (same hip, same horse).
        $byHip = [];
        foreach ($hips as $h) {
            $byHip[$h['hip']][] = $h['name'];
        }
        $best = null;
        $bestScore = 0;
        foreach ($sales as $s) {
            $score = 0;
            foreach ($s['lots'] as $l) {
                foreach ($byHip[$l['hip']] ?? [] as $n) {
                    $score += HeritageParser::sameHorse($l['name'], $n) === true ? 1 : 0;
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$s, $score];
            }
        }
        if ($best && $bestScore >= 2) {
            return [$best, "results page ($bestScore top sellers agree)"];
        }
        if ($stamp = HeritageParser::stampMonth($text)) {
            $from = sprintf('%04d-%02d-01', $stamp[0], $stamp[1]);
            $next = null;
            foreach ($sales as $s) {
                if ($s['date'] >= $from && $s['date'] <= date('Y-m-d', strtotime($from.' +75 days')) && ($next === null || $s['date'] < $next['date'])) {
                    $next = $s;
                }
            }
            if ($next) {
                return [$next, 'first sale after the QData stamp month '.$stamp[1].'/'.$stamp[0]];
            }

            return [['date' => $from, 'title' => null, 'venue' => null, 'city' => null, 'lots' => [], 'page' => null, 'month_only' => true], 'QData stamp month only'];
        }

        return [null, 'unknown'];
    }

    private static function coverCity(string $cover): ?string
    {
        return preg_match('/\b(PARSONS, KS|BUTLER, MO|CARTHAGE, MO|MT\.? ?VERNON, MO|MOUNT VERNON, MO)\b/i', $cover, $m) ? ucwords(strtolower($m[1]), ' .') : null;
    }

    public static function saleName(?array $s): string
    {
        $t = trim((string) ($s['title'] ?? ''));
        $when = $s ? date(! empty($s['month_only']) ? 'F Y' : 'F j, Y', strtotime($s['date'])) : '';
        $when = $when ? ' ('.$when.')' : '';
        if ($t !== '' && ! preg_match('/^Heritage Livestock/i', $t)) {
            return ucfirst($t).' at Heritage Livestock'.$when; // a sale Heritage ran with a partner
        }

        return 'Heritage Livestock '.(trim(preg_replace('/^(Heritage Livestock\'?s?\s*)+/i', '', $t)) ?: 'Horse Sale').$when;
    }

    public static function location(?array $s): ?string
    {
        return $s ? (implode(', ', array_filter([$s['venue'] ?? null, $s['city'] ?? null])) ?: null) : null;
    }

    /** Lots with their sale and any published price: one row per catalog hip. */
    public function rows(): array
    {
        $rows = [];
        $catalogs = $this->catalogs();
        foreach ($catalogs as $c) {
            if (! $c['catalog']) {
                continue;
            }
            $s = $c['sale'];
            $prices = [];
            foreach ($s['lots'] ?? [] as $l) {
                $prices[$l['hip']] = $l;
            }
            foreach ($c['hips'] as $h) {
                [$name, $registry] = self::registryOf($h['name'], (bool) $h['reg']);
                $p = $prices[$h['hip']] ?? null;
                // A price is attached by hip; if the results page names a clearly different horse, it isn't.
                if ($p && HeritageParser::sameHorse($p['name'], $name) === false) {
                    $p = null;
                }
                $past = $s && $s['date'] < now()->subDays(3)->toDateString();
                $ped = $h['pedigree'];
                $rows[] = [
                    'catalog_file' => $c['file'], 'sale_date' => $s['date'] ?? null, 'sale_name' => self::saleName($s), 'location' => self::location($s),
                    'date_basis' => $c['date_basis'], 'month_only' => ! empty($s['month_only']), 'hip' => $h['hip'], 'name' => NrchaHarvester::display($name), 'grade' => $h['grade'],
                    'registry' => $registry, 'registration_number' => $h['reg'], 'foal_year' => $h['foal_year'], 'birth_date' => $h['birth_date'] ?? null,
                    'color' => $h['color'], 'sex' => $h['sex'], 'sire' => $ped['s'] ?? null, 'dam' => $ped['d'] ?? null, 'dam_sire' => $ped['ds'] ?? null,
                    'pedigree' => $ped, 'status' => $p ? 'sold' : ($past ? 'offered' : 'catalog'), 'price_cents' => $p['price_cents'] ?? null,
                    'price_source' => $p ? ($s['page'] ?? null) : null, 'has_consignor' => $h['consignor'] !== null, 'source_url' => self::UGD.$c['file'], 'page' => $h['page'],
                ];
            }
        }
        // Sales whose catalog is gone: the published top sellers alone (hip, name, price), when the name is a name
        // and not a description ("Finished Rope Gelding").
        $catalogued = array_flip(array_filter(array_column($rows, 'sale_date')));
        foreach ($this->sales() as $date => $s) {
            if (isset($catalogued[$date])) {
                continue;
            }
            foreach ($s['lots'] as $l) {
                $name = self::cleanName($l['name']);
                if (! $name || HeritageParser::sameHorse($name, '~') === null) {
                    continue;
                }
                [$name, $registry] = self::registryOf($name, false);
                $rows[] = [
                    'catalog_file' => null, 'sale_date' => $date, 'sale_name' => self::saleName($s), 'location' => self::location($s), 'date_basis' => 'results page',
                    'month_only' => false, 'hip' => $l['hip'], 'name' => NrchaHarvester::display($name), 'grade' => false, 'registry' => $registry,
                    'registration_number' => null, 'foal_year' => null, 'birth_date' => null, 'color' => null, 'sex' => null, 'sire' => null, 'dam' => null,
                    'dam_sire' => null, 'pedigree' => [], 'status' => 'sold', 'price_cents' => $l['price_cents'], 'price_source' => $s['page'],
                    'has_consignor' => false, 'source_url' => $s['page'], 'page' => null,
                ];
            }
        }

        return $rows;
    }

    /** heritage-lots.csv / .json and heritage-catalogs.json (no consignor names). Returns totals. */
    public function export(): array
    {
        $rows = $this->rows();
        file_put_contents($this->dir.'/heritage-lots.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $fh = fopen($this->dir.'/heritage-lots.csv', 'w');
        fputcsv($fh, self::CSV);
        foreach ($rows as $r) {
            $r['pedigree'] = $r['pedigree'] ? json_encode($r['pedigree'], JSON_UNESCAPED_UNICODE) : null;
            $r['grade'] = $r['grade'] ? 'yes' : 'no';
            fputcsv($fh, array_map(fn ($k) => $r[$k] ?? null, self::CSV));
        }
        fclose($fh);
        $cats = [];
        foreach ($rows as $r) {
            $key = $r['catalog_file'] ?? 'results only '.$r['sale_date'];
            $c = &$cats[$key];
            $c ??= ['file' => $key, 'sale_name' => $r['sale_name'], 'sale_date' => $r['sale_date'], 'location' => $r['location'], 'date_basis' => $r['date_basis'], 'hips' => 0, 'highest_hip' => 0, 'with_reg' => 0, 'with_price' => 0];
            $c['hips']++;
            $c['highest_hip'] = max($c['highest_hip'], (int) $r['hip']);
            $c['with_reg'] += $r['registration_number'] ? 1 : 0;
            $c['with_price'] += $r['price_cents'] ? 1 : 0;
            unset($c);
        }
        usort($cats, fn ($a, $b) => strcmp((string) $a['sale_date'], (string) $b['sale_date']));
        file_put_contents($this->dir.'/heritage-catalogs.json', json_encode($cats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $published = array_sum(array_map(fn ($s) => count($s['lots']), $this->sales()));

        return ['catalog_pdfs' => count(array_filter($cats, fn ($c) => str_starts_with($c['file'], '1b95cd_'))), 'results_only_sales' => count(array_filter($cats, fn ($c) => ! str_starts_with($c['file'], '1b95cd_'))), 'horses' => count($rows), 'with_reg_number' => count(array_filter($rows, fn ($r) => $r['registration_number'])),
            'with_price' => count(array_filter($rows, fn ($r) => $r['price_cents'])), 'prices_published' => $published, 'catalog_list' => $cats];
    }

    /**
     * Load into sale_lots (one per sale day + hip). A lot attaches on its registration number; otherwise a registered
     * name gets the shared rule (AdvertisedRegisteredName: propose, queue or create an unverified record). Consignors never go in.
     */
    public function load(): array
    {
        $src = $this->source();
        $out = ['loaded' => 0, 'skipped_no_sale_date' => 0, 'with_reg_number' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($this->rows() as $r) {
            if (! $r['sale_date']) {
                $out['skipped_no_sale_date']++;
                continue;
            }
            $lot = SaleLot::record($src->id, 'hl:'.$r['sale_date'].':'.$r['hip'], [
                'sale_name' => mb_substr($r['sale_name'], 0, 255), 'sale_date' => $r['sale_date'], 'date_precision' => $r['month_only'] ? 'month' : 'day',
                'lot' => $r['hip'], 'horse_name' => mb_substr($r['name'], 0, 255), 'registered' => $r['registration_number'] ? true : ($r['grade'] ? false : null),
                'registry' => $r['registry'], 'registration_number' => $r['registration_number'], 'foal_year' => $r['foal_year'], 'birth_date' => $r['birth_date'],
                'sex' => $r['sex'], 'color' => $r['color'], 'breed' => $r['registry'] === 'AQHA' ? 'Quarter Horse' : null,
                'sire_name' => $r['sire'], 'dam_name' => $r['dam'], 'dam_sire_name' => $r['dam_sire'], 'pedigree' => $r['pedigree'] ?: null,
                'status' => $r['status'], 'price_cents' => $r['price_cents'], 'buyers_premium' => 'unknown', 'source_url' => $r['source_url'],
                'note' => mb_substr($r['price_cents'] ? 'Price from Heritage Livestock\'s published top sellers: '.$r['price_source'] : ($r['status'] === 'offered' ? 'Offered at the sale; Heritage publishes prices for its top sellers only' : 'Catalogued'), 0, 255),
            ]);
            $out['loaded']++;
            $out['with_reg_number'] += $lot->registration_number ? 1 : 0;
            $out[RegistrationOnlyMatch::place($lot, 'Heritage Livestock')]++;
        }

        return $out;
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
