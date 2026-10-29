<?php

namespace App\Harvest;

use App\Actions\Record\AddPublishedIdentity;
use App\Actions\Record\AttachSaleLot;
use App\Actions\Record\BuildRecordsFromResults;
use App\Models\DataSource;
use App\Models\HorseEvent;
use App\Models\SaleLot;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Legacy Reining Breeders Sale results (legacysale.com), the reining yearling and broodmare sale held every September
 * at ThorSport Ranch, Aubrey TX. Checked Oct 23 2026 and collected under Richard's standing approval: WordPress;
 * robots.txt disallows only /wp-admin/ and /wp-content/uploads/wpforms/; no terms of use (a copyright line only); no
 * bot wall.
 *
 * One results page per sale, 2014 to 2026 (2026 had a Friday Designer Sale and the Saturday Legacy Sale). Hip, horse
 * and price throughout; sire, dam, foal year, sex and color too up to 2021 and on the 2026 Designer page. The 2025 and
 * 2026 catalogs are already sale lots from the Robin Glenn crawl (pedigrees, no prices): a results row with the same
 * year, hip and an agreeing name goes onto that lot's record and carries the result, and the Robin Glenn lot keeps the
 * identity (see pairWithRobinGlennLot), the reverse of WaverlyHarvester's .bid pairing.
 *
 * Facts only: consignor and buyer names are never stored (only whether a buyer was printed, in the parsed rows). One
 * request at a time, $delay seconds apart (at least 3), as HorsefaxBot; a 403/429 or a bot check stops the run.
 */
class LegacySaleHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://legacysale.com';
    public const SALE = 'Legacy Reining Breeders Sale';

    /** A results row that shares its record with the Robin Glenn catalog lot of the same sale and hip. */
    public const SAME_SALE = 'same_sale';

    private DataSource $source;
    private float $lastRequest = 0;
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY);
        }
        $this->dir = $dir ?? storage_path('app/private/legacysale');
        @mkdir($this->dir.'/pages', 0775, true);
    }

    /**
     * The results pages, keyed by sale. Days: 2025 from Robin Glenn's catalog 2650 (Sept 20 2025); 2026 from the
     * site's schedule (Designer Sale Friday Sept 18, Legacy Sale Saturday Sept 19). The other years print no date:
     * September, month precision.
     */
    public static function sales(array $found = []): array
    {
        $out = [];
        foreach (range(2014, 2025) as $y) {
            $out[(string) $y] = ['year' => $y, 'slug' => $y.'-sale-results', 'name' => self::SALE.' '.$y, 'date' => $y === 2025 ? '2025-09-20' : null];
        }
        $out['2026-designer'] = ['year' => 2026, 'slug' => '2026-designer-sale-results', 'name' => self::SALE.' 2026 (Designer Sale)', 'date' => '2026-09-18'];
        $out['2026'] = ['year' => 2026, 'slug' => '2026-legacy-sale', 'name' => self::SALE.' 2026', 'date' => '2026-09-19'];

        // Later sales: results pages linked from the homepage (discover()), dated from its schedule.
        foreach ($found['pages'] ?? [] as $slug) {
            if (! preg_match('/^(20\d\d)-(?!.*consign)[a-z-]*(?:sale-results|legacy-sale|designer-sale)[a-z-]*$/', $slug, $m)) {
                continue;
            }
            $designer = str_contains($slug, 'designer');
            $key = $m[1].($designer ? '-designer' : '');
            if (isset($out[$key])) {
                continue;
            }
            $d = $found['dates'][$m[1]] ?? null;
            $out[$key] = ['year' => (int) $m[1], 'slug' => $slug, 'name' => self::SALE.' '.$m[1].($designer ? ' (Designer Sale)' : ''),
                'date' => $d ? ($designer ? $d['from'] : $d['to']) : null]; // the Designer Sale opens the weekend (2026)
        }

        return $out;
    }

    /** The fixed list plus what the homepage linked. */
    public function allSales(): array
    {
        return self::sales($this->found());
    }

    /** Results-page slugs and sale dates read from the homepage, kept in calendar.json (merged, never dropped). */
    public function found(): array
    {
        $f = is_file($this->dir.'/calendar.json') ? json_decode(file_get_contents($this->dir.'/calendar.json'), true) : [];

        return ['pages' => $f['pages'] ?? [], 'dates' => $f['dates'] ?? []];
    }

    /** Read the homepage: links to results pages ("2027-sale-results") and the coming sale's dates ("September 18 & 19, 2026"). */
    public function discover(): array
    {
        $html = $this->get(self::BASE.'/');
        if ($html === null) {
            return ['pages' => 0, 'dates' => 0];
        }
        $page = self::homepage($html);
        $f = $this->found();
        $f['pages'] = array_values(array_unique(array_merge($f['pages'], $page['pages'])));
        $f['dates'] = $page['dates'] + $f['dates'];
        file_put_contents($this->dir.'/calendar.json', json_encode($f, JSON_PRETTY_PRINT));

        return ['pages' => count($page['pages']), 'dates' => count($page['dates'])];
    }

    /** @return array{pages: list<string>, dates: array<string, array{from:string, to:string}>} */
    public static function homepage(string $html): array
    {
        preg_match_all('#https?://(?:www\.)?legacysale\.com/(20\d\d-[a-z0-9-]+)/#', $html, $m);
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $dates = [];
        if (preg_match_all('/\b(September|October|August)\s+(\d{1,2})(?:\s*(?:&|-|–|and)\s*(\d{1,2}))?,?\s+(20\d\d)\b/u', $text, $dd, PREG_SET_ORDER)) {
            foreach ($dd as $d) {
                $from = date('Y-m-d', strtotime("$d[1] $d[2] $d[4]"));
                $dates[$d[4]] ??= ['from' => $from, 'to' => ! empty($d[3]) ? date('Y-m-d', strtotime("$d[1] $d[3] $d[4]")) : $from];
            }
        }

        return ['pages' => array_values(array_unique($m[1])), 'dates' => $dates];
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'legacysale'], [
            'name' => 'Legacy Reining Breeders Sale results', 'url' => self::BASE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 23 2026: WordPress site; robots.txt disallows only /wp-admin/ and /wp-content/uploads/wpforms/; no terms of use (copyright line only); no bot protection. Results pages 2014–2026. Facts only with a link back: consignor and buyer names are never stored (the kept pages still show them). Collected under Richard\'s standing approval.',
        ]);
    }

    public static function url(array $sale): string
    {
        return self::BASE.'/'.$sale['slug'].'/';
    }

    public function pagePath(array $sale): string
    {
        return $this->dir.'/pages/'.$sale['slug'].'.html.gz';
    }

    /**
     * Read robots.txt and the homepage, then fetch every results page not already kept (gzipped). A sale in the last
     * 40 days is fetched again: its results page fills in after the sale.
     */
    public function collect(): array
    {
        $out = ['fetched' => 0, 'cached' => 0, 'unavailable' => 0];
        $robots = (string) $this->get(self::BASE.'/robots.txt');
        if (preg_match('#^\s*Disallow:\s*/\s*$#mi', $robots) || preg_match('#^\s*Disallow:\s*/20#mi', $robots)) {
            throw new HarvestBlocked('robots.txt now disallows the results pages: stopping.');
        }
        $out += $this->discover();
        foreach ($this->allSales() as $sale) {
            $recent = $sale['date'] && $sale['date'] <= now()->toDateString() && $sale['date'] >= now()->subDays(40)->toDateString();
            if (is_file($this->pagePath($sale)) && ! $recent) {
                $out['cached']++;
                continue;
            }
            $body = $this->get(self::url($sale));
            if ($body === null || ! str_contains($body, '<table')) {
                $out['unavailable']++;
                continue;
            }
            file_put_contents($this->pagePath($sale), gzencode($body, 9));
            $out['fetched']++;
        }

        return $out;
    }

    /** Parsed rows per sale key, from the kept pages. A summary (counts only, no names) is kept beside them. */
    public function rows(): array
    {
        $out = [];
        foreach ($this->allSales() as $key => $sale) {
            if (is_file($this->pagePath($sale))) {
                $out[$key] = LegacySaleParser::rows(gzdecode(file_get_contents($this->pagePath($sale))));
            }
        }
        file_put_contents($this->dir.'/legacy-sales.json', json_encode(array_map(fn ($rows) => array_count_values(array_column($rows, 'status'))
            + ['rows' => count($rows), 'unnamed' => count(array_filter($rows, fn ($r) => $r['pending'])), 'with_pedigree' => count(array_filter($rows, fn ($r) => $r['sire'] && $r['dam']))], $out), JSON_PRETTY_PRINT));

        return $out;
    }

    /** @param ?array $rows parsed rows per sale key (rows()' shape); null reads the kept pages */
    public function load(?array $rows = null): array
    {
        $src = $this->source();
        $rg = DataSource::where('key', 'robinglenn')->first();
        $out = ['loaded' => 0, 'sold' => 0, 'no_sale' => 0, 'offered' => 0, 'withdrawn_skipped' => 0, 'unnamed' => 0, 'with_pedigree' => 0,
            'same_horse_as_robin_glenn_lot' => 0, 'replaced_offered_entry' => 0, 'robin_glenn_lot_left_for_staff' => 0, 'robin_glenn_lot_neither_placed' => 0,
            'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0, 'by_sale' => []];
        $sales = $this->allSales();
        foreach ($rows ?? $this->rows() as $key => $list) {
            $sale = $sales[$key];
            $out['by_sale'][$key] = 0;
            foreach ($list as $r) {
                if ($r['status'] === 'withdrawn') {
                    $out['withdrawn_skipped']++;
                    continue;
                }
                $note = self::SALE.' results, hip '.$r['hip']
                    .($r['status'] === 'no_sale' ? '; passed out (no sale)'.($r['last_bid_cents'] ? ', last bid $'.number_format($r['last_bid_cents'] / 100) : '') : '')
                    .($r['status'] === 'offered' ? '; no result printed' : '')
                    .($r['pending'] ? '; not yet named (registration pending)' : '');
                $lot = SaleLot::record($src->id, 'legacy:'.$key.':'.$r['hip'], [
                    'sale_name' => $sale['name'], 'sale_date' => $sale['date'] ?? $sale['year'].'-09-01', 'date_precision' => $sale['date'] ? 'day' : 'month',
                    'sale_year' => $sale['year'], 'lot' => $r['hip'], 'horse_name' => $r['name'] ?? 'Pending', 'registered' => $r['pending'] ? false : null,
                    'registry' => $r['registry'], 'foal_year' => $r['foal_year'], 'sex' => $r['sex'], 'color' => $r['color'],
                    'sire_name' => $r['sire'], 'dam_name' => $r['dam'], 'pedigree' => array_filter(['s' => $r['sire'], 'd' => $r['dam']]) ?: null,
                    'status' => $r['status'], 'price_cents' => $r['price_cents'], 'buyers_premium' => 'unknown', 'source_url' => self::url($sale),
                    'note' => mb_substr($note, 0, 255),
                ]);
                $out['loaded']++;
                $out['by_sale'][$key]++;
                $out[$r['status']]++;
                $out['with_pedigree'] += $r['sire'] && $r['dam'] ? 1 : 0;
                if ($r['pending'] || ! $r['name']) {
                    // No name: hip and price only, never matched by name (nor paired with a "Pending/..." catalog lot).
                    $out['unnamed']++;
                    if (! $lot->horse_id && $lot->review_status === null) {
                        $lot->forceFill(['review_status' => RegistrationOnlyMatch::UNMATCHED, 'review_note' => 'Not yet named at the sale; never matched by name'])->save();
                    }
                    $out[$lot->horse_id || $lot->review_status !== RegistrationOnlyMatch::UNMATCHED ? 'already' : 'unmatched']++;
                    continue;
                }
                $rgLot = $rg ? LegacySaleParser::robinGlennLot($rg->id, $sale['year'], $r['hip'], $r['name']) : null;
                if ($rgLot && $rgLot->horse_id && ! $lot->horse_id && in_array($lot->review_status, [null, RegistrationOnlyMatch::UNMATCHED], true)) {
                    // review_status null: the nightly hf:match-sales puts the reported price on the record (AttachSaleLot).
                    $lot->forceFill(['horse_id' => $rgLot->horse_id, 'match_tier' => 'same_lot', 'matched_at' => now(),
                        'review_status' => $lot->priceKind() && ! $lot->publishable() ? 'held' : null,
                        'review_note' => 'Same sale and hip as Robin Glenn catalog lot '.$rgLot->external_id])->save();
                    $out['matched']++;
                } else {
                    $out[RegistrationOnlyMatch::place($lot, self::SALE)]++;
                }
                if ($rgLot && $lot->horse_id && $rgLot->review_status !== self::SAME_SALE) {
                    $paired = $this->pairWithRobinGlennLot($lot, $rgLot);
                    $out[$paired ? 'same_horse_as_robin_glenn_lot' : 'robin_glenn_lot_left_for_staff']++;
                    $out['replaced_offered_entry'] += $paired === 'replaced' ? 1 : 0;
                } elseif ($rgLot && ! $lot->horse_id) {
                    $out['robin_glenn_lot_neither_placed']++; // same horse, but neither lot is on a record yet
                }
            }
        }

        return $out;
    }

    /**
     * The results row and the Robin Glenn catalog lot (same year, hip and name) are one horse at one sale. The results
     * row carries the result into the history; the Robin Glenn lot keeps the identity (pedigree, foal date) and is
     * marked same_sale so the nightly matcher never adds an "offered" entry beside the reported sale. A Robin Glenn lot
     * still waiting for a match goes onto the record the results row found.
     *
     * When the Robin Glenn lot already put "Offered at auction" on the record (production, Oct 2026) and the results
     * row reports a sale, that entry is replaced by the reported sale (Richard, Oct 23 2026): the removed entry is
     * written to replaced-entries.jsonl beside the pages and to the audit log. A passed-out horse keeps its "offered"
     * entry, which is still true. Returns 'paired', 'replaced', or null when both are left for staff (the Robin Glenn
     * lot sits on another record, or its entry is something else).
     */
    private function pairWithRobinGlennLot(SaleLot $lot, SaleLot $rgLot): ?string
    {
        if (! $rgLot->horse_id && in_array($rgLot->review_status, [null, RegistrationOnlyMatch::UNMATCHED], true)) {
            $rgLot->forceFill(['horse_id' => $lot->horse_id, 'match_tier' => 'same_lot', 'matched_at' => now()])->save();
            $pedigree = $rgLot->pedigree ?: array_filter(['s' => $rgLot->sire_name, 'd' => $rgLot->dam_name, 'ds' => $rgLot->dam_sire_name]);
            app(AddPublishedIdentity::class)($lot->horse, BuildRecordsFromResults::system(), [
                'registry' => $rgLot->registry, 'registration_number' => $rgLot->registration_number, 'foal_year' => $rgLot->foal_year, 'pedigree' => $pedigree,
            ], 'sale lot '.$rgLot->id);
        }
        if ($rgLot->horse_id !== $lot->horse_id) {
            return null;
        }
        $same = ['review_status' => self::SAME_SALE, 'review_note' => 'Same sale and hip as Legacy results hip '.$lot->lot.' (that lot carries the result)'];
        if (! $rgLot->horse_event_id) {
            $rgLot->forceFill($same)->save();

            return 'paired';
        }
        $event = HorseEvent::find($rgLot->horse_event_id);
        $replaceable = $event && $event->horse_id === $lot->horse_id && $event->result === 'offered' && $event->status === 'unverified'
            && $event->source_type === 'historical' && $lot->status === 'sold' && $lot->publishable() && ! $lot->horse_event_id;
        if (! $replaceable) {
            return null;
        }
        DB::transaction(function () use ($lot, $rgLot, $event, $same) {
            $system = BuildRecordsFromResults::system();
            file_put_contents($this->dir.'/replaced-entries.jsonl', json_encode(['at' => now()->toIso8601String(), 'sale_lot' => $rgLot->id, 'replaced_by_sale_lot' => $lot->id, 'event' => $event->getAttributes()], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            Audit::record('sale_lot.offered_entry_replaced', $event, $event->getAttributes(), ['by_sale_lot' => $lot->id, 'sale' => $lot->sale_name, 'lot' => $lot->lot], $event->horse_id, $system->id);
            $event->delete();
            $rgLot->forceFill(['horse_event_id' => null] + $same)->save();
            app(AttachSaleLot::class)($lot->fresh(), $lot->horse, $system, 'same_lot'); // the reported sale goes on now, not at the nightly match
        });

        return 'replaced';
    }

    private function get(string $url): ?string
    {
        for ($attempt = 0, $backoff = 30; ; $attempt++, $backoff *= 2) {
            $wait = $this->delay - (microtime(true) - $this->lastRequest);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $this->lastRequest = microtime(true);
            try {
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(90)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            $this->say($status.' '.$url);
            $challenge = preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!|Access denied)|cf-chl-|id="challenge-form"/i', substr($body, 0, 20000));
            if ($status === 200 && ! $challenge) {
                return $body;
            }
            if (in_array($status, [403, 429], true) || ($status === 200 && $challenge)) {
                throw new HarvestBlocked("Blocked or rate-limited at $url (HTTP $status): stopping. No workaround.");
            }
            if (in_array($status, [404, 410], true) || $attempt >= 3) {
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
