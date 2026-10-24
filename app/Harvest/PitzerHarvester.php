<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Pitzer Ranch sale catalogs (pitzerranch.net, Ericson NE), Oct 23 2026 under Richard's standing approval. WordPress.com
 * site: robots.txt disallows only WooCommerce and /wp-admin/ paths; no site terms; WordPress.com's terms have no
 * scraping clause.
 *
 * Four Pitzer sales (Spring 2024, Fall 2024, Spring 2025, Spring 2026) are already in through the Robin Glenn crawl
 * (catIDs 2122, 2282, 2484, 2860), but Robin Glenn emptied Fall 2025 and Fall 2026 after those sales. The catalog PDFs
 * on Pitzer's own site fill that gap, and an archived 2022 "Line Up" list adds Fall 2022. The catalogs are Robin Glenn
 * QData pages (see PitzerParser); the line-up is a plain list with no pedigree. The 2016 catalog on the Wayback Machine
 * is a different InDesign layout and is not read.
 *
 * Facts only: consignor names and phone numbers, NOTES text and photos are never kept (the cached PDFs still print
 * them). One request at a time, $delay seconds apart (at least 3), as HorsefaxBot; a 403/429 or a bot check stops the run.
 */
class PitzerHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://pitzerranch.net';

    /**
     * The PDFs, keyed by sale. Dates: the catalog covers for 2025 and 2026 (also Robin Glenn's empty catalogs 2599 and
     * 3030); 2022 from Pitzer's homepage of Aug 2022 on the Wayback Machine ("2022 Fall Sale – Sept. 9 – 10").
     */
    public const SALES = [
        'fall-2022' => ['season' => 'Fall', 'year' => 2022, 'from' => '2022-09-09', 'to' => '2022-09-10', 'kind' => 'lineup', 'file' => 'pitzer-fall-2022-list.pdf',
            'url' => 'https://pitzerranch.net/wp-content/uploads/2022/07/Website-List-F22.pdf',
            'fetch' => 'https://web.archive.org/web/20220801012220id_/https://pitzerranch.net/wp-content/uploads/2022/07/Website-List-F22.pdf'],
        'fall-2025' => ['season' => 'Fall', 'year' => 2025, 'from' => '2025-09-05', 'to' => '2025-09-06', 'kind' => 'catalog', 'file' => 'pitzer-fall-2025.pdf',
            'url' => 'https://pitzerranch.net/wp-content/uploads/2025/07/FALL-25-2-web.pdf'],
        'fall-2026' => ['season' => 'Fall', 'year' => 2026, 'from' => '2026-09-04', 'to' => '2026-09-05', 'kind' => 'catalog', 'file' => 'pitzer-fall-2026.pdf',
            'url' => 'https://pitzerranch.net/wp-content/uploads/2026/07/Fall-26-Web-Catalog.pdf'],
    ];

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY);
        }
        $this->dir = $dir ?? storage_path('app/private/pitzer');
        @mkdir($this->dir.'/pdf', 0775, true);
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'pitzer'], [
            'name' => 'Pitzer Ranch sale catalogs', 'url' => self::BASE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 23 2026: WordPress.com site; robots.txt disallows only WooCommerce and /wp-admin/ paths; no site terms; WordPress.com terms have no scraping clause. Catalog PDFs from pitzerranch.net (Fall 2022 list via the Wayback Machine). Facts only with a link back: consignors, phone numbers, notes and photos are never stored (the kept PDFs still print them). Collected under Richard\'s standing approval (Oct 23 2026).',
        ]);
    }

    /** Download every PDF not already kept. */
    public function collect(): array
    {
        $out = ['downloaded' => 0, 'cached' => 0, 'unavailable' => 0];
        foreach (self::SALES as $sale) {
            $path = $this->pdfPath($sale['file']);
            if (is_file($path) && filesize($path) > 0) {
                $out['cached']++;
                continue;
            }
            $body = $this->get($sale['fetch'] ?? $sale['url']);
            // A complete PDF only: archived first captures are often cut off at 1 MB.
            if ($body === null || ! str_starts_with($body, '%PDF') || ! str_contains(substr($body, -1024), '%%EOF')) {
                $out['unavailable']++;
                continue;
            }
            file_put_contents($path, $body);
            $out['downloaded']++;
        }

        return $out;
    }

    public function pdfPath(string $file): string
    {
        return $this->dir.'/pdf/'.$file;
    }

    /** Lots per sale key, read from the kept PDFs. */
    public function lots(): array
    {
        $out = [];
        foreach (self::SALES as $key => $sale) {
            $path = $this->pdfPath($sale['file']);
            if (! is_file($path)) {
                continue;
            }
            $out[$key] = $sale['kind'] === 'lineup'
                ? PitzerParser::lineUp(StatePdfHarvester::text(file_get_contents($path)))
                : PitzerParser::catalog(PitzerParser::pagesFromPdf($path), PitzerParser::ocrPages($path));
        }

        return $out;
    }

    public function load(?array $lots = null): array
    {
        $src = $this->source();
        $out = ['loaded' => 0, 'offered' => 0, 'catalog' => 0, 'with_pedigree' => 0, 'with_reg_number' => 0, 'scanned_page' => 0, 'not_a_registered_name' => 0,
            'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0, 'by_sale' => []];
        foreach ($lots ?? $this->lots() as $key => $rows) {
            $sale = self::SALES[$key];
            $name = 'Pitzer Ranch '.$sale['season'].' Sale '.$sale['year']; // Robin Glenn's naming for the other Pitzer sales
            $status = $sale['to'] < now()->subDays(3)->toDateString() ? 'offered' : 'catalog';
            $out['by_sale'][$key] = 0;
            foreach ($rows as $r) {
                if ($r['foal_year'] > $sale['year'] || $r['foal_year'] < 1980) {
                    continue; // a misread year
                }
                $p = $r['pedigree'];
                $lot = SaleLot::record($src->id, 'pitzer:'.$key.':'.$r['lot'], [
                    'sale_name' => $name, 'sale_date' => $sale['from'], 'date_precision' => 'day', 'lot' => $r['lot'],
                    'horse_name' => mb_substr($r['name'], 0, 255), 'registered' => $r['registration_number'] ? true : null,
                    'registry' => $r['registration_number'] ? 'AQHA' : null, 'registration_number' => $r['registration_number'],
                    'foal_year' => $r['foal_year'], 'birth_date' => $r['birth_date'], 'sex' => $r['sex'], 'color' => $r['color'], 'breed' => $r['breed'],
                    'sire_name' => $p['s'] ?? null, 'dam_name' => $p['d'] ?? null, 'dam_sire_name' => $p['ds'] ?? null, 'pedigree' => $p ?: null,
                    'status' => $status, 'price_cents' => null, 'buyers_premium' => 'unknown', 'source_url' => $sale['fetch'] ?? $sale['url'],
                    'note' => mb_substr(match (true) {
                        $sale['kind'] === 'lineup' => 'Pitzer Ranch sale line-up list (archived); no pedigree printed',
                        $r['ocr'] => 'Pitzer Ranch catalog, scanned page: name read twice and agreeing; chart and number not kept',
                        default => 'Pitzer Ranch catalog (Robin Glenn QData pages)',
                    }.'; no prices published', 0, 255),
                ]);
                $out['loaded']++;
                $out['by_sale'][$key]++;
                $out[$status]++;
                $out['with_pedigree'] += $p ? 1 : 0;
                $out['with_reg_number'] += $r['registration_number'] ? 1 : 0;
                $out['scanned_page'] += $r['ocr'] ? 1 : 0;
                if ($r['placeholder']) {
                    // "MB x Jessie Jo Jackie": a foal not yet named. Number only (there is none), so no record from the label.
                    $out['not_a_registered_name']++;
                    $placed = RegistrationOnlyMatch::apply($lot);
                    $out[$placed === 'no_number' ? 'unmatched' : $placed]++;
                    continue;
                }
                $out[RegistrationOnlyMatch::place($lot, 'Pitzer Ranch')]++;
            }
        }

        return $out;
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
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(600)->get($url);
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
