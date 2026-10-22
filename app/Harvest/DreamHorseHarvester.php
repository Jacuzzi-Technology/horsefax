<?php

namespace App\Harvest;

use App\Actions\Record\MatchSaleLots;
use App\Models\DataSource;
use App\Models\HorseIdentifier;
use App\Models\SaleLot;
use App\Support\Record;
use Illuminate\Support\Facades\Http;

/**
 * DreamHorse.com horse ads (Richard, Oct 22 2026). DreamHorse's terms take no license to seller content and have no
 * anti-scraping clause; robots.txt allows /ad/*.html and the facet list pages. Photos and descriptions stay the
 * sellers' copyright, so only facts are kept, with a link back to the ad.
 *
 * Finding the ads: sitemap.txt lists only the facet pages (state, color, breed ...), and each list shows its first
 * 40 ads; the "next page" buttons POST to /show_list_pages.php, which robots.txt disallows. So lists over 40 are split
 * into finer facets instead (state -> gender -> color -> breed, e.g. /bcgs/85/x/chestnut/mare/horses-for-sale-in-
 * ohio.html), and the "similar horses" links on each ad page add any stragglers. Every list's own total is kept, so
 * the run reports how many ads it could not reach.
 *
 * One request at a time, $delay seconds apart (house rule: at least 3), as HorsefaxBot. Errors back off (30 s doubling); a 403 or a bot
 * challenge stops the run (never worked around). Rerunnable: the raw file (ads.jsonl) is the state, ads are fetched
 * once (refreshed after $refreshDays), and ads that drop out of the lists are re-checked and marked gone.
 */
