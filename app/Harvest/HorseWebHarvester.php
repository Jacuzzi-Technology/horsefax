<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * HorseWeb.com classified ads (horses for sale in the US since 1995; Texas), found Oct 22 2026 while surveying
 * EquineNow alternatives under Richard's standing approval. robots.txt shuts out only named SEO bots (Ahrefs, Semrush
 * and similar), not HorsefaxBot; the Terms and Conditions have no scraping, crawling, database or reuse clause; no bot
 * wall.
 *
 * The listing (/horses/N/, 30 ads a page, newest first, about 1,470 pages) gives each ad's name, breed, gender, age,
 * color, state, asking price and status tag (Sold, Stud). Pages past the last repeat the last page, so the run stops
 * when a page brings no new ad. An ad page adds Registry, Reg Number and Foal Date: it is fetched only for ads whose
 * Name has two or more words (a one-word name can't be a registered name under Richard's rule, so its page adds nothing
 * the loader uses), once, and kept.
 *
 * Facts only, with a link back: description, seller, phone, location detail and photos are never stored; the kept
 * HTML (local only, $dir) holds the seller's text and is never loaded. One request at a time, $delay seconds apart (at
 * least 3), as HorsefaxBot; a 403/429 or a bot check stops the run. State: ads.jsonl (one line per ad, latest wins).
 */
class HorseWebHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://www.horseweb.com';
    public const SALE_NAME = 'HorseWeb.com classified ad';

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    /** @var array<string, array> */
    private array $ads = [];
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY);
        }
        $this->dir = $dir ?? storage_path('app/private/horseweb');
        @mkdir($this->dir.'/html', 0775, true);
        if (is_file($this->dir.'/ads.jsonl')) {
            foreach (file($this->dir.'/ads.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $a = json_decode($line, true);
                if (isset($a['ad_id'])) {
                    $this->ads[$a['ad_id']] = $a;
                }
            }
        }
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'horseweb'], [
            'name' => 'HorseWeb.com', 'url' => self::BASE, 'permission' => 'terms_allow',
            'terms_note' => 'Checked Oct 22 2026: robots.txt blocks only named SEO bots; Terms and Conditions have no scraping, crawling, database or reuse clause; no bot protection. Facts only with a link back: descriptions, seller details, phone numbers and photos are never stored. Collected under Richard\'s standing approval (Oct 22 2026).',
        ]);
    }

    public static function adUrl(string $id): string
    {
        return self::BASE.'/horse-'.$id.'/';
    }

    public static function pageUrl(int $n): string
    {
        return self::BASE.'/horses/'.($n > 1 ? $n.'/' : '');
    }

    public static function needsAdPage(array $a): bool
    {
        return $a['name'] !== null && count(preg_split('/\s+/', trim($a['name']))) >= 2;
    }

    /** Read the listing (newest first) and the ad pages it calls for. Returns counts. */
    public function run(?int $maxPages = null, ?int $maxAds = null): array
    {
        $out = ['pages' => 0, 'listed' => 0, 'new' => 0, 'ad_pages' => 0];
        $today = now()->toDateString();
        $prev = null;
        for ($n = 1; $maxPages === null || $n <= $maxPages; $n++) {
            $html = $this->get(self::pageUrl($n));
            $rows = $html ? HorseWebParser::listing($html) : [];
            $ids = array_column($rows, 'ad_id');
            if (! $rows || $ids === $prev) {
                break; // past the last page (it repeats) or nothing returned
            }
            $prev = $ids;
            $out['pages']++;
            foreach ($rows as $r) {
                $out['listed']++;
                $old = $this->ads[$r['ad_id']] ?? null;
                $out['new'] += $old ? 0 : 1;
                $this->put($r + ['first_seen' => $old['first_seen'] ?? $today, 'last_seen' => $today, 'listed_on_page' => $n] + ($old ?? []));
            }
        }
        foreach ($this->ads as $id => $a) {
            if ($maxAds !== null && $out['ad_pages'] >= $maxAds) {
                break;
            }
            if (! self::needsAdPage($a) || isset($a['ad_fetched'])) {
                continue;
            }
            $html = $this->get(self::adUrl($id));
            if ($html === null) {
                $this->put(['ad_fetched' => $today, 'gone' => true] + $a);
                continue;
            }
            file_put_contents($this->dir.'/html/'.$id.'.html.gz', gzencode($html, 9));
            $this->put(['ad_fetched' => $today] + array_filter(HorseWebParser::ad($html), fn ($v) => $v !== null) + ['list_name' => $a['name']] + $a);
            $out['ad_pages']++;
        }
        $out['requests'] = $this->requests;

        return $out;
    }

    /** Load into sale_lots as advertised asking prices (never a sale), then place each ad. */
    public function load(): array
    {
        $src = $this->source();
        $out = ['loaded' => 0, 'skipped_stud' => 0, 'skipped_no_name' => 0, 'with_reg_number' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($this->ads as $id => $a) {
            if (strcasecmp((string) $a['tag'], 'Stud') === 0) {
                $out['skipped_stud']++; // a stallion standing at stud: a breeding fee, not a horse for sale
                continue;
            }
            $name = $a['name'] ?? null;
            if (! $name || preg_match('/\b(mule|donkey|burro|jenny|jack)s?\b/i', (string) $a['breed'])) {
                $out['skipped_no_name']++;
                continue;
            }
            $registry = AuctioneerSoftwareParser::registry((string) ($a['registry'] ?? '')) ?? (($a['registry'] ?? null) ? mb_substr($a['registry'], 0, 255) : null);
            $foalYear = $a['foal_year'] ?? (isset($a['age']) ? (int) substr($a['first_seen'], 0, 4) - $a['age'] : null);
            $sold = strcasecmp((string) $a['tag'], 'Sold') === 0 || strcasecmp((string) ($a['ad_status'] ?? ''), 'Sold') === 0;
            $lot = SaleLot::record($src->id, 'hw:'.$id, [
                'sale_name' => self::SALE_NAME, 'sale_date' => $a['first_seen'], 'date_precision' => 'day',
                'horse_name' => mb_substr(NrchaHarvester::display($name), 0, 255), 'registered' => $registry || ($a['registration_number'] ?? null) ? true : null,
                'registry' => $registry, 'registration_number' => $a['registration_number'] ?? null,
                'foal_year' => $foalYear, 'sex' => AuctioneerSoftwareParser::sex((string) $a['sex']), 'color' => $a['color'] ? mb_substr($a['color'], 0, 255) : null,
                'breed' => $a['breed'] ? mb_substr($a['breed'], 0, 255) : null,
                'status' => 'asking', 'price_cents' => $a['price_cents'], 'buyers_premium' => 'none', 'source_url' => self::adUrl($id),
                'note' => mb_substr('Advertised asking price on HorseWeb.com, first seen '.$a['first_seen'].($sold ? '; the ad is marked sold' : ''), 0, 255),
            ]);
            $out['loaded']++;
            $out['with_reg_number'] += $lot->registration_number ? 1 : 0;
            if ($lot->horse_id || ! in_array($lot->review_status, [null, RegistrationOnlyMatch::UNMATCHED], true)) {
                $out['already']++;
                continue;
            }
            $r = RegistrationOnlyMatch::apply($lot);
            if (! in_array($r, ['unmatched', 'no_number'], true)) {
                $out[$r]++;
                continue;
            }
            $out[AdvertisedRegisteredName::apply($lot, 'HorseWeb')]++;
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->ads);
    }

    private function put(array $a): void
    {
        $this->ads[$a['ad_id']] = $a;
        file_put_contents($this->dir.'/ads.jsonl', json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
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
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(90)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            if ($this->log) {
                ($this->log)($status.' '.$url);
            }
            $challenge = (bool) preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!|Access denied)|cf-chl-|id="challenge-form"/i', substr($body, 0, 20000));
            if ($status === 200 && ! $challenge) {
                return $body;
            }
            if (in_array($status, [403, 429], true) || ($status === 200 && $challenge)) {
                throw new HarvestBlocked("Blocked or rate-limited at $url (HTTP $status): stopping. No workaround.");
            }
            if ($status === 404 || $attempt >= 3) {
                return null;
            }
            sleep(app()->runningUnitTests() ? 0 : $backoff);
        }
    }
}
