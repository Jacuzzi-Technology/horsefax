<?php

namespace App\Harvest;

use App\Actions\Record\CreateRecordsFromSaleLots;
use App\Actions\Record\MatchSaleLots;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\ReviewSuggestion;
use App\Models\SaleLot;
use App\Support\Record;
use Illuminate\Support\Facades\Http;

/**
 * RanchWorldAds.com horse ads, active and sold, back to 2014 (Richard, Oct 22 2026). Checked Oct 22 2026: no
 * robots.txt, no sitemap, no anti-scraping clause, and sold ads stay online. Its Site Rules ("USE OF PHOTOS") give
 * RanchWorldAds an exclusive, perpetual license to ad text and images, so only facts are kept, with a link back.
 *
 * Finding the ads: the Horses For Sale index (cat_id=1, newest first) pages through every horse ad, sold ones
 * included ("Horses For Sale" and "Horses For Bid at Auction"). Listing ids outside the index still resolve, so
 * --probe samples random ids in the index's range to measure how many horse ads the index leaves out.
 *
 * One request at a time, $delay seconds apart, as HorsefaxBot. Errors back off (30 s doubling); a 403, a 429 or a
 * bot challenge stops the run (never worked around). Raw HTML is cached gzipped in $dir/html (local only: it holds
 * the ad text, so it is never loaded, deployed or published; it lets the parser be rerun without refetching).
 * Rerunnable: ads.jsonl is the state, ads are fetched once and refreshed when the index shows a change (sold) or
 * after $refreshDays; ads that drop out of a full index are re-checked and marked gone.
 */
