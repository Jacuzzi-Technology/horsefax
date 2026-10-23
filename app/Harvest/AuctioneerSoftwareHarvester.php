<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Horse auctions run on AuctioneerSoftware (auctioneersoftware.com), found Oct 22 2026 under Richard's standing
 * approval ("continue to search for allowable sites ... scraping the available data and adding the sites"). Every site
 * below was checked that day: the platform's robots.txt allows everything but /admin/, /api/ and /asset/ (the JSON
 * /api/ is never used: lot lists are read from the server-rendered pages), no bot wall, and neither the platform's
 * terms nor the sale company's terms page has a scraping, crawling or database clause. Each site's Privacy Policy page
 * is read too: Premier Online's carries Terms of Service that forbid reuse, so it was retired (RETIRED, purge()).
 *
 * Per site: /sitemap/past-auctions lists the finished auctions; /sitemap/auction/{id} lists every lot in one; the lot
 * list is read 125 at a time (?pageSize=125&page=N); one lot page gives the sale day ("Start Time"). Finished auctions
 * are fetched once and kept gzipped in $dir, so reruns only fetch auctions that are new.
 *
 * Facts only, with a link back to the lot: name, foal year (or age at the sale), sex, color, breed/registry, sire, dam,
 * lot, sale, sale day, the result and the price. Never kept: consignor, trainer, fitter, phone, email, location,
 * bidder, the "Story"/description text, photos and video (AuctioneerSoftwareParser::DROP). One request at a time,
 * $delay seconds apart (at least 3), as HorsefaxBot; a 403/429 or a bot check stops the run (never worked around).
 */
class AuctioneerSoftwareHarvester
{
    public const MIN_DELAY = 3.0;

    private const CHECKED = 'Checked Oct 22 2026: AuctioneerSoftware platform robots.txt allows all but /admin/, /api/, /asset/ (the /api/ is never used); no bot wall; the sale\'s terms page and the platform\'s privacy/terms page have no scraping, crawling, database or reuse clause. Facts only with a link back: consignor, trainer, contact details, bidders, descriptions and photos are never stored. Collected under Richard\'s standing approval (Oct 22 2026).';

    /** key => [name, base, horse-only site?, currency (USD unless given), day-first dates?] */
    public const SITES = [
        'bigsky' => ['name' => 'Big Sky Horse Sales', 'base' => 'https://bid.bigskyhorsesales.com', 'horses_only' => true],
        'montanaselect' => ['name' => 'Montana Select Horse Sales', 'base' => 'https://www.montanaselectsales.com', 'horses_only' => true],
        'eugene' => ['name' => 'Eugene Horse Auction', 'base' => 'https://www.eugenehorseauction.com', 'horses_only' => true],
        'waverly' => ['name' => 'Waverly Sales Company (Midwest Horse Sale)', 'base' => 'https://www.waverlysales.bid', 'horses_only' => false],
        'mthope' => ['name' => 'Mt. Hope Auction (Mid-Ohio sales)', 'base' => 'https://bid.mthopeauction.com', 'horses_only' => false],
        'cavan' => ['name' => 'Cavan Equestrian (Cavan Horse & Pony Sales, Ireland)', 'base' => 'https://bid.cavanequestrian.com', 'horses_only' => true, 'currency' => 'EUR', 'day_first' => true],
        'goresbridge' => ['name' => 'Goresbridge Horse Sales (Ireland)', 'base' => 'https://www.goresbridgeonlineauctions.com', 'horses_only' => true, 'currency' => 'EUR', 'day_first' => true],
    ];

    /**
     * Sites collected once and then found not allowed: never collected again; purge() removes what was loaded.
     * premieronline: its Terms of Service (posted under bid.premieronlinesales.com/privacy) restrict the site to
     * personal noncommercial use and forbid copying or republishing its materials (found Oct 23 2026).
     */
    public const RETIRED = ['premieronline' => 'Premier Online Horse Sales (MM Auction Services)'];

    /** Auctions that aren't horse sales even on a horse-sale site. */
    public const NOT_HORSES = '/equipment|\btack\b|carriage|furniture|stock ?dog|stallion[- ](?:service|auction|breeding)|breeding|semen|fundraiser|relief|custom-(auction|event)|host-your|\btest\b|machinery|antique|steam|produce|hay-|cattle|dairy|herd-dispersal|sheep|goat|pig|consignment-form|alternative-animal|exotic|bird|giraffe|zebra|camel/';

