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
     * Remove everything loaded from a retired site: its sale lots, the records they created when nothing else
     * (another source's lot, a show record, an owner, a claim, a document, a transfer, a pedigree link) refers to them,
     * and then the ancestor records made for those records' pedigrees that are left unreferenced
     * (PruneOrphanAncestors, recursively). Records with other evidence are kept; only the retired site's lots go.
     * $dryRun does all of it inside a transaction and rolls back, so the counts are the real ones. Returns counts.
     */
    public static function purge(string $key, bool $dryRun = false): array
    {
        $src = DataSource::where('key', 'as-'.$key)->first();
        $out = ['dry_run' => $dryRun, 'lots' => 0, 'records_removed' => 0, 'records_kept' => 0, 'ancestors_removed' => 0];
        if (! $src || ! isset(self::RETIRED[$key])) {
            return $out;
        }
        $db = \Illuminate\Support\Facades\DB::class;
        $created = SaleLot::where('data_source_id', $src->id)->where('match_tier', 'created')->whereNotNull('horse_id')->pluck('horse_id')->unique()->values();
        $keep = $created->filter(fn ($id) => SaleLot::where('horse_id', $id)->where('data_source_id', '<>', $src->id)->exists()
            || $db::table('show_records')->where('horse_id', $id)->exists() || $db::table('stewardships')->where('horse_id', $id)->exists()
            || $db::table('claim_codes')->where('horse_id', $id)->exists() || $db::table('documents')->where('horse_id', $id)->exists()
            || $db::table('transfers')->where('horse_id', $id)->exists() || $db::table('pedigree_entries')->where('linked_horse_id', $id)->exists());
        $remove = $created->diff($keep)->values()->all();
        $lots = SaleLot::where('data_source_id', $src->id)->pluck('id')->all();
        $db::beginTransaction();
        try {
            // The ancestors their charts linked to, looked up before the lines go with the records.
            $ancestors = $db::table('pedigree_entries')->whereIn('horse_id', $remove)->whereNotNull('linked_horse_id')->pluck('linked_horse_id')->unique()->values()->all();
            $db::table('review_suggestions')->where(fn ($q) => $q->whereIn('horse_id', $remove)->orWhere(fn ($w) => $w->where('subject_type', 'sale_lot')->whereIn('subject_id', $lots)))->delete();
            $db::table('name_reviews')->where(fn ($q) => $q->whereIn('horse_a_id', $remove)->orWhereIn('horse_b_id', $remove)->orWhereIn('kept_horse_id', $remove))->delete();
            SaleLot::whereIn('id', $lots)->delete();
            \App\Models\Horse::whereIn('id', $remove)->get()->each->delete();
            $out['ancestors_removed'] = count(app(\App\Actions\Record\PruneOrphanAncestors::class)($ancestors));
            if (! $dryRun) {
                $src->forceFill(['permission' => 'not_allowed', 'terms_note' => 'Retired Oct 23 2026: '.self::RETIRED[$key].' Terms of Service restrict the site to personal noncommercial use and forbid copying or republishing; everything loaded from it was removed.'])->save();
            }
            $dryRun ? $db::rollBack() : $db::commit();
        } catch (\Throwable $e) {
            $db::rollBack();
            throw $e;
        }

        return ['lots' => count($lots), 'records_removed' => count($remove), 'records_kept' => $keep->count()] + $out;
    }

    /**
     * Richard, Oct 23 2026, for Premier Online: "separate the records and just keep the horses that are registered along
     * with their pedigrees and delete the sale information". So:
     *  - every sale lot of the retired site goes (prices, bids, dates, sale names, links, notes), with the timeline
     *    entries made from those lots, their review suggestions and staff notes that quote them;
     *  - a record the site created for a horse with no registration information (no registry, no number, not both sire
     *    and dam on any of its lots) goes, with its orphaned ancestors;
     *  - a record it created for a registered horse stays: registered name, registry, number, sex, color, foal year and
     *    pedigree, identity unverified. Its facts, identifiers and pedigree are already stored as "historical" with no
     *    link to a source. The audit log is append-only (a database trigger), so its past entries that name a lot or the
     *    site's address stay as written; they are counted (audit_entries_naming_site), and one new entry records the
     *    withdrawal;
     *  - a record with other evidence (another source's lot, a show record, an owner...) stays as it was, losing only the
     *    site's lots and the timeline entries made from them.
     * $dryRun does it all in a transaction and rolls back. Returns counts, including any reference left (should be 0).
     */
    public static function purgeKeepRegistered(string $key, bool $dryRun = false): array
    {
        $out = ['dry_run' => $dryRun, 'lots' => 0, 'timeline_entries' => 0, 'records_kept_registered' => 0, 'records_kept_other_evidence' => 0,
            'records_removed_unregistered' => 0, 'ancestors_removed' => 0, 'references_left' => 0, 'audit_entries_naming_site' => 0];
        $src = DataSource::where('key', 'as-'.$key)->first();
        if (! $src || ! isset(self::RETIRED[$key])) {
            return $out;
        }
        $db = \Illuminate\Support\Facades\DB::class;
        $lots = SaleLot::where('data_source_id', $src->id)->get();
        $lotIds = $lots->pluck('id')->all();
        $created = $lots->where('match_tier', 'created')->pluck('horse_id')->filter()->unique()->values();
        $other = $created->filter(fn ($id) => SaleLot::where('horse_id', $id)->where('data_source_id', '<>', $src->id)->exists()
            || $db::table('show_records')->where('horse_id', $id)->exists() || $db::table('stewardships')->where('horse_id', $id)->exists()
            || $db::table('claim_codes')->where('horse_id', $id)->exists() || $db::table('documents')->where('horse_id', $id)->exists()
            || $db::table('transfers')->where('horse_id', $id)->exists() || $db::table('pedigree_entries')->where('linked_horse_id', $id)->where('horse_id', '<>', $id)->exists())->values();
        $registered = $created->diff($other)->filter(fn ($id) => $lots->where('horse_id', $id)->contains(fn ($l) => $l->registry || preg_match('/\d/', (string) $l->registration_number) || ($l->sire_name && $l->dam_name)))->values();
        $remove = $created->diff($other)->diff($registered)->values()->all();
        $kept = $registered->all();
        $base = rtrim(self::RETIRED_BASE[$key] ?? '', '/');
        $saleNames = $lots->pluck('sale_name')->unique()->filter()->values()->all();

        $db::beginTransaction();
        try {
            // Timeline entries made from the lots (and any left on these records that quote the site).
            $events = $lots->pluck('horse_event_id')->filter()->all();
            $touched = $lots->pluck('horse_id')->filter()->unique()->all();
            $quoting = $db::table('horse_events')->whereIn('horse_id', $touched)->where(fn ($q) => $q->where('details', 'like', '%'.$base.'%')->orWhereIn('sale_venue', $lots->map(fn ($l) => $l->sale_name.($l->lot ? ', lot '.$l->lot : ''))->all()))->pluck('id')->all();
            $events = array_values(array_unique(array_merge($events, $quoting)));
            SaleLot::whereIn('id', $lotIds)->update(['horse_event_id' => null]);
            $out['timeline_entries'] = $db::table('horse_events')->whereIn('id', $events)->delete();
            $db::table('review_suggestions')->where(fn ($q) => $q->whereIn('horse_id', $remove)->orWhere(fn ($w) => $w->where('subject_type', 'sale_lot')->whereIn('subject_id', $lotIds)))->delete();
            $db::table('name_reviews')->where(fn ($q) => $q->whereIn('horse_a_id', $remove)->orWhereIn('horse_b_id', $remove)->orWhereIn('kept_horse_id', $remove))->delete();
            foreach ($saleNames as $name) { // staff notes on kept records: "Automatic: the <sale> lot contradicts …"
                $db::table('name_reviews')->where('note', 'like', '%'.$name.'%')->update(['note' => $db::raw("replace(note, 'the ".str_replace("'", "''", $name)." lot', 'a published lot')")]);
            }
            $out['lots'] = SaleLot::whereIn('id', $lotIds)->delete();
            $ancestors = $db::table('pedigree_entries')->whereIn('horse_id', $remove)->whereNotNull('linked_horse_id')->pluck('linked_horse_id')->unique()->values()->all();
            \App\Models\Horse::whereIn('id', $remove)->get()->each->delete();
            $out['ancestors_removed'] = count(app(\App\Actions\Record\PruneOrphanAncestors::class)($ancestors));
            foreach ($kept as $id) {
                \App\Support\Audit::record('evidence.source_withdrawn', \App\Models\Horse::find($id), null, ['note' => 'References to a retired published source were removed (Oct 23 2026); the record keeps its identity facts as unattributed historical evidence.'], $id);
            }
            $out['records_kept_registered'] = count($kept);
            $out['records_kept_other_evidence'] = $other->count();
            $out['records_removed_unregistered'] = count($remove);
            $out['references_left'] = self::references($kept, $lotIds, $base, $saleNames);
            // The audit log is append-only (a database trigger), so its past entries can't be edited: counted, not changed.
            $out['audit_entries_naming_site'] = self::auditReferences($kept, $lotIds, $base, $saleNames);
            if (! $dryRun) {
                $src->forceFill(['permission' => 'not_allowed', 'terms_note' => 'Retired Oct 23 2026: '.self::RETIRED[$key].' Terms of Service restrict the site to personal noncommercial use and forbid copying or republishing. Its sale information was removed; registered horses it introduced were kept as unattributed historical records (Richard, Oct 23 2026).'])->save();
            }
            $dryRun ? $db::rollBack() : $db::commit();
        } catch (\Throwable $e) {
            $db::rollBack();
            throw $e;
        }

        return $out;
    }

    /** Retired sites' addresses, to find references to them in kept evidence. */
    public const RETIRED_BASE = ['premieronline' => 'https://bid.premieronlinesales.com'];

    /** Audit entries on kept records that name the site, a lot or a sale (the log is append-only: these stay). */
    public static function auditReferences(array $horseIds, array $lotIds, string $base, array $saleNames): int
    {
        if (! $horseIds) {
            return 0;
        }
        $db = \Illuminate\Support\Facades\DB::class;

        return $db::table('audit_log')->whereIn('horse_id', $horseIds)->where(function ($w) use ($db, $base, $lotIds, $saleNames) {
            foreach (['after', 'before'] as $c) {
                $col = $db::raw("cast($c as text)");
                $w->orWhere($col, 'like', '%'.$base.'%');
                foreach ($saleNames as $sn) {
                    $w->orWhere($col, 'like', '%'.$sn.'%');
                }
                foreach (array_slice($lotIds, 0, 2000) as $l) {
                    $w->orWhere($col, 'like', '%'.$l.'%');
                }
            }
        })->count();
    }

    /** References to the site still on kept records (their timeline, facts, staff notes); the audit log is counted apart. */
    public static function references(array $horseIds, array $lotIds, string $base, array $saleNames): int
    {
        if (! $horseIds) {
            return 0;
        }
        $db = \Illuminate\Support\Facades\DB::class;
        $like = fn ($q, array $cols) => $q->where(function ($w) use ($cols, $base, $lotIds, $saleNames) {
            foreach ($cols as $c) {
                $w->orWhere($c, 'like', '%'.$base.'%');
                foreach ($saleNames as $s) {
                    $w->orWhere($c, 'like', '%'.$s.'%');
                }
                foreach (array_slice($lotIds, 0, 2000) as $l) {
                    $w->orWhere($c, 'like', '%'.$l.'%');
                }
            }
        });
        $n = SaleLot::whereIn('horse_id', $horseIds)->whereIn('id', $lotIds)->count();
        $n += $like($db::table('horse_events')->whereIn('horse_id', $horseIds), ['details', 'sale_venue', 'event_name', 'provider'])->count();
        $n += $like($db::table('horse_facts')->whereIn('horse_id', $horseIds), ['value', $db::raw('cast(meta as text)')])->count();
        $n += $like($db::table('name_reviews')->where(fn ($q) => $q->whereIn('horse_a_id', $horseIds)->orWhereIn('horse_b_id', $horseIds)), ['note'])->count();

        return $n;
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
