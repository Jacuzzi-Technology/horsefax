<?php

namespace App\Harvest;

use App\Actions\Record\AddPublishedIdentity;
use App\Actions\Record\BuildRecordsFromResults;
use App\Models\DataSource;
use App\Models\SaleLot;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Waverly Sales Company (Waverly IA) Midwest Horse Sale catalog PDFs, checked Oct 23 2026 and collected under
 * Richard's standing approval (he asked for the archived ones too). waverlysales.com: robots.txt (Yoast) allows all;
 * the "Privacy Policy and Terms & Conditions" page covers privacy and SMS consent only; no bot wall.
 *
 * The current WordPress site keeps catalogs under /wp-content/uploads/ (Fall 2024 on). The site before it kept PDFs
 * at the root (Catalog_F20.pdf, Thursday_F17.pdf ...), back to 2003. Both are listed from the Internet Archive's CDX
 * API (the site's own media API drops query strings), read from Waverly's server first and from the Archive copy
 * when the file is gone. Consignors' uploaded pedigrees and papers (/gravity_forms/) are never fetched.
 *
 * The lots are the same sales the waverlysales.bid harvester (AuctioneerSoftware, source as-waverly) already
 * collects from Fall 2024 on, with prices. The catalog adds what those pages lack: registration numbers, foal dates
 * and pedigrees. A catalog lot whose sale day, lot number and name agree with a .bid lot is the same horse, so the
 * two are put on the same record (see load()); the price stays on the .bid lot, never copied.
 *
 * Facts only: consignor, "Fitted by", towns, phone numbers and the description text are never kept (the kept PDFs
 * still print them). One request at a time, $delay seconds apart (at least 3), as HorsefaxBot; a 403/429 or a bot
 * check stops the run.
 */
class WaverlyHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://waverlysales.com';
    /** Every capture, not one per file: the Archive's first copy of a big PDF is often cut off at 1 MB, a later one whole. */
    public const CDX = 'https://web.archive.org/cdx/search/cdx?url=waverlysales.com/*&filter=mimetype:application/pdf&filter=statuscode:200&output=json&fl=original,timestamp,length&limit=20000';

    private DataSource $source;
    private float $lastRequest = 0;
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY);
        }
        $this->dir = $dir ?? storage_path('app/private/waverly');
        @mkdir($this->dir.'/pdf', 0775, true);
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'waverly'], [
            'name' => 'Waverly Sales Company Midwest Horse Sale catalogs', 'url' => self::BASE.'/midwest-horse-sale/', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 23 2026: robots.txt allows all; the Privacy Policy and Terms & Conditions page covers privacy and SMS consent only (no scraping, crawling or database clause); no bot protection. Catalog PDFs from the current site and, for older sales, the Internet Archive. Facts only with a link back: consignors, towns, phone numbers and descriptions are never stored (the kept PDFs still print them). Collected under Richard\'s standing approval (Oct 22 2026).',
        ]);
    }

    /** Could this file be a sale catalog? Forms, certificates, schedules, sale bills and consignors' papers are not. */
    public static function candidate(string $url): bool
    {
        $f = strtolower(urldecode($url));
        if (str_contains($f, '/gravity_forms/')) {
            return false; // consignors' own uploads (pedigrees, registration papers)
        }

        return ! preg_match('/schedule|calendar|certificate|consign|form|scrapie|drug|residue|bqa|cool|tag_|slideshow|salebill|payment|pedigree|registration|flyer|wavfly|wavcoa/', basename($f));
    }

    /** A stable local name: the upload month (current site) + the file name. */
    public static function fileName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $prefix = preg_match('#/uploads/(\d{4})/(\d{2})/#', $path, $m) ? $m[1].'-'.$m[2].'_' : 'root_';

        return $prefix.preg_replace('/[^A-Za-z0-9._-]+/', '_', urldecode(basename($path)));
    }

    /** The live URL on Waverly's server for an archived original (https, host without www for the WordPress site). */
    public static function liveUrl(string $original): string
    {
        $u = preg_replace('#^https?://(www\.)?waverlysales\.com(:80)?#i', '', $original);

        return (str_starts_with($u, '/wp-content/') ? self::BASE : 'https://www.waverlysales.com').$u;
    }

    /** List the PDFs from the Archive and download any not already kept. */
    public function collect(): array
    {
        $out = ['pdfs_found' => 0, 'downloaded' => 0, 'from_archive' => 0, 'cached' => 0, 'unavailable' => 0];
        $index = $this->index();
        $cdx = $this->get(self::CDX);
        $biggest = [];
        foreach (array_slice(json_decode((string) $cdx, true) ?: [], 1) as [$original, $ts, $length]) {
            if (! self::candidate($original)) {
                continue;
            }
            $file = self::fileName($original);
            if (isset($biggest[$file]) && $biggest[$file] >= (int) $length) {
                continue;
            }
            if (($index[$file]['from'] ?? null) && is_file($this->pdfPath($file)) && self::complete(file_get_contents($this->pdfPath($file)))) {
                continue; // a whole copy is kept: keep the capture it came from (its date dates an undated cover)
            }
            $biggest[$file] = (int) $length;
            $index[$file] = ['file' => $file, 'url' => self::liveUrl($original),
                'archived' => 'https://web.archive.org/web/'.$ts.'id_/'.$original] + ($index[$file] ?? []);
        }
        $out['pdfs_found'] = count($index);
        foreach ($index as $file => &$info) {
            $path = $this->pdfPath($file);
            if (is_file($path) && self::complete(file_get_contents($path))) {
                $out['cached']++;
                continue;
            }
            $body = $this->get($info['url']);
            $info['from'] = 'site';
            if (! self::complete($body) && $info['archived']) {
                $body = $this->get($info['archived']);
                $info['from'] = 'archive';
            }
            if (! self::complete($body)) {
                $info['from'] = null;
                $info['truncated'] = $body !== null && str_starts_with($body, '%PDF'); // only a cut-off copy exists
                $out['unavailable']++;
                $this->say("unavailable".($info['truncated'] ? ' (only a cut-off copy archived)' : '').": $file");
                @unlink($path);
                continue;
            }
            file_put_contents($path, $body);
            $out[$info['from'] === 'site' ? 'downloaded' : 'from_archive']++;
        }
        unset($info);
        ksort($index);
        file_put_contents($this->dir.'/pdf-index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    /** A whole PDF: the header, and the end-of-file marker in its last few KB (cut-off archive copies have none). */
    public static function complete(?string $body): bool
    {
        return $body !== null && str_starts_with($body, '%PDF') && str_contains(substr($body, -4096), '%%EOF');
    }

    /** @return array<string, array{file:string, url:string, archived:?string, from:?string}> */
    public function index(): array
    {
        return is_file($this->dir.'/pdf-index.json') ? json_decode(file_get_contents($this->dir.'/pdf-index.json'), true) : [];
    }

    public function pdfPath(string $file): string
    {
        return $this->dir.'/pdf/'.$file;
    }

    /** Parsed catalogs: [file, info, sale, lots], kept as JSON beside the PDFs for checking. */
    public function catalogs(): array
    {
        $out = [];
        $seen = [];
        foreach ($this->index() as $file => $info) {
            $path = $this->pdfPath($file);
            if (! is_file($path)) {
                continue;
            }
            $text = StatePdfHarvester::text(file_get_contents($path));
            if (isset($seen[md5($text)])) {
                continue; // the same catalog posted under two names (WMHS_S24_Website / WMHS-S24-Website-03-01-2023)
            }
            $seen[md5($text)] = true;
            $lots = WaverlyParser::catalog($text, self::flowText($path));
            if (count($lots) < 5) {
                continue; // a sale bill, a dispersal flyer or a form: not a horse-sale catalog
            }
            $seenYear = preg_match('#/web/(\d{4})#', (string) ($info['archived'] ?? ''), $y) ? (int) $y[1] : null;
            $out[] = ['file' => $file, 'info' => $info, 'sale' => WaverlyParser::sale($file, $text, $seenYear), 'lots' => $lots];
        }
        // Two printings of one catalog (an early and a final web version): keep the fuller one. Day catalogs of one sale
        // (Thursday_F17, Friday_F17) share a sale but not their lot numbers, so both stay.
        usort($out, fn ($a, $b) => count($b['lots']) <=> count($a['lots']));
        $kept = [];
        foreach ($out as $c) {
            $lots = array_filter(array_column($c['lots'], 'lot'));
            foreach ($kept as $k) {
                if ($k['sale']['key'] === $c['sale']['key'] && $lots && count(array_intersect($lots, array_column($k['lots'], 'lot'))) >= 0.8 * count($lots)) {
                    continue 2;
                }
            }
            $kept[] = $c;
        }
        $out = $kept;
        usort($out, fn ($a, $b) => [$a['sale']['year'], $a['sale']['key'], $a['file']] <=> [$b['sale']['year'], $b['sale']['key'], $b['file']]);
        file_put_contents($this->dir.'/waverly-catalogs.json', json_encode(array_map(fn ($c) => ['file' => $c['file'], 'sale' => $c['sale'],
            'lots' => count($c['lots']), 'with_reg_number' => count(array_filter($c['lots'], fn ($l) => $l['registration_number']))], $out), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    /** Text in reading order (no -layout), for the 2003–04 flyers set in columns. */
    private static function flowText(string $path): string
    {
        return Process::timeout(120)->run(['pdftotext', $path, '-'])->output();
    }

    /** @param ?array $catalogs parsed catalogs (catalogs()' shape); null reads the kept PDFs */
    public function load(?array $catalogs = null): array
    {
        $src = $this->source();
        $bid = DataSource::where('key', 'as-waverly')->first();
        $out = ['catalogs' => 0, 'loaded' => 0, 'offered' => 0, 'catalog' => 0, 'with_reg_number' => 0, 'same_horse_as_bid_lot' => 0,
            'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($catalogs ?? $this->catalogs() as $c) {
            $sale = $c['sale'];
            if (! $sale['year']) {
                $this->say('no sale year: '.$c['file']);
                continue;
            }
            $out['catalogs']++;
            $past = ($sale['date'] ?? $sale['year'].'-12-31') < now()->subDays(3)->toDateString();
            foreach ($c['lots'] as $l) {
                $bidLot = $bid ? WaverlyParser::bidLot($bid->id, $sale, $l) : null;
                // The day the lot sold comes from the .bid lot; otherwise the sale week (its first day, month precision).
                $lot = SaleLot::record($src->id, 'wav:'.$sale['key'].':'.($l['lot'] ?? 'n-'.ShowRecord::key($l['name'])), [
                    'sale_name' => mb_substr($sale['name'], 0, 255), 'sale_date' => $bidLot?->sale_date ?? $sale['date'] ?? $sale['year'].'-'.($sale['season'] === 'Fall' ? '10' : '03').'-01',
                    'date_precision' => $bidLot ? 'day' : 'month', 'sale_year' => $sale['year'], 'lot' => $l['lot'],
                    'horse_name' => mb_substr($l['name'], 0, 255), 'registered' => $l['registered'], 'registry' => $l['registry'],
                    'registration_number' => $l['registration_number'], 'foal_year' => $l['foal_year'] ?? ($l['age'] !== null ? $sale['year'] - $l['age'] : null), 'birth_date' => $l['birth_date'],
                    'sex' => $l['sex'], 'color' => $l['color'], 'breed' => $l['breed'],
                    'sire_name' => $l['pedigree']['s'] ?? null, 'dam_name' => $l['pedigree']['d'] ?? null, 'dam_sire_name' => $l['pedigree']['ds'] ?? null, 'pedigree' => $l['pedigree'] ?: null,
                    'status' => $past ? 'offered' : 'catalog', 'price_cents' => null, 'buyers_premium' => 'unknown', 'source_url' => $c['info']['url'],
                    'note' => mb_substr($bidLot ? 'Catalogued; the result is on the waverlysales.bid lot '.$bidLot->lot : ($past ? 'Catalogued for the sale; no result published with the catalog' : 'Catalogued'), 0, 255),
                ]);
                $out['loaded']++;
                $out[$past ? 'offered' : 'catalog']++;
                $out['with_reg_number'] += $l['registration_number'] ? 1 : 0;
                if ($lot->review_status === self::SAME_SALE) {
                    $out['already']++;
                    continue;
                }
                if ($bidLot && $bidLot->horse_id && ! $lot->horse_id && in_array($lot->review_status, [null, RegistrationOnlyMatch::UNMATCHED], true)) {
                    $lot->forceFill(['horse_id' => $bidLot->horse_id, 'match_tier' => 'same_lot', 'matched_at' => now()])->save();
                } else {
                    $out[RegistrationOnlyMatch::place($lot, 'Waverly Sales Company')]++;
                }
                if ($bidLot && $lot->horse_id && ! $lot->horse_event_id) {
                    self::pairWithBidLot($lot, $bidLot);
                    $out['same_horse_as_bid_lot']++;
                }
            }
        }

        return $out;
    }

    /** A catalog lot that shares its record with the .bid lot of the same sale: identity only, no second history entry. */
    public const SAME_SALE = 'same_sale';

    /**
     * The catalog lot and the .bid lot (same sale day, lot number and name) are one horse at one sale. The .bid lot
     * carries the result into the history; the catalog lot adds only what the .bid pages lack (registration number,
     * foal year, pedigree) and is marked same_sale so the nightly matcher never adds an "offered" entry beside the
     * reported sale. A .bid lot still waiting for a match goes onto the record the catalog found.
     */
    private static function pairWithBidLot(SaleLot $lot, SaleLot $bidLot): void
    {
        if (! $bidLot->horse_id && in_array($bidLot->review_status, [null, RegistrationOnlyMatch::UNMATCHED], true)) {
            $bidLot->forceFill(['horse_id' => $lot->horse_id, 'match_tier' => 'same_lot', 'matched_at' => now(),
                'review_status' => $bidLot->priceKind() && ! $bidLot->publishable() ? 'held' : null,
                'review_note' => 'Same sale and lot as Waverly catalog lot '.$lot->lot])->save();
        }
        if ($bidLot->horse_id !== $lot->horse_id) {
            return; // the .bid lot sits on another record (or with staff): leave both for staff to see
        }
        $pedigree = $lot->pedigree ?: array_filter(['s' => $lot->sire_name, 'd' => $lot->dam_name, 'ds' => $lot->dam_sire_name]);
        app(AddPublishedIdentity::class)($lot->horse, BuildRecordsFromResults::system(), [
            'registry' => $lot->registry, 'registration_number' => $lot->registration_number, 'foal_year' => $lot->foal_year, 'pedigree' => $pedigree,
        ], 'sale lot '.$lot->id);
        $lot->forceFill(['review_status' => self::SAME_SALE, 'review_note' => 'Same sale and lot as waverlysales.bid lot '.$bidLot->lot.' (that lot carries the result)'])->save();
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
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(300)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            $this->say($status.' '.$url);
            $challenge = ! str_starts_with($body, '%PDF') && preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!|Access denied)|cf-chl-|id="challenge-form"/i', substr($body, 0, 20000));
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