    /** On mixed sale-barn sites, an auction must say it's a horse sale. */
    public const HORSE_WORDS = '/horse|pony|ponies|draft|belgian|percheron|friesian|ha(l)?flinger|fjord|crossbred|gypsy|standardbred|morgan|saddlebred|hackney/';

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    private string $dir;
    private array $site;

    public function __construct(private string $key, private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        $this->site = self::SITES[$key] ?? throw new \InvalidArgumentException("Unknown AuctioneerSoftware site $key");
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY); // house rule: at least 3 s between requests (faked HTTP in tests)
        }
        $this->dir = ($dir ?? storage_path('app/private/auctioneersoftware')).'/'.$key;
        @mkdir($this->dir.'/pages', 0775, true);
    }

    /**
     * Remove everything loaded from a retired site: its sale lots, and the records they created when nothing else
     * (another source's lot, a show record, an owner, a claim, a document, a transfer, a pedigree link) refers to them.
     * Records with other evidence are kept; only the retired site's lots go. Returns counts.
     */
    public static function purge(string $key): array
    {
        $src = DataSource::where('key', 'as-'.$key)->first();
        if (! $src || ! isset(self::RETIRED[$key])) {
            return ['lots' => 0, 'records_removed' => 0, 'records_kept' => 0];
        }
        $db = \Illuminate\Support\Facades\DB::class;
        $created = SaleLot::where('data_source_id', $src->id)->where('match_tier', 'created')->whereNotNull('horse_id')->pluck('horse_id')->unique()->values();
        $keep = $created->filter(fn ($id) => SaleLot::where('horse_id', $id)->where('data_source_id', '<>', $src->id)->exists()
            || $db::table('show_records')->where('horse_id', $id)->exists() || $db::table('stewardships')->where('horse_id', $id)->exists()
            || $db::table('claim_codes')->where('horse_id', $id)->exists() || $db::table('documents')->where('horse_id', $id)->exists()
            || $db::table('transfers')->where('horse_id', $id)->exists() || $db::table('pedigree_entries')->where('linked_horse_id', $id)->exists());
        $remove = $created->diff($keep)->values()->all();
        $lots = SaleLot::where('data_source_id', $src->id)->pluck('id')->all();
        $db::transaction(function () use ($db, $remove, $lots) {
            $db::table('review_suggestions')->where(fn ($q) => $q->whereIn('horse_id', $remove)->orWhere(fn ($w) => $w->where('subject_type', 'sale_lot')->whereIn('subject_id', $lots)))->delete();
            $db::table('name_reviews')->where(fn ($q) => $q->whereIn('horse_a_id', $remove)->orWhereIn('horse_b_id', $remove)->orWhereIn('kept_horse_id', $remove))->delete();
            SaleLot::whereIn('id', $lots)->delete();
            \App\Models\Horse::whereIn('id', $remove)->get()->each->delete();
        });
        $src->forceFill(['permission' => 'not_allowed', 'terms_note' => 'Retired Oct 23 2026: '.self::RETIRED[$key].' Terms of Service restrict the site to personal noncommercial use and forbid copying or republishing; everything loaded from it was removed.'])->save();

        return ['lots' => count($lots), 'records_removed' => count($remove), 'records_kept' => $keep->count()];
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'as-'.$this->key], [
            'name' => $this->site['name'], 'url' => $this->site['base'], 'permission' => 'terms_allow', 'terms_note' => self::CHECKED,
        ]);
    }

    public function base(): string
    {
        return $this->site['base'];
    }

    /** Fetch every finished auction not already kept. Returns counts. */
    public function collect(?int $maxAuctions = null): array
    {
        $out = ['auctions_listed' => 0, 'skipped_not_horses' => 0, 'fetched' => 0, 'cached' => 0, 'requests' => 0];
        $sitemap = $this->get($this->site['base'].'/sitemap/past-auctions', 'past-auctions', fresh: true) ?? '';
        preg_match_all('#/sitemap/auction/(\d+)#', $sitemap, $m);
        $ids = array_values(array_unique($m[1]));
        $out['auctions_listed'] = count($ids);
        foreach ($ids as $id) {
            if ($maxAuctions !== null && $out['fetched'] >= $maxAuctions) {
                break;
            }
            if (is_file($this->path("auction-$id.done"))) {
                $out['cached']++;
                continue;
            }
            $lotsMap = $this->get($this->site['base']."/sitemap/auction/$id", "sitemap-$id") ?? '';
            if (! preg_match('#/auctions/'.$id.'-([a-z0-9-]+)#', $lotsMap, $s)) {
                continue;
            }
            $slug = $id.'-'.$s[1];
            $horses = $this->isHorseSale($slug);
            if (! $horses && ! preg_match(self::NOT_HORSES, $slug)) {
                // A mixed sale barn's title may not say what sold ("Friday, March 28th Auction"): look at its lots.
                $first = $this->get($this->site['base']."/auctions/$slug?pageSize=125&page=1", "list-$id-1");
                $horses = $first !== null && self::mostlyHorses(AuctioneerSoftwareParser::lots($first));
            }
            if (! $horses) {
                @unlink($this->path("list-$id-1.html.gz"));
                $out['skipped_not_horses']++;
                file_put_contents($this->path("auction-$id.done"), 'not a horse sale: '.$slug);
                continue;
            }
            $lotIds = AuctioneerSoftwareParser::sitemapLots($lotsMap);
            $seen = [];
            for ($page = 1; $page <= max(1, (int) ceil(count($lotIds) / 125)) + 1; $page++) {
                $html = $this->get($this->site['base']."/auctions/$slug?pageSize=125&page=$page", "list-$id-$page");
                $lots = $html ? AuctioneerSoftwareParser::lots($html) : [];
                $new = array_diff(array_column($lots, 'lot_id'), $seen);
                if (! $new) {
                    @unlink($this->path("list-$id-$page.html.gz")); // past the last page (the site repeats page 1 or shows none)
                    break;
                }
                $seen = array_merge($seen, $new);
                if (count($seen) >= count($lotIds)) {
                    break;
                }
            }
            // The sale day: the first lot page's "Start Time".
            if ($first = $lotIds[0] ?? null) {
                $this->get($this->site['base']."/auctions/$id/lot/$first", "lot-$id");
            }
            $missing = count(array_diff($lotIds, $seen));
            file_put_contents($this->path("auction-$id.done"), json_encode(['slug' => $slug, 'lots' => count($lotIds), 'read' => count($seen), 'missing' => $missing]));
            $out['fetched']++;
        }
        $out['requests'] = $this->requests;

        return $out;
    }

    public function isHorseSale(string $slug): bool
    {
        return ! preg_match(self::NOT_HORSES, $slug) && ($this->site['horses_only'] || preg_match(self::HORSE_WORDS, $slug));
    }

    /** Most lots on the page describe a horse (a sex, a breed field, a "Horse Name" field or a horse word in the title). */
    public static function mostlyHorses(array $lots): bool
    {
        if (! $lots) {
            return false;
        }
        $horses = 0;
        foreach ($lots as $l) {
            $keys = strtolower(implode(' ', array_keys($l['fields'])));
            $horses += (str_contains($keys, 'horse name') || str_contains($keys, 'breed') || str_contains($keys, 'gender') || preg_match('/\bsex\b/', $keys)
                || AuctioneerSoftwareParser::sex($l['title']) || preg_match(self::HORSE_WORDS, strtolower($l['title']))) ? 1 : 0;
        }

        return $horses * 2 >= count($lots);
    }

    /** Every lot of every kept auction, as rows for sale_lots. */
    public function rows(): array
    {
        $rows = [];
        foreach (glob($this->dir.'/pages/auction-*.done') as $done) {
            $info = json_decode(file_get_contents($done), true);
            if (! is_array($info) || preg_match(self::NOT_HORSES, $info['slug'])) {
                continue; // not a horse sale (or excluded since it was kept)
            }
            $id = (int) preg_replace('/\D/', '', basename($done));
            $first = $this->read("list-$id-1");
            if ($first === null) {
                continue;
            }
            $title = trim(preg_replace('/\s+-\s+[^-]+$/', '', (string) AuctioneerSoftwareParser::pageTitle($first))); // drop " - <company>"
            $date = ($lotPage = $this->read("lot-$id")) ? AuctioneerSoftwareParser::startDate($lotPage, $this->site['day_first'] ?? false) : null;
            $date ??= self::dateFromText(AuctioneerSoftwareParser::clean($first));
            $year = $date ? (int) substr($date, 0, 4) : null;
            $lots = [];
            for ($page = 1; ($html = $this->read("list-$id-$page")) !== null; $page++) {
                foreach (AuctioneerSoftwareParser::lots($html) as $l) {
                    $lots[$l['lot_id']] = $l;
                }
            }
            foreach ($lots as $l) {
                $h = AuctioneerSoftwareParser::horse($l, $year);
                if (AuctioneerSoftwareParser::notAHorse($l, $h)) {
                    continue;
                }
                [$status, $price, $note] = AuctioneerSoftwareParser::result($l);
                $rows[] = $h + ['auction_id' => $id, 'sale_name' => $this->site['name'].': '.$title, 'sale_date' => $date, 'lot' => $l['lot'], 'lot_id' => $l['lot_id'],
                    'status' => $status, 'price_cents' => $price, 'note' => $note,
                    'source_url' => $l['url'] ? $this->site['base'].$l['url'] : $this->site['base'].'/auctions/'.$info['slug']];
            }
        }

        return $rows;
    }

    /** "Thursday, October 8th 2026" in an auction's description, when no lot page gave a day. */
    public static function dateFromText(string $text): ?string
    {
        if (preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(20\d\d)\b/', $text, $m)) {
            return date('Y-m-d', strtotime("$m[1] $m[2] $m[3]"));
        }

        return null;
    }

    /** Load into sale_lots and place each lot (registration number, then the registered-name rule). */
    public function load(): array
    {
        $src = $this->source();
        $out = ['loaded' => 0, 'skipped_no_date' => 0, 'sold' => 0, 'high_bid' => 0, 'no_sale' => 0, 'offered' => 0, 'catalog' => 0,
            'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($this->rows() as $r) {
            if (! $r['sale_date']) {
                $out['skipped_no_date']++;
                continue;
            }
            $lot = SaleLot::record($src->id, 'as:'.$r['auction_id'].':'.$r['lot_id'], [
                'sale_name' => mb_substr($r['sale_name'], 0, 255), 'sale_date' => $r['sale_date'], 'date_precision' => 'day', 'lot' => mb_substr($r['lot'], 0, 255),
                'horse_name' => mb_substr(NrchaHarvester::display($r['name']), 0, 255), 'registered' => $r['registered'], 'registry' => $r['registry'],
                'foal_year' => $r['foal_year'], 'sex' => $r['sex'], 'color' => $r['color'] ? mb_substr($r['color'], 0, 255) : null, 'breed' => $r['breed'] ? mb_substr($r['breed'], 0, 255) : null,
                'sire_name' => $r['sire'] ? mb_substr($r['sire'], 0, 255) : null, 'dam_name' => $r['dam'] ? mb_substr($r['dam'], 0, 255) : null,
                'status' => $r['status'], 'price_cents' => $r['price_cents'], 'currency' => $this->site['currency'] ?? 'USD', 'buyers_premium' => 'unknown', 'source_url' => mb_substr($r['source_url'], 0, 600),
                'note' => $r['note'] ? mb_substr($r['note'].' ('.$this->site['name'].')', 0, 255) : null,
            ]);
            $out['loaded']++;
            $out[$r['status']]++;
            $out[RegistrationOnlyMatch::place($lot, $this->site['name'])]++;
        }

        return $out;
    }

    private function path(string $name): string
    {
        return $this->dir.'/pages/'.$name;
    }

    private function read(string $name): ?string
    {
        $f = $this->path($name.'.html.gz');

        return is_file($f) ? gzdecode(file_get_contents($f)) : null;
    }

    /** A page, from the kept copy unless $fresh; kept gzipped. Null on 404 or repeated failure. */
    private function get(string $url, string $name, bool $fresh = false): ?string
    {
        if (! $fresh && ($kept = $this->read($name)) !== null) {
            return $kept;
        }
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
            $this->say($status.' '.$url);
            $challenge = (bool) preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!|Access denied)|cf-chl-|id="challenge-form"|captcha-container/i', substr($body, 0, 20000));
            if ($status === 200 && ! $challenge) {
                file_put_contents($this->path($name.'.html.gz'), gzencode($body, 9));

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

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