class RanchWorldAdsHarvester
{
    public const BASE = 'https://www.ranchworldads.com';
    public const SALE_NAME = 'RanchWorldAds.com classified ad';

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    /** @var array<int, array> ad id => latest record */
    private array $ads = [];
    /** @var array<int, array> ad id => its row in this run's index */
    private array $listed = [];
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/ranchworldads');
        @mkdir($this->dir.'/html', 0775, true);
        $this->readState();
    }

    public static function adUrl(int $id): string
    {
        return self::BASE.'/classified.php?listing='.$id;
    }

    public static function indexUrl(int $page): string
    {
        return self::BASE.'/index.php?cat_id=1&pg='.$page.'&sort=date&sort_dir=d';
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'ranchworldads'], [
            'name' => 'RanchWorldAds.com', 'url' => self::BASE, 'permission' => 'terms_allow',
            'terms_note' => 'Checked Oct 22 2026: no robots.txt, no sitemap, no anti-scraping clause; sold ads stay online. Site Rules ("USE OF PHOTOS") give RanchWorldAds an exclusive, perpetual license to ad text and images, so facts only with a link back: photos, video, descriptions and phone numbers are never stored. Richard asked for it (Oct 22 2026).',
        ]);
    }

    /**
     * The whole run. Returns counts.
     *
     * @return array<string, int|float|string>
     */
    public function run(bool $index = true, ?int $maxAds = null, int $refreshDays = 60, int $probe = 0, ?int $maxPages = null): array
    {
        $out = ['index_pages' => 0, 'index_ads' => 0, 'fetched' => 0, 'from_cache' => 0, 'new' => 0, 'gone' => 0, 'not_horse' => 0];
        $complete = false;
        if ($index) {
            [$out['index_pages'], $complete] = $this->readIndex($maxPages);
            $out['index_ads'] = count($this->listed);
        }
        $queue = array_keys($this->listed);
        rsort($queue);
        $fetched = 0;
        foreach ($queue as $id) {
            if ($maxAds !== null && $fetched >= $maxAds) {
                break;
            }
            if (! $this->needsFetch($id, $refreshDays)) {
                $this->touch($id);
                continue;
            }
            $this->fetchAd($id, $out) ? $fetched++ : $out['from_cache']++;
            if ($fetched && $fetched % 100 === 0) {
                $this->say("$fetched ads fetched, ".count($this->ads).' on file, '.$this->requests.' requests');
            }
        }
        // Only after a full index: re-check ads that dropped out of it and mark the gone ones.
        if ($complete && $maxAds === null) {
            foreach ($this->ads as $id => $a) {
                if ($a['status'] === 'live' && empty($a['off_index']) && ! isset($this->listed[$id])) {
                    $this->fetchAd($id, $out, true) && $fetched++;
                }
            }
        }
        $out['fetched'] = $fetched;
        if ($probe > 0) {
            $out += $this->probe($probe);
        }
        $out['requests'] = $this->requests;
        $this->compact();

        return $out + $this->export();
    }

    /** Read the index from page 1 to its last page. Returns [pages read, finished?]. */
    public function readIndex(?int $maxPages = null): array
    {
        $last = null;
        $pages = 0;
        $summary = [];
        for ($pg = 1; $last === null || $pg <= $last; $pg++) {
            if ($maxPages !== null && $pages >= $maxPages) {
                return [$pages, false];
            }
            // An index page read in the last 12 hours (a run that was stopped and restarted) is reused, not refetched.
            $cached = $this->dir.'/html/index-'.$pg.'.html.gz';
            $html = is_file($cached) && time() - filemtime($cached) < 12 * 3600 ? gzdecode(file_get_contents($cached)) : $this->get(self::indexUrl($pg));
            $pages++;
            if ($html === null) {
                $this->say("index page $pg failed; index incomplete");
                $summary[$pg] = null;
                continue;
            }
            file_put_contents($this->dir.'/html/index-'.$pg.'.html.gz', gzencode($html, 6));
            $p = RanchWorldAdsParser::listPage($html);
            $last = max($last ?? 1, $p['last_page'] ?? $pg);
            foreach ($p['rows'] as $r) {
                if (! isset($this->listed[$r['id']]) || ($this->listed[$r['id']]['featured'] && ! $r['featured'])) {
                    $this->listed[$r['id']] = $r + ['page' => $pg]; // its place in the list beats a featured strip
                }
            }
            $summary[$pg] = count(array_filter($p['rows'], fn ($r) => ! $r['featured']));
            if (! $summary[$pg]) {
                break;
            }
            if ($pg % 50 === 0) {
                $this->say("index page $pg of $last, ".count($this->listed).' ads');
            }
        }
        $ids = array_keys($this->listed);
        file_put_contents($this->dir.'/index-'.now()->format('Y-m-d').'.json', json_encode([
            'date' => now()->toDateString(), 'pages' => $pages, 'last_page' => $last, 'ads' => count($ids),
            'sold' => count(array_filter($this->listed, fn ($r) => $r['sold'])),
            'featured_only' => count(array_filter($this->listed, fn ($r) => $r['featured'])),
            'kinds' => array_count_values(array_column($this->listed, 'kind')),
            'min_id' => $ids ? min($ids) : null, 'max_id' => $ids ? max($ids) : null,
            'rows_per_page' => $summary, 'rows' => array_values($this->listed),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->say("index read: $pages pages, ".count($ids).' ads');

        return [$pages, ! in_array(null, $summary, true)];
    }

    private function needsFetch(int $id, int $refreshDays): bool
    {
        $a = $this->ads[$id] ?? null;
        if (! $a || in_array($a['status'], ['gone', 'error'], true)) {
            return true;
        }
        if ($a['status'] !== 'live') {
            return false; // not a horse ad
        }
        $row = $this->listed[$id] ?? null;
        if ($row && ! $row['featured'] && $row['sold'] !== ($a['sold'] ?? null)) {
            return true; // the index shows it sold (or relisted) since we read it
        }

        return empty($a['sold']) && (time() - strtotime($a['fetched_at'])) > $refreshDays * 86400;
    }

    /**
     * Read one ad (from the cache when it is current, otherwise fetched). Returns whether a request was made.
     * $recheck forces a fetch (an ad that dropped out of the index).
     */
    private function fetchAd(int $id, array &$out, bool $recheck = false): bool
    {
        $cache = $this->cachePath($id);
        $prev = $this->ads[$id] ?? null;
        $fetched = false;
        $html = null;
        if (! $recheck && ! $prev && is_file($cache)) {
            $html = gzdecode(file_get_contents($cache)); // cached by an earlier run whose state was lost
        } else {
            $html = $this->get(self::adUrl($id));
            $fetched = true;
            if ($html !== null && ! RanchWorldAdsParser::gone($html)) {
                @mkdir(dirname($cache), 0775, true);
                file_put_contents($cache, gzencode($html, 6));
            }
        }
        $this->record($id, $html, $out, $prev);

        return $fetched;
    }

    /** Parse a page into the state file. */
    private function record(int $id, ?string $html, array &$out, ?array $prev, array $extra = []): void
    {
        $now = now()->toIso8601String();
        if ($html === null || RanchWorldAdsParser::gone($html)) {
            if ($prev && $prev['status'] === 'live') {
                $this->put($id, ['status' => 'gone', 'gone_at' => now()->toDateString(), 'checked_at' => $now] + $prev);
                $out['gone']++;
            } elseif (! $prev) {
                $this->put($id, ['ad_id' => $id, 'status' => $html === null ? 'error' : 'gone', 'checked_at' => $now] + $extra);
            }

            return;
        }
        $facts = RanchWorldAdsParser::ad($html, $id);
        if ($facts === null || ! $facts['horse']) {
            $this->put($id, ['ad_id' => $id, 'status' => 'not_horse', 'category' => $facts['category'] ?? null, 'checked_at' => $now] + $extra);
            $out['not_horse']++;

            return;
        }
        unset($facts['horse']);
        $row = $this->listed[$id] ?? null;
        $facts += $extra + [
            'status' => 'live', 'index_page' => $row['page'] ?? null, 'off_index' => $prev['off_index'] ?? ! $row,
            'fetched_at' => $now, 'checked_at' => $now, 'gone_at' => null,
            'first_seen' => $prev['first_seen'] ?? now()->toDateString(), 'last_seen' => now()->toDateString(),
        ];
        if (! empty($facts['sold']) && empty($prev['sold'])) {
            $facts['sold_seen'] = now()->toDateString(); // first time we saw it marked SOLD (not the sale date)
        } elseif (! empty($prev['sold_seen'])) {
            $facts['sold_seen'] = $prev['sold_seen'];
        }
        $this->put($id, $facts);
        if (! $prev || $prev['status'] !== 'live') {
            $out['new']++;
        }
    }

    /**
     * Fetch $n random listing ids in the index's id range that the index doesn't list, to measure the horse ads it
     * leaves out. Horse ads found are kept (off_index). Written to probe-YYYY-MM-DD.json.
     */
    public function probe(int $n): array
    {
        $ids = array_keys($this->listed ?: array_filter($this->ads, fn ($a) => empty($a['off_index'])));
        if (! $ids) {
            return [];
        }
        [$lo, $hi] = [min($ids), max($ids)];
        $res = ['range' => "$lo-$hi", 'sampled' => 0, 'gone' => 0, 'not_horse' => 0, 'horse' => 0, 'horse_ids' => []];
        $tried = [];
        $out = ['gone' => 0, 'new' => 0, 'not_horse' => 0];
        while ($res['sampled'] < $n) {
            $id = random_int($lo, $hi);
            if (isset($tried[$id]) || isset($this->listed[$id]) || isset($this->ads[$id])) {
                continue;
            }
            $tried[$id] = true;
            $res['sampled']++;
            $html = $this->get(self::adUrl($id));
            if ($html === null || RanchWorldAdsParser::gone($html)) {
                $res['gone']++;
                continue;
            }
            $facts = RanchWorldAdsParser::ad($html, $id);
            if (! $facts || ! $facts['horse']) {
                $res['not_horse']++;
                continue;
            }
            $res['horse']++;
            $res['horse_ids'][] = $id;
            $cache = $this->cachePath($id);
            @mkdir(dirname($cache), 0775, true);
            file_put_contents($cache, gzencode($html, 6));
            $this->record($id, $html, $out, null, ['off_index' => true]);
        }
        $span = $hi - $lo + 1 - count($this->listed);
        $res['estimated_off_index_horse_ads'] = (int) round($res['horse'] / max(1, $res['sampled']) * $span);
        file_put_contents($this->dir.'/probe-'.now()->format('Y-m-d').'.json', json_encode($res, JSON_PRETTY_PRINT));
        $this->say('probe: '.json_encode(array_diff_key($res, ['horse_ids' => 1])));

        return ['probe_sampled' => $res['sampled'], 'probe_horse' => $res['horse'], 'probe_estimated_off_index' => $res['estimated_off_index_horse_ads']];
    }

    /** Re-read every cached page with the current parser (after a parser fix). No requests. */
    public function reparse(): array
    {
        $out = ['gone' => 0, 'new' => 0, 'not_horse' => 0, 'reparsed' => 0];
        foreach ($this->ads as $id => $a) {
            if ($a['status'] === 'live' && is_file($c = $this->cachePath($id))) {
                $keep = array_intersect_key($a, array_flip(['off_index', 'index_page', 'first_seen', 'last_seen', 'fetched_at', 'sold_seen']));
                $facts = RanchWorldAdsParser::ad(gzdecode(file_get_contents($c)), $id);
                if ($facts && $facts['horse']) {
                    unset($facts['horse']);
                    $this->ads[$id] = $facts + $keep + ['status' => 'live', 'checked_at' => $a['checked_at'], 'gone_at' => null];
                    $out['reparsed']++;
                }
            }
        }
        $this->compact();

        return $out + $this->export();
    }

    /** Polite GET: the body, null on 404 or a page that won't load; stops the run when blocked. */
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
                $status = $r->status();
                $body = $r->body();
            } catch (\Throwable $e) {
                $status = 0;
                $body = $e->getMessage();
            }
            $challenge = $status === 200 && preg_match('/cf-chl|challenge-platform|Just a moment\.\.\.|captcha/i', substr($body, 0, 20000));
            if ($status === 200 && ! $challenge) {
                return $body;
            }
            if ($status === 404) {
                return null;
            }
            if (in_array($status, [401, 403, 429], true) || $challenge) {
                $this->compact();
                throw new RanchWorldAdsBlocked("Blocked at $url (HTTP $status".($challenge ? ', challenge page' : '').'): stopping. No workaround; ask RanchWorldAds to allow HorsefaxBot.');
            }
            if ($attempt >= 5) {
                $this->say("giving up on $url after $attempt retries (HTTP $status)");

                return null;
            }
            $this->say("HTTP $status at $url; waiting {$backoff}s");
            sleep($backoff);
        }
    }

    private function cachePath(int $id): string
    {
        return $this->dir.'/html/'.intdiv($id, 1000).'/'.$id.'.html.gz';
    }

    // ---- the raw file: one JSON line per change, the last line for an ad wins -------------------------------------

    private function readState(): void
    {
        $file = $this->dir.'/ads.jsonl';
        if (! is_file($file)) {
            return;
        }
        foreach (new \SplFileObject($file) as $line) {
            if (($a = json_decode((string) $line, true)) && isset($a['ad_id'])) {
                $this->ads[(int) $a['ad_id']] = $a;
            }
        }
    }

    private function put(int $id, array $a): void
    {
        $this->ads[$id] = $a;
        file_put_contents($this->dir.'/ads.jsonl', json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }

    private function touch(int $id): void
    {
        $this->ads[$id]['last_seen'] = now()->toDateString();
    }

    private function compact(): void
    {
        ksort($this->ads);
        $tmp = $this->dir.'/ads.jsonl.tmp';
        $fh = fopen($tmp, 'w');
        foreach ($this->ads as $a) {
            fwrite($fh, json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }
        fclose($fh);
        rename($tmp, $this->dir.'/ads.jsonl');
    }

    public const CSV = ['ad_id', 'url', 'status', 'off_index', 'kind', 'title', 'name', 'sold', 'auction', 'price_cents', 'price_text', 'sale_name',
        'city', 'state', 'foal_year', 'foaled_text', 'breed', 'sex', 'height_hands', 'weight_lbs', 'color', 'registry', 'registry_text', 'registered',
        'disciplines', 'poster', 'horsebid', 'posted', 'registration_number', 'reg_numbers', 'sire', 'dam', 'dam_sire', 'pedigree', 'pedigree_from',
        'first_seen', 'last_seen', 'sold_seen', 'fetched_at', 'gone_at'];

    /** ranchworldads-ads.json and .csv beside the raw file, plus the counts. */
    public function export(): array
    {
        $ads = array_values(array_filter($this->ads, fn ($a) => in_array($a['status'], ['live', 'gone'], true) && isset($a['url'])));
        file_put_contents($this->dir.'/ranchworldads-ads.json', json_encode($ads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $fh = fopen($this->dir.'/ranchworldads-ads.csv', 'w');
        fputcsv($fh, self::CSV, escape: '');
        foreach ($ads as $a) {
            $ped = $a['pedigree'] ?? [];
            $row = $a + ['sire' => $ped['s'] ?? null, 'dam' => $ped['d'] ?? null, 'dam_sire' => $ped['ds'] ?? null];
            $row['pedigree'] = $ped ? json_encode($ped, JSON_UNESCAPED_UNICODE) : null;
            $row['disciplines'] = implode(', ', $a['disciplines'] ?? []);
            $row['reg_numbers'] = implode(' ', $a['reg_numbers'] ?? []);
            fputcsv($fh, array_map(fn ($k) => is_bool($row[$k] ?? null) ? ($row[$k] ? 'yes' : 'no') : ($row[$k] ?? null), self::CSV), escape: '');
        }
        fclose($fh);

        $n = fn ($f) => count(array_filter($ads, $f));

        return [
            'ads_on_file' => count($ads),
            'live' => $n(fn ($a) => $a['status'] === 'live'),
            'sold' => $n(fn ($a) => ! empty($a['sold'])),
            'active' => $n(fn ($a) => $a['status'] === 'live' && empty($a['sold'])),
            'auction' => $n(fn ($a) => ! empty($a['auction'])),
            'off_index' => $n(fn ($a) => ! empty($a['off_index'])),
            'with_name' => $n(fn ($a) => ! empty($a['name'])),
            'with_pedigree' => $n(fn ($a) => ! empty($a['pedigree']['s']) && ! empty($a['pedigree']['d'])),
            'with_reg_number' => $n(fn ($a) => ! empty($a['registration_number'])),
            'horsebid' => $n(fn ($a) => ! empty($a['horsebid'])),
            'not_horse' => count(array_filter($this->ads, fn ($a) => $a['status'] === 'not_horse')),
        ];
    }

    /**
     * Load the raw file into sale_lots as advertised listings (status asking: held, not published), then match:
     *  - registration number: the one record holding it (registry agreeing or blank) → attached, held;
     *  - otherwise name + sire + dam agreeing with exactly one record → PROPOSED only: the lot is queued and a
     *    "lot_belongs" review suggestion is left for staff. Nothing is attached on a name, and no records are created.
     * Returns counts.
     */
    public function load(): array
    {
        $source = $this->source();
        $out = ['loaded' => 0, 'with_reg_number' => 0, 'matched_registration' => 0, 'conflict' => 0, 'proposed_match' => 0,
            'created_records' => 0, 'linked_same_horse' => 0, 'queued_same_name' => 0, 'unmatched' => 0, 'already_matched' => 0, 'already_in_review' => 0];
        foreach ($this->ads as $a) {
            if (! in_array($a['status'], ['live', 'gone'], true) || ! isset($a['url'])) {
                continue;
            }
            $ped = $a['pedigree'] ?? [];
            $name = $a['name'] ?? null;
            $lot = SaleLot::record($source->id, (string) $a['ad_id'], [
                'sale_name' => self::SALE_NAME, 'sale_date' => $a['posted'] ?? null,
                'date_precision' => 'day', 'sale_year' => isset($a['posted']) ? (int) substr($a['posted'], 0, 4) : null,
                // The ad title is RanchWorldAds' licensed text: an ad with no name in it is "Unnamed" (never matched).
                'horse_name' => mb_substr(NrchaHarvester::display($name) ?? 'Unnamed', 0, 255), 'registered' => $a['registered'] ?? null,
                'registry' => $a['registry'] ?? null, 'registration_number' => $a['registration_number'] ?? null,
                'foal_year' => $a['foal_year'] ?? null, 'sex' => $a['sex'] ?? null, 'color' => $a['color'] ?? null, 'breed' => $a['breed'] ?? null,
                'sire_name' => $ped['s'] ?? null, 'dam_name' => $ped['d'] ?? null, 'dam_sire_name' => $ped['ds'] ?? null, 'pedigree' => $ped ?: null,
                // An auction ad's figure may be a reserve or an estimate, not an asking price: kept in the note only.
                'status' => 'asking', 'price_cents' => empty($a['auction']) ? ($a['price_cents'] ?? null) : null, 'currency' => 'USD', 'buyers_premium' => 'none',
                'note' => mb_substr(self::note($a), 0, 255), 'source_url' => $a['url'],
            ]);
            $out['loaded']++;
            if ($lot->horse_id || $lot->review_status) {
                $out[$lot->horse_id ? 'already_matched' : 'already_in_review']++; // a rerun leaves earlier decisions alone
                continue;
            }
            if ($lot->registration_number) {
                $out['with_reg_number']++;
                $r = $this->matchRegistration($lot);
                if ($r !== 'unmatched') {
                    $out[$r === 'matched' ? 'matched_registration' : 'conflict']++;
                    continue;
                }
            }
            $r = $this->byName($lot);
            $out[['proposed' => 'proposed_match', 'linked' => 'linked_same_horse', 'created' => 'created_records', 'queued' => 'queued_same_name'][$r] ?? 'unmatched']++;
        }

        return $out;
    }

    /** Registration number only (show-results convention): the one record holding it, or a conflict for staff. */
    private function matchRegistration(SaleLot $lot): string
    {
        $ids = HorseIdentifier::where('kind', 'registration')->whereNull('superseded_by')
            ->where('value_normalized', Record::normalizeIdentifier($lot->registration_number))
            ->when($lot->registry, fn ($q) => $q->where(fn ($w) => $w->whereNull('registry')->orWhere('registry', $lot->registry)))
            ->pluck('horse_id')->unique();
        $ids = MatchSaleLots::matchable(Horse::whereIn('id', $ids))->pluck('id');
        if ($ids->count() === 1) {
            $lot->forceFill(['horse_id' => $ids->first(), 'match_tier' => 'registration', 'matched_at' => now(), 'review_status' => 'held',
                'review_note' => 'Advertised asking prices are not published yet'])->save();

            return 'matched';
        }
        if ($ids->count() > 1) {
            $lot->forceFill(['review_status' => 'conflict', 'review_note' => 'Registration number '.$lot->registration_number.' is on more than one record'])->save();

            return 'conflict';
        }

        return 'unmatched';
    }

    /**
     * An ad giving the horse's registered name (Richard, Oct 22 2026: "create new unverified horse listings anytime we
     * have the registered names on the sites we're crawling"):
     *  - one record agrees on name + parentage: PROPOSED to staff (lot queued + lot_belongs suggestion), never attached,
     *    unless that record was itself made from an earlier RanchWorldAds ad of this horse (then the ad is linked, held);
     *  - a record of that name with nothing to compare: left queued for staff, nothing created beside it;
     *  - no record of that name, or every one contradicts the ad (a different horse): a new unverified record.
     * A registered name: the ad names a registry (or says registered) or gives sire and dam, the horse isn't listed as
     * grade, and the name has two or more words ("Trigger" is a barn name). Mules and donkeys are left out. Returns proposed, linked, created, queued or unmatched.
     */
    private function byName(SaleLot $lot): string
    {
        $words = count(preg_split('/\s+/', trim($lot->horse_name)));
        $registered = $lot->registry || $lot->registered === true || ($lot->sire_name && $lot->dam_name);
        if (! $registered || Record::placeholderName($lot->horse_name) || $words < 2 || $lot->registered === false
            || preg_match('/\b(mule|donkey|burro|ass)\b/i', (string) $lot->breed)) {
            return 'unmatched';
        }
        [$horse, $tier, $review, $note] = (new MatchSaleLots)->match($lot); // a fresh matcher each time: it sees records made so far
        if ($horse) {
            if (SaleLot::where('horse_id', $horse->id)->where('data_source_id', $lot->data_source_id)->where('match_tier', 'created')->exists()) {
                $lot->forceFill(['horse_id' => $horse->id, 'match_tier' => $tier, 'matched_at' => now(), 'review_status' => 'held',
                    'review_note' => 'Same horse as an earlier RanchWorldAds ad; advertised asking prices are not published yet'])->save();

                return 'linked';
            }
            $summary = 'RanchWorldAds ad names '.$lot->horse_name.($lot->sire_name ? ' by '.$lot->sire_name : '').($lot->dam_name ? ' out of '.$lot->dam_name : '').', which agrees with '.$horse->horsefax_id.'. Proposed on name and parentage only; not attached.';
            $lot->forceFill(['review_status' => 'queued', 'review_note' => mb_substr('Proposed: '.$horse->horsefax_id.' ('.$tier.')', 0, 255)])->save();
            ReviewSuggestion::firstOrCreate(['subject_type' => 'sale_lot', 'subject_id' => $lot->id], [
                'suggestion' => 'lot_belongs', 'horse_id' => $horse->id, 'summary' => $summary,
                'evidence' => [['url' => $lot->source_url, 'facts' => array_filter(['sire' => $lot->sire_name, 'dam' => $lot->dam_name, 'foal_year' => $lot->foal_year, 'sex' => $lot->sex]), 'supports' => true]],
            ]);

            return 'proposed';
        }
        $different = $review === 'conflict' && str_starts_with((string) $note, MatchSaleLots::DIFFERENT);
        if ($review && ! $different) {
            $lot->forceFill(['review_status' => $review, 'review_note' => $note])->save();

            return $review === 'queued' ? 'queued' : 'unmatched';
        }
        app(CreateRecordsFromSaleLots::class)->createForAdvertised($lot, $different ? $note : null);

        return 'created';
    }

    private static function note(array $a): string
    {
        $where = implode(', ', array_filter([$a['city'] ?? null, $a['state'] ?? null]));
        $bits = [(empty($a['horsebid']) ? 'Advertised on RanchWorldAds' : 'HorseBid lot advertised on RanchWorldAds').($where ? " ($where)" : '')];
        if (! empty($a['auction'])) {
            $bits[] = 'for bid at auction'.(! empty($a['sale_name']) ? ': '.mb_substr($a['sale_name'], 0, 80) : '')
                .(($a['price_cents'] ?? null) ? ' (ad shows $'.number_format($a['price_cents'] / 100).')' : '');
        } elseif (($a['price_cents'] ?? null) === null) {
            $bits[] = ! empty($a['price_text']) ? 'price shown as "'.mb_substr($a['price_text'], 0, 40).'"' : 'no price shown';
        }
        if (! empty($a['sold'])) {
            $bits[] = 'ad marked SOLD';
        }
        if ($a['status'] === 'gone') {
            $bits[] = 'ad no longer on RanchWorldAds as of '.$a['gone_at'];
        }

        return implode('; ', $bits);
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
