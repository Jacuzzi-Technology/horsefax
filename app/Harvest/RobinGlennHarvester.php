<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Robin Glenn Pedigrees' QData online sale catalogs (catalogs.robinglenn.com), Richard's decision Oct 22 2026:
 * "Import everything". Robin Glenn is a commercial pedigree business and the catalogs are its product, so facts only,
 * with a link back to the catalog page. No robots.txt (404), no terms on the catalog pages, no bot wall seen.
 *
 * Catalogs are numbered (catID) and SalePreview?catID=N opens without the uid parameter. The crawl reads every catID
 * from 1 up to the highest that exists (it stops after PROBE_GAP missing numbers in a row past the last real one), one
 * request at a time, $delay seconds apart (at least 3), as HorsefaxBot. Errors back off (30 s doubling); a 403, a 429 or a bot
 * challenge stops the run (never worked around). Each page is kept gzipped in html/, so parsing, the inventory and the
 * load never touch the site again. The per-horse pedigree pages (RGPCatBTNew.aspx) are NOT fetched.
 *
 * Consignor and buyer names are never stored (house rule; only whether a lot had them). The gzipped evidence copy of
 * each page still contains them, as the catalog published it. Notes, photos and videos are not read at all.
 */
class RobinGlennHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://catalogs.robinglenn.com/account/SalePreview?catID=';
    public const PROBE_GAP = 60;
    public const CSV = ['cat_id', 'kind', 'hip', 'name', 'rg_horse_id', 'foal_date_text', 'foal_year', 'birth_date', 'color', 'sex_code', 'sex',
        'sire', 'dam', 'dam_sire', 'session', 'results', 'status', 'price_text', 'price_cents', 'has_consignor', 'has_buyer', 'sale_name', 'sale_date', 'location', 'source_url'];
    public const INVENTORY = ['cat_id', 'state', 'sale_company', 'sale_name', 'location', 'date_from', 'date_to', 'contact', 'website',
        'lots', 'withdrawn', 'with_results', 'with_price', 'with_buyer', 'with_consignor', 'with_foal_year', 'kind'];

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    private string $dir;
    /** @var array<int, array{state:string, fetched_at:string, http?:int}> catID => crawl state */
    private array $state = [];

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY); // house rule: at least 3 s between requests (faked HTTP in tests)
        }
        $this->dir = $dir ?? storage_path('app/private/robinglenn');
        @mkdir($this->dir.'/html', 0775, true);
        if (is_file($this->dir.'/crawl-state.json')) {
            $this->state = json_decode(file_get_contents($this->dir.'/crawl-state.json'), true) ?: [];
        }
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'robinglenn'], [
            'name' => 'Robin Glenn Pedigrees QData online catalogs', 'url' => 'https://catalogs.robinglenn.com', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 22 2026: no robots.txt, no terms on the catalog pages, catalogs open by sequential catID without a login. Robin Glenn Pedigrees is a commercial pedigree business and the catalogs are its product (flagged to Richard); Richard decided "Import everything" (Oct 22 2026). Facts only with a link back; notes, photos, videos not collected; consignor and buyer names never stored (only whether the catalog filled them in); the kept evidence pages still show them.',
        ]);
    }

    /**
     * Fetch every catID not yet kept: 1 .. the highest real one (probing past it). $refreshFrom re-reads catIDs at or
     * above that number (recent catalogs that may have gained results); $refresh re-reads just those catIDs (the sale
     * calendar's picks, see SaleWatch). Returns counts.
     *
     * @return array<string, int>
     */
    public function crawl(int $from = 1, ?int $to = null, ?int $maxRequests = null, ?int $refreshFrom = null, array $refresh = []): array
    {
        $out = ['requests' => 0, 'catalogs' => 0, 'empty' => 0, 'missing' => 0, 'highest' => 0];
        $gap = 0;
        try {
            for ($id = $from; $to === null || $id <= $to; $id++) {
                if ($maxRequests !== null && $this->requests >= $maxRequests) {
                    $this->say("stopped after $maxRequests requests at catID $id");
                    break;
                }
                $kept = $this->state[$id]['state'] ?? null;
                $again = ($refreshFrom !== null && $id >= $refreshFrom && $kept !== 'missing') || in_array($id, $refresh, true);
                // Numbers past the last real catalog are probed again on every run: new catalogs appear there.
                if ($kept === null || $kept === 'error' || $again || ($kept === 'missing' && $id > $this->highest())) {
                    $this->fetchCatalog($id);
                }
                $kept = $this->state[$id]['state'] ?? 'error';
                $out[$kept === 'catalog' ? 'catalogs' : ($kept === 'empty' ? 'empty' : 'missing')]++;
                if ($kept === 'missing' && $to === null && $id > $this->highest()) {
                    if (++$gap >= self::PROBE_GAP) {
                        $this->say("no catalog for ".self::PROBE_GAP." numbers past catID ".$this->highest().": done");
                        break;
                    }
                } else {
                    $gap = 0;
                }
                if ($this->requests % 25 === 0) {
                    $this->saveState();
                }
            }
        } finally {
            $this->saveState();
        }
        $out['requests'] = $this->requests;
        $out['highest'] = $this->highest();

        return $out;
    }

    /** The highest catID with a real (non-missing) page. */
    public function highest(): int
    {
        $ids = array_keys(array_filter($this->state, fn ($s) => $s['state'] !== 'missing' && $s['state'] !== 'error'));

        return $ids ? max($ids) : 0;
    }

    private function fetchCatalog(int $id): void
    {
        $html = $this->get(self::BASE.$id);
        if ($html === null) {
            $this->state[$id] = ['state' => 'error', 'fetched_at' => now()->toIso8601String()];

            return;
        }
        $p = RobinGlennParser::parse($html);
        $state = $p['rows'] ? 'catalog' : ($p['sale']['name'] || $p['sale']['date_from'] ? 'empty' : 'missing');
        if ($state !== 'missing') {
            file_put_contents($this->dir.'/html/'.$id.'.html.gz', gzencode($html, 9));
        }
        $this->state[$id] = ['state' => $state, 'fetched_at' => now()->toIso8601String(), 'rows' => count($p['rows'])];
        $this->say("catID $id: $state".($p['rows'] ? ', '.count($p['rows']).' rows' : '').($p['sale']['name'] ? ' ('.$p['sale']['name'].')' : ''));
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
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            $challenge = preg_match('/cf-chl|challenge-platform|captcha|Just a moment\.\.\.|Access denied/i', substr($body, 0, 20000));
            if ($status === 200 && ! $challenge) {
                return $body;
            }
            if (in_array($status, [403, 429], true) || ($status === 200 && $challenge)) {
                $this->saveState();
                throw new HarvestBlocked("Blocked or rate-limited at $url (HTTP $status): stopping. No workaround; ask Robin Glenn Pedigrees to allow HorsefaxBot.");
            }
            if ($status === 404) {
                return null;
            }
            if ($attempt >= 5) {
                $this->saveState();
                throw new HarvestBlocked("Giving up at $url after $attempt retries (HTTP $status): stopping the run.");
            }
            $this->say("HTTP $status at $url; waiting {$backoff}s");
            sleep($backoff);
        }
    }

    private function saveState(): void
    {
        ksort($this->state);
        file_put_contents($this->dir.'/crawl-state.json.tmp', json_encode($this->state));
        rename($this->dir.'/crawl-state.json.tmp', $this->dir.'/crawl-state.json');
    }

    /** Every kept catalog, parsed: catID => parse() result. */
    private function catalogs(): \Generator
    {
        foreach ($this->state as $id => $s) {
            $f = $this->dir.'/html/'.$id.'.html.gz';
            if (in_array($s['state'], ['catalog', 'empty'], true) && is_file($f)) {
                yield (int) $id => RobinGlennParser::parse(gzdecode(file_get_contents($f)));
            }
        }
    }

    /** sale, private_order (a pedigree order like "PO-Name-03112026") or test (Robin Glenn's own test and example catalogs). */
    public static function kind(?string $name): string
    {
        $n = (string) $name;

        return match (true) {
            (bool) preg_match('/^PO[- ]/i', $n) => 'private_order',
            (bool) preg_match('/\btest\b|\bexample\b|\bdemo\b/i', $n) => 'test',
            default => 'sale',
        };
    }

    /** "billingslivestock.com" / "http://www.tqha.com" -> "billingslivestock.com"; else the contact. */
    public static function company(array $sale): ?string
    {
        $w = $sale['website'] ?? null;
        if ($w && preg_match('#^(?:https?://)?(?:www\.)?([a-z0-9.-]+\.[a-z]{2,})#i', $w, $m)) {
            return strtolower($m[1]);
        }

        return $sale['contact'] ?? null;
    }

    /** sold / no_sale / withdrawn / catalog (+ offered once the sale is past) from the Results and Price cells. */
    public static function status(array $row, ?string $saleDate): string
    {
        $r = strtolower((string) $row['results']);
        $past = $saleDate && $saleDate < now()->subDays(3)->toDateString();

        // Seen in the catalogs: "Purchased", "Purchased 5500", "Repurchased" (bought back by the consignor), "Out".
        return match (true) {
            str_contains($r, 'withdr') || str_contains(strtolower((string) $row['name']), 'withdrawn') || $r === 'out' || $r === 'scratched' => 'withdrawn',
            (bool) preg_match('/\b(repurchased|no ?sale|ns|rna|pass(ed)?|not sold|bought back|buy ?back)\b/', $r) => self::price($row) ? 'high_bid' : 'no_sale',
            (bool) preg_match('/\b(purchased|sold)\b/', $r) || ($row['price_cents'] && $r === '') => self::price($row) ? 'sold' : 'offered',
            $past => 'offered',
            default => 'catalog',
        };
    }

    /** The price: the Price cell, else a number in the Results cell ("Purchased 5500"). */
    public static function price(array $row): ?int
    {
        if (! empty($row['price_cents'])) {
            return $row['price_cents'];
        }

        return preg_match('/\b(\d{3,7})(?:\.\d\d)?\b/', (string) $row['results'], $m) ? (int) $m[1] * 100 : null;
    }

    /** inventory.csv + catalogs.json + lots.csv + lots.json from the kept pages. Returns totals. */
    public function export(): array
    {
        $inv = fopen($this->dir.'/inventory.csv', 'w');
        fputcsv($inv, self::INVENTORY);
        $lots = fopen($this->dir.'/robinglenn-lots.csv', 'w');
        fputcsv($lots, self::CSV);
        $json = fopen($this->dir.'/robinglenn-lots.jsonl', 'w');
        $tot = ['catalog_ids_seen' => count($this->state), 'missing_ids' => count(array_filter($this->state, fn ($s) => $s['state'] === 'missing')), 'catalogs_with_lots' => 0, 'catalogs_empty' => 0, 'private_orders' => 0, 'test_catalogs' => 0, 'sale_catalogs_with_lots' => 0, 'sale_lots' => 0,
            'lots' => 0, 'withdrawn' => 0, 'with_price' => 0, 'with_buyer' => 0, 'with_foal_year' => 0, 'catalogs_with_price' => 0, 'catalogs_with_buyer' => 0, 'highest_cat_id' => $this->highest()];
        $results = [];
        foreach ($this->catalogs() as $id => $p) {
            $sale = $p['sale'];
            $rows = $p['rows'];
            $kind = self::kind($sale['name']);
            $count = fn ($k) => count(array_filter($rows, fn ($r) => ! empty($r[$k])));
            $withdrawn = 0;
            foreach ($rows as $r) {
                $status = self::status($r, $sale['date_from']);
                $withdrawn += $status === 'withdrawn' ? 1 : 0;
                $results[$r['results'] ?? ''] = ($results[$r['results'] ?? ''] ?? 0) + 1;
                $r['price_cents'] = self::price($r);
                $row = ['cat_id' => $id, 'kind' => $kind, 'status' => $status, 'sale_name' => $sale['name'], 'sale_date' => $sale['date_from'], 'location' => $sale['location'], 'source_url' => self::BASE.$id] + $r;
                fputcsv($lots, array_map(fn ($k) => $row[$k] ?? null, self::CSV));
                fwrite($json, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            }
            $line = ['cat_id' => $id, 'state' => $rows ? 'catalog' : 'empty', 'sale_company' => self::company($sale), 'sale_name' => $sale['name'],
                'location' => $sale['location'], 'date_from' => $sale['date_from'], 'date_to' => $sale['date_to'], 'contact' => $sale['contact'], 'website' => $sale['website'],
                'lots' => count($rows), 'withdrawn' => $withdrawn, 'with_results' => $count('results'), 'with_price' => $count('price_cents'),
                'with_buyer' => $count('has_buyer'), 'with_consignor' => $count('has_consignor'), 'with_foal_year' => $count('foal_year'), 'kind' => $kind];
            fputcsv($inv, $line);
            $tot[$rows ? 'catalogs_with_lots' : 'catalogs_empty']++;
            $tot['private_orders'] += $kind === 'private_order' ? 1 : 0;
            $tot['test_catalogs'] += $kind === 'test' ? 1 : 0;
            $tot['sale_catalogs_with_lots'] += $kind === 'sale' && $rows ? 1 : 0;
            $tot['sale_lots'] += $kind === 'sale' ? count($rows) : 0;
            $tot['lots'] += count($rows);
            $tot['withdrawn'] += $withdrawn;
            $tot['with_price'] += $line['with_price'];
            $tot['with_buyer'] += $line['with_buyer'];
            $tot['with_foal_year'] += $line['with_foal_year'];
            $tot['catalogs_with_price'] += $line['with_price'] ? 1 : 0;
            $tot['catalogs_with_buyer'] += $line['with_buyer'] ? 1 : 0;
        }
        fclose($inv);
        fclose($lots);
        fclose($json);
        arsort($results);
        $tot['results_values'] = array_slice($results, 0, 25, true);
        file_put_contents($this->dir.'/totals.json', json_encode($tot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $tot;
    }

    /**
     * Load every lot into sale_lots (one per catID + hip). The web catalog prints no registration numbers, so lots go
     * straight to the shared registered-name rule (AdvertisedRegisteredName): sire + dam given and a 2+ word name means
     * propose, queue or create an unverified record; anything else waits as an unmatched source record.
     * Private pedigree orders ("PO-...") and Robin Glenn's test/example catalogs are not sales and are skipped. Consignor and buyer never go in.
     */
    public function load(): array
    {
        $src = $this->source();
        $out = ['loaded' => 0, 'skipped_private_orders' => 0, 'skipped_test_catalogs' => 0, 'skipped_withdrawn' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($this->catalogs() as $id => $p) {
            $sale = $p['sale'];
            $kind = self::kind($sale['name']);
            if ($kind !== 'sale') {
                $out[$kind === 'test' ? 'skipped_test_catalogs' : 'skipped_private_orders'] += count($p['rows']);
                continue;
            }
            foreach ($p['rows'] as $i => $r) {
                $status = self::status($r, $sale['date_from']);
                if (! $r['name'] || $status === 'withdrawn' && ! $r['sire']) {
                    $out['skipped_withdrawn']++;
                    continue;
                }
                $lot = SaleLot::record($src->id, $id.':'.($r['hip'] ?? 'h'.($r['rg_horse_id'] ?? $i)), [
                    'sale_name' => mb_substr($sale['name'] ?? 'Robin Glenn catalog '.$id, 0, 255), 'sale_date' => $sale['date_from'], 'date_precision' => $sale['date_from'] ? 'day' : 'year',
                    'lot' => $r['hip'], 'horse_name' => mb_substr(NrchaHarvester::display($r['name']), 0, 255),
                    'foal_year' => $r['foal_year'], 'birth_date' => $r['birth_date'], 'sex' => $r['sex'], 'color' => $r['color'],
                    'sire_name' => $r['sire'], 'dam_name' => $r['dam'], 'dam_sire_name' => $r['dam_sire'],
                    'pedigree' => array_filter(['s' => $r['sire'], 'd' => $r['dam'], 'ds' => $r['dam_sire']]) ?: null,
                    'status' => $status, 'price_cents' => in_array($status, ['sold', 'high_bid'], true) ? self::price($r) : null,
                    'buyers_premium' => 'unknown', 'source_url' => self::BASE.$id,
                    'note' => mb_substr('Robin Glenn QData catalog #'.$id.($sale['location'] ? ' ('.$sale['location'].')' : ''), 0, 255),
                ]);
                $out['loaded']++;
                $out[RegistrationOnlyMatch::place($lot, 'Robin Glenn QData')]++;
            }
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