class DreamHorseHarvester
{
    public const BASE = 'https://www.dreamhorse.com';
    public const SALE_NAME = 'DreamHorse.com classified ad';
    public const PAGE = 40;
    private const GENDERS = ['mare', 'gelding', 'stallion', 'colt', 'filly'];

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    /** @var array<int, array> ad id => latest record */
    private array $ads = [];
    /** @var array<int, true> ids seen in this run's lists */
    private array $seen = [];
    private array $lists = [];
    private string $dir;
    private array $progress;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/dreamhorse');
        @mkdir($this->dir, 0775, true);
        $this->readState();
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'dreamhorse'], [
            'name' => 'DreamHorse.com', 'url' => self::BASE, 'permission' => 'terms_allow',
            'terms_note' => 'Checked Oct 22 2026: Dream Horse Classifieds LLC (Fort Collins CO) terms take no license to seller content and have no anti-scraping clause; robots.txt allows /ad/*.html and the facet list pages (not /show_list_pages.php, so lists are split by facet instead of paged). Facts only with a link back; photos, descriptions and seller contact details are never stored. Richard approved (Oct 22 2026).',
        ]);
    }

    /**
     * The whole run. Returns counts.
     *
     * @return array<string, int|float>
     */
    public function run(bool $discover = true, ?int $maxAds = null, int $refreshDays = 30): array
    {
        $out = ['list_pages' => 0, 'listed_total' => 0, 'ids_found' => 0, 'fetched' => 0, 'new' => 0, 'gone' => 0, 'not_ads' => 0];
        $complete = false;
        if ($discover) {
            [$out['list_pages'], $out['listed_total'], $complete] = $this->discover();
            $out['ids_found'] = count($this->seen);
        }
        $queue = array_keys($this->seen);
        rsort($queue);
        $fetched = 0;
        foreach ($queue as $i => $id) {
            if ($maxAds !== null && $fetched >= $maxAds) {
                break;
            }
            $a = $this->ads[$id] ?? null;
            $stale = $a && $a['status'] === 'live' && (time() - strtotime($a['fetched_at'])) > $refreshDays * 86400;
            if ($a && ! $stale && $a['status'] !== 'gone') {
                $this->touch($id);
                continue;
            }
            $this->fetchAd($id, $out);
            $fetched++;
            // Ads link three similar horses: follow any not found through the lists.
            foreach ($this->ads[$id]['similar'] ?? [] as $other) {
                if (! isset($this->seen[$other])) {
                    $this->seen[$other] = true;
                    $queue[] = $other;
                }
            }
            if ($fetched % 100 === 0) {
                $this->say("$fetched ads fetched, ".count($this->ads).' on file, '.$this->requests.' requests');
            }
        }
        $out['ids_found'] = count($this->seen);
        // Only after a full listing: re-check ads that dropped out, mark the gone ones.
        if ($complete && $maxAds === null) {
            foreach ($this->ads as $id => $a) {
                if ($a['status'] === 'live' && ! isset($this->seen[$id])) {
                    $this->fetchAd($id, $out);
                }
            }
        }
        $out['fetched'] = $fetched;
        $out['requests'] = $this->requests;
        $this->compact();
        $out += $this->export();

        return $out;
    }

    /**
     * Read the facet lists from the sitemap down. Returns [pages read, ads the site says it lists, finished?].
     *
     * @return array{0:int, 1:int, 2:bool}
     */
    public function discover(): array
    {
        $sitemap = $this->get(self::BASE.'/sitemap.txt');
        if ($sitemap === null) {
            throw new \RuntimeException('DreamHorse sitemap.txt unavailable');
        }
        preg_match_all('#/s/horses-for-sale-in-([a-z0-9-]+)\.html#', $sitemap, $s);
        preg_match_all('#/c/([a-z0-9-]+)/horses-for-sale\.html#', $sitemap, $c);
        preg_match_all('#/b/(\d+)/[a-z0-9-]+/horses-for-sale\.html#', $sitemap, $b);
        $states = array_unique($s[1]);
        // Colors and breeds are read nationally first: one whose whole list fits on a page needs no finer split.
        $big = ['color' => [], 'breed' => []];
        $colorTotal = 0;
        foreach (array_unique($c[1]) as $color) {
            $t = $this->list("/c/$color/horses-for-sale.html");
            $colorTotal += $t ?? 0;
            if ($t === null || $t > self::PAGE) {
                $big['color'][] = $color;
            }
        }
        foreach (array_unique($b[1]) as $breed) {
            $t = $this->list("/b/$breed/x/horses-for-sale.html");
            if ($t === null || $t > self::PAGE) {
                $big['breed'][] = $breed;
            }
        }
        foreach (['new', 'spotlights', 'videos', 'studs', 'lease', 'trade', 'gaited', 'warmbloods', 'auction', 'free', 'sold', 'adoption'] as $l) {
            $this->list("/list-horses/$l.html");
        }
        $stateTotal = 0;
        $missed = 0;
        foreach ($states as $state) {
            $in = "horses-for-sale-in-$state.html";
            $t = $this->list("/s/$in");
            $stateTotal += $t ?? 0;
            if ($t === null || $t <= self::PAGE) {
                continue;
            }
            foreach (self::GENDERS as $g) {
                $tg = $this->list("/gs/$g/$in");
                if (! $tg || $tg <= self::PAGE) {
                    continue;
                }
                foreach ($big['color'] as $color) {
                    $tc = $this->list("/cgs/$color/$g/$in");
                    if (! $tc || $tc <= self::PAGE) {
                        continue;
                    }
                    foreach ($big['breed'] as $breed) {
                        $tb = $this->list("/bcgs/$breed/x/$color/$g/$in");
                        if ($tb > self::PAGE) {
                            $missed += $tb - self::PAGE;
                            $this->say("still over a page: /bcgs/$breed/x/$color/$g/$in ($tb)");
                        }
                    }
                }
            }
        }
        $this->lists['_summary'] = ['date' => now()->toDateString(), 'state_total' => $stateTotal, 'color_total' => $colorTotal, 'missed_in_big_lists' => $missed, 'requests' => $this->requests];
        file_put_contents($this->dir.'/discovery-'.now()->format('Y-m-d').'.json', json_encode($this->lists, JSON_PRETTY_PRINT));
        @unlink($this->dir.'/lists-progress.jsonl'); // listing finished: the next run reads fresh
        $this->say("lists read: state total $stateTotal, color total $colorTotal, ".count($this->seen).' ids found');

        return [count($this->lists) - 1, max($stateTotal, $colorTotal), true];
    }

    /** One list page: its ids join $seen; returns the list's total (null if the page failed). */
    private function list(string $path): ?int
    {
        // A stopped run resumes: list pages read in the last 2 days are taken from the progress file.
        if (! isset($this->progress)) {
            $this->progress = [];
            foreach (is_file($f = $this->dir.'/lists-progress.jsonl') ? file($f) : [] as $line) {
                if (($l = json_decode($line, true)) && strtotime($l['at']) > time() - 2 * 86400) {
                    $this->progress[$l['path']] = $l;
                }
            }
        }
        if ($p = $this->progress[$path] ?? null) {
            unset($p['at'], $p['path']);
        } else {
            $html = $this->get(self::BASE.$path);
            if ($html === null) {
                $this->lists[$path] = ['total' => null];

                return null;
            }
            $p = DreamHorseParser::listPage($html);
            // Evidence copy: list pages carry no seller descriptions or contact details.
            $ev = $this->dir.'/evidence/'.now()->format('Y-m-d');
            @mkdir($ev, 0775, true);
            file_put_contents($ev.'/'.sha1($path).'.html.gz', gzencode($html, 9));
            file_put_contents($this->dir.'/lists-progress.jsonl', json_encode(['path' => $path, 'at' => now()->toIso8601String()] + $p, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        }
        foreach ($p['ids'] as $id) {
            $this->seen[$id] = true;
        }
        $this->lists[$path] = ['total' => $p['total'], 'ids' => count($p['ids'])];
        if (count($this->lists) % 50 === 0) {
            $this->say(count($this->lists).' list pages read, '.count($this->seen).' ids found (last: '.$path.')');
        }

        return $p['total'] ?? count($p['ids']);
    }

    private function fetchAd(int $id, array &$out): void
    {
        $html = $this->get(self::BASE."/ad/$id.html");
        $now = now()->toIso8601String();
        $prev = $this->ads[$id] ?? null;
        if ($html === null || DreamHorseParser::gone($html)) {
            if ($prev && $prev['status'] === 'live') {
                $this->put($id, ['status' => 'gone', 'gone_at' => now()->toDateString(), 'checked_at' => $now] + $prev);
                $out['gone']++;
            } elseif (! $prev) {
                $this->put($id, ['ad_id' => $id, 'status' => 'gone', 'gone_at' => now()->toDateString(), 'checked_at' => $now, 'first_seen' => now()->toDateString()]);
            }

            return;
        }
        $facts = DreamHorseParser::ad($html, $id);
        if ($facts === null) {
            $this->put($id, ['ad_id' => $id, 'status' => 'not_ad', 'checked_at' => $now]);
            $out['not_ads']++;

            return;
        }
        preg_match_all('#href="/ad/(\d+)\.html"#', $html, $m);
        $facts['similar'] = array_values(array_diff(array_unique(array_map('intval', $m[1])), [$id]));
        $facts += ['status' => 'live', 'fetched_at' => $now, 'checked_at' => $now, 'gone_at' => null,
            'first_seen' => $prev['first_seen'] ?? now()->toDateString(), 'last_seen' => now()->toDateString()];
        $this->put($id, $facts);
        if (! $prev || $prev['status'] !== 'live') {
            $out['new']++;
        }
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
            if ($status === 200 && ! preg_match('/cf-chl|challenge-platform|captcha|Just a moment\.\.\./i', substr($body, 0, 20000))) {
                return $body;
            }
            if ($status === 404) {
                return null;
            }
            if ($status === 403 || $status === 200) {
                $this->compact();
                throw new DreamHorseBlocked("Blocked at $url (HTTP $status): stopping. No workaround; ask DreamHorse to allow HorsefaxBot.");
            }
            if ($attempt >= 5) {
                $this->say("giving up on $url after $attempt retries (HTTP $status)");

                return null;
            }
            $this->say("HTTP $status at $url; waiting {$backoff}s");
            sleep($backoff);
        }
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

    public const CSV = ['ad_id', 'url', 'status', 'name', 'barn_name', 'breed', 'registered', 'registry', 'registration_number', 'sex', 'color',
        'markings', 'foal_year', 'foal_month', 'height_hands', 'weight_lbs', 'gaited', 'warmblood', 'for_sale', 'for_lease', 'may_trade',
        'price_cents', 'currency', 'price_text', 'sold', 'city', 'state', 'zip', 'country', 'seller_name', 'ad_created', 'ad_renewed', 'ad_expires',
        'sire', 'dam', 'dam_sire', 'pedigree', 'first_seen', 'last_seen', 'fetched_at', 'gone_at'];

    /** dreamhorse-ads.json and .csv beside the raw file. */
    public function export(): array
    {
        $ads = array_values(array_filter($this->ads, fn ($a) => in_array($a['status'], ['live', 'gone'], true) && isset($a['url'])));
        file_put_contents($this->dir.'/dreamhorse-ads.json', json_encode($ads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $fh = fopen($this->dir.'/dreamhorse-ads.csv', 'w');
        fputcsv($fh, self::CSV);
        foreach ($ads as $a) {
            $ped = $a['pedigree'] ?? [];
            $row = $a + ['sire' => $ped['s'] ?? null, 'dam' => $ped['d'] ?? null, 'dam_sire' => $ped['ds'] ?? null];
            $row['pedigree'] = $ped ? json_encode($ped, JSON_UNESCAPED_UNICODE) : null;
            fputcsv($fh, array_map(fn ($k) => is_bool($row[$k] ?? null) ? ($row[$k] ? 'yes' : 'no') : ($row[$k] ?? null), self::CSV));
        }
        fclose($fh);

        return [
            'ads_on_file' => count($ads),
            'live' => count(array_filter($ads, fn ($a) => $a['status'] === 'live')),
            'with_reg_number' => count(array_filter($ads, fn ($a) => ! empty($a['registration_number']))),
        ];
    }

    /**
     * Load the raw file into sale_lots as advertised asking prices (held, like HOMD's: not published yet), then
     * attach to a record only on a registration number match. Never on name. Returns counts.
     */
    public function load(): array
    {
        $source = $this->source();
        $out = ['loaded' => 0, 'skipped_not_for_sale' => 0, 'with_reg_number' => 0, 'matched' => 0, 'conflict' => 0, 'proposed_match' => 0,
            'created_records' => 0, 'linked_same_horse' => 0, 'queued_same_name' => 0, 'unmatched' => 0, 'already_matched' => 0, 'already_in_review' => 0];
        foreach ($this->ads as $a) {
            if (! in_array($a['status'], ['live', 'gone'], true) || ! isset($a['url'])) {
                continue;
            }
            if ($a['for_sale'] === false) {
                $out['skipped_not_for_sale']++;
                continue;
            }
            $ped = $a['pedigree'] ?? [];
            $name = $a['name'] ?: $a['barn_name'] ?: $a['title'];
            $lot = SaleLot::record($source->id, (string) $a['ad_id'], [
                'sale_name' => self::SALE_NAME, 'sale_date' => $a['ad_created'] ?? $a['ad_renewed'] ?? $a['first_seen'], 'date_precision' => 'day',
                'horse_name' => mb_substr(NrchaHarvester::display($name) ?? $name, 0, 255), 'registered' => $a['registered'],
                'registry' => self::registry($a['registry'] ?? null), 'registration_number' => $a['registration_number'],
                'foal_year' => $a['foal_year'], 'sex' => $a['sex'], 'color' => $a['color'], 'breed' => $a['breed'],
                'sire_name' => $ped['s'] ?? null, 'dam_name' => $ped['d'] ?? null, 'dam_sire_name' => $ped['ds'] ?? null, 'pedigree' => $ped ?: null,
                'status' => 'asking', 'price_cents' => $a['price_cents'], 'currency' => in_array($a['currency'] ?? null, ['USD', 'CAD'], true) ? $a['currency'] : 'USD',
                'buyers_premium' => 'none', 'note' => mb_substr(self::note($a), 0, 255), 'source_url' => $a['url'],
            ]);
            $out['loaded']++;
            $out['with_reg_number'] += $lot->registration_number ? 1 : 0;
            if ($lot->horse_id || $lot->review_status) {
                $out[$lot->horse_id ? 'already_matched' : 'already_in_review']++; // a rerun leaves earlier decisions alone
                continue;
            }
            if ($lot->registration_number && ($r = $this->matchRegistration($lot)) !== 'unmatched') {
                $out[$r]++;
                continue;
            }
            // Richard, Oct 22 2026: an ad giving a registered name gets an unverified record (shared rule), but only when
            // the title reads as one: "NAME (Barn)" or a registration number, never an ad headline.
            $r = self::namesHorse($a) ? AdvertisedRegisteredName::apply($lot, 'DreamHorse') : 'unmatched';
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
        $ids = MatchSaleLots::matchable(\App\Models\Horse::whereIn('id', $ids))->pluck('id');
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
     * Does the ad's title give the horse's own name? Sellers type the title, so "Performance bred mares for sale!" or
     * "Beginner Safe Gelding" turn up there. A name counts when the ad gives a different barn name in brackets ("BELL'S
     * MILLIE OF THE RIDGE (Lady)") or a registration number, and it reads as a name, not a headline.
     */
    public static function namesHorse(array $a): bool
    {
        $name = trim((string) ($a['name'] ?? ''));
        $distinctBarn = ! empty($a['barn_name']) && strcasecmp($a['barn_name'], $name) !== 0;
        if ($name === '' || (! $distinctBarn && empty($a['registration_number']))) {
            return false;
        }

        return ! preg_match('/[!?]|\b(for sale|sale|sold|mares?|geldings?|stallions?|fill(y|ies)|colts?|horses?|ponies|pony|registered|broke|safe|prospect|beginner|kid|lease|trade|reduced|price|obo|\d+\s*(yo|year))\b/i', $name);
    }

    /** The association as DreamHorse sellers type it, in Horsefax's spelling where there is one. */
    public static function registry(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '' || preg_match('/^(n\/?a|none|other|unknown|-+)$/i', $s)) {
            return null;
        }

        return LiveAgParser::registry($s) ?? mb_substr($s, 0, 60);
    }

    private static function note(array $a): string
    {
        $where = implode(', ', array_filter([$a['city'] ?? null, $a['state'] ?? null]));
        $bits = ['Advertised on DreamHorse'.($where ? " ($where)" : '')];
        if (($a['price_cents'] ?? null) === null) {
            $bits[] = $a['price_text'] ? 'price shown as "'.mb_substr($a['price_text'], 0, 60).'"' : 'no price shown';
        }
        if (! empty($a['sold'])) {
            $bits[] = 'seller marked it sold';
        }
        if ($a['status'] === 'gone') {
            $bits[] = 'ad no longer on DreamHorse as of '.$a['gone_at'];
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
