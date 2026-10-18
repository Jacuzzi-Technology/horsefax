<?php

namespace App\Harvest;

use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Super Sires (supersires.org): the enrolled-stallion pages (3-generation pedigrees) and nominated-foal pages (sire,
 * dam, dam's sire, foal date, sex, registration number, Super Sires earnings). Pages come from the site's own
 * sitemaps. No terms of use posted (Oct 17 2026; footer "Copyright © Super Sires, Inc.", privacy policy only);
 * robots.txt allows all with Crawl-delay 10, so one request every 10 seconds. Owner, standing, nominator and
 * ownership-history names are never stored. Each page is read once and kept as a gzipped evidence copy.
 */
class SuperSiresHarvester
{
    public const BASE = 'https://supersires.org/';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 10.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'supersires'], [
            'name' => 'Super Sires (supersires.org)', 'url' => self::BASE.'stallions/', 'permission' => 'no_terms_posted',
            'terms_note' => 'Oct 17 2026: no terms of use (footer "Copyright © Super Sires, Inc.", privacy policy only); robots.txt allows all agents, Crawl-delay 10. Richard approved collecting stallion pedigrees and nominated foals.',
        ]);
    }

    /** @return list<string> stallion pages first, then foals */
    public function pages(): array
    {
        $index = $this->fetch(self::BASE.'sitemap_index.xml') ?? '';
        preg_match_all('#<loc>(https://supersires\.org/(stallion|foals)-sitemap\d*\.xml)</loc>#', $index, $maps, PREG_SET_ORDER);
        usort($maps, fn ($a, $b) => [$a[2] === 'foals', $a[1]] <=> [$b[2] === 'foals', $b[1]]);
        $urls = [];
        foreach ($maps as $m) {
            preg_match_all('#<loc>(https://supersires\.org/(?:stallion|foals)/[^<]+/)</loc>#', $this->fetch($m[1]) ?? '', $u);
            array_push($urls, ...$u[1]);
        }

        return array_values(array_unique($urls));
    }

    /** Returns counts by outcome. */
    public function run(?int $maxPages = null): array
    {
        $out = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_owned' => 0, 'skipped_ambiguous' => 0, 'results' => 0];
        foreach ($this->pages() as $url) {
            if ($maxPages !== null && $out['pages'] >= $maxPages) {
                break;
            }
            $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => str_contains($url, '/foals/') ? 'foal' : 'stallion']);
            if (in_array($page->status, ['parsed', 'skipped'], true)) {
                continue;
            }
            $html = $page->path && Storage::disk('local')->exists($page->path) ? gzdecode(Storage::disk('local')->get($page->path)) : $this->download($page);
            if ($html === null) {
                continue;
            }
            $h = $page->kind === 'foal' ? SuperSiresParser::foal($html) : SuperSiresParser::stallion($html);
            if (! $h) {
                $page->fill(['status' => 'skipped', 'error' => 'No horse found on the page'])->save();
                continue;
            }
            $out[app(ImportPublishedHorse::class)($h, $url)]++;
            $out['results'] += $this->results($page, $h);
            $page->fill(['status' => 'parsed', 'rows' => count($h['results']), 'year' => $h['foal_year']])->save();
            $out['pages']++;
            if ($this->log && $out['pages'] % 100 === 0) {
                ($this->log)(json_encode($out));
            }
        }

        return $out;
    }

    /** A foal's Super Sires earnings go into the registered-name index like other published results. */
    private function results(HarvestPage $page, array $h): int
    {
        $rows = array_filter($h['results'], fn ($r) => $r['year']);
        if (! $rows) {
            return 0;
        }

        return DB::transaction(function () use ($page, $h, $rows) {
            $record = ShowRecord::firstOrCreate(['name_key' => ShowRecord::key($h['name'])], ['display_name' => $h['name']]);
            $n = 0;
            foreach ($rows as $r) {
                $a = ShowAppearance::firstOrCreate(
                    ['harvest_page_id' => $page->id, 'show_record_id' => $record->id, 'event_name' => $r['event'], 'class_code' => null,
                        'class_name' => $r['class'].($r['purse'] ? ' ('.$r['purse'].' purse)' : ''), 'round' => null, 'placing' => $r['placing']],
                    [
                        'data_source_id' => $this->source->id, 'horse_name' => $h['name'], 'event_year' => $r['year'],
                        'organization' => 'Super Sires', 'registry_inferred' => $h['registry'] === 'AQHA' ? 'AQHA' : null,
                        'earnings_cents' => $r['earnings_cents'], 'sire_name' => $h['pedigree']['s'] ?? null, 'dam_name' => $h['pedigree']['d'] ?? null,
                        'dam_sire_name' => $h['pedigree']['ds'] ?? null, 'source_url' => $page->url,
                    ]
                );
                $n += $a->wasRecentlyCreated ? 1 : 0;
            }
            CongressHarvester::refresh($record->id);

            return $n;
        });
    }

    private function download(HarvestPage $page): ?string
    {
        $body = $this->fetch($page->url, $page);
        if ($body === null) {
            return null;
        }
        $file = 'harvest/supersires/'.sha1($page->url).'.html.gz';
        Storage::disk('local')->put($file, gzencode($body, 9));
        $page->fill(['path' => $file, 'sha256' => hash('sha256', $body), 'status' => 'fetched'])->save();

        return $body;
    }

    private function fetch(string $url, ?HarvestPage $page = null): ?string
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)->retry(2, 15000, throw: false)->get($url);
        } catch (\Throwable $e) {
            $page?->fill(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500), 'fetched_at' => now()])->save();

            return null;
        }
        if ($this->log) {
            ($this->log)(now()->format('H:i:s').' '.$r->status().' '.$url);
        }
        $page?->fill(['http_status' => $r->status(), 'fetched_at' => now()]);
        if (! $r->successful()) {
            $page?->fill(['status' => $r->status() === 404 ? 'skipped' : 'failed', 'error' => 'HTTP '.$r->status()])->save();

            return null;
        }

        return $r->body();
    }
}
