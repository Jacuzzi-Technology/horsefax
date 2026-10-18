<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * State Quarter Horse association results posted as PDFs on their own sites. Reads the listed results pages, fetches
 * each PDF once (identifies itself, waits between requests), keeps a gzipped evidence copy, reads the text layer with
 * pdftotext and stores only horse name, show, class and placing. A season-standings file repeats every show's
 * placings, so a result already stored from the same association isn't stored again from a later file.
 */
class StatePdfHarvester
{
    /** Squarespace sites: their robots.txt names AI crawlers to keep out; HorsefaxBot isn't named, and Richard chose to collect (Oct 16 2026). */
    private const SQUARESPACE = 'No terms of use posted (checked Oct 16 2026). The Squarespace robots.txt disallows a list of named AI crawlers (GPTBot, ClaudeBot and others); for other agents only /config, /search, /account and /api are off-limits. Richard decided to collect (Oct 16 2026).';

    public const SOURCES = [
        'nqha' => [
            'name' => 'Nebraska Quarter Horse Association', 'url' => 'https://www.nebraskaquarterhorseassociation.com/2026-results',
            'index' => ['https://www.nebraskaquarterhorseassociation.com/2026-results', 'https://www.nebraskaquarterhorseassociation.com/results-archive'],
            'format' => 'publicationReport', 'match' => '/\.pdf$/i',
        ],
        'moqha' => [
            'name' => 'Missouri Quarter Horse Association', 'url' => 'https://www.moqha.com/',
            'index' => ['https://www.moqha.com/'],
            'format' => 'memberStandings', 'match' => '/(Standing|Division)[^\/]*\.pdf$/i',
        ],
    ];

    private DataSource $source;
    private array $config;
    private float $lastRequest = 0;

    public function __construct(private string $key, private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->config = self::SOURCES[$key] ?? throw new \InvalidArgumentException("Unknown source $key");
        $this->source = DataSource::firstOrCreate(['key' => $key], [
            'name' => $this->config['name'], 'url' => $this->config['url'], 'permission' => 'no_terms_posted', 'terms_note' => self::SQUARESPACE,
        ]);
    }

    /** @return list<string> PDF links on the results pages */
    public function files(): array
    {
        $files = [];
        foreach ($this->config['index'] as $index) {
            $body = $this->fetch($index)?->body() ?? '';
            foreach (CongressParser::links($body, $index) as $u) {
                $u = strtok($u, '?#');
                if (parse_url($u, PHP_URL_HOST) === parse_url($index, PHP_URL_HOST) && preg_match($this->config['match'], $u)) {
                    $files[$u] = true;
                }
            }
        }

        return array_keys($files);
    }

    /** Returns [files read, results stored]. */
    public function run(?int $maxFiles = null): array
    {
        $files = 0;
        $stored = 0;
        foreach ($this->files() as $url) {
            if ($maxFiles !== null && $files >= $maxFiles) {
                break;
            }
            $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'class']);
            if (in_array($page->status, ['parsed', 'skipped'], true)) {
                continue;
            }
            $pdf = $page->path && Storage::disk('local')->exists($page->path) ? gzdecode(Storage::disk('local')->get($page->path)) : $this->download($page);
            if ($pdf === null) {
                continue;
            }
            $stored += $this->import($page->fresh(), StatePdfParser::{$this->config['format']}(self::text($pdf)));
            $files++;
        }

        return [$files, $stored];
    }

    public static function text(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hfpdf');
        file_put_contents($tmp, $pdf);
        try {
            return Process::timeout(120)->run(['pdftotext', '-layout', $tmp, '-'])->output();
        } finally {
            @unlink($tmp);
        }
    }

    private function fetch(string $url): ?\Illuminate\Http\Client\Response
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(90)->retry(2, 10000, throw: false)->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        if ($this->log) {
            ($this->log)($r->status().' '.$url);
        }

        return $r;
    }

    private function download(HarvestPage $page): ?string
    {
        $r = $this->fetch($page->url);
        if ($r === null || ! $r->successful()) {
            $page->fill(['status' => $r?->status() === 404 ? 'skipped' : 'failed', 'http_status' => $r?->status(), 'error' => $r ? 'HTTP '.$r->status() : 'Request failed', 'fetched_at' => now()])->save();

            return null;
        }
        $file = 'harvest/'.$this->key.'/'.sha1($page->url).'.pdf.gz';
        Storage::disk('local')->put($file, gzencode($r->body(), 9));
        $page->fill(['path' => $file, 'sha256' => hash('sha256', $r->body()), 'status' => 'fetched', 'http_status' => $r->status(), 'fetched_at' => now()])->save();

        return $r->body();
    }

    private function import(HarvestPage $page, array $rows): int
    {
        if (! $rows) {
            $page->fill(['status' => 'skipped', 'error' => 'No placed results read'])->save();

            return 0;
        }

        return DB::transaction(function () use ($page, $rows) {
            $n = 0;
            $touched = [];
            foreach ($rows as $row) {
                $name = NrchaHarvester::display($row['horse']);
                $key = ShowRecord::key((string) $name);
                if ($key === '' || ! $row['year']) {
                    continue;
                }
                $record = ShowRecord::firstOrCreate(['name_key' => $key], ['display_name' => $name]);
                $aqha = $row['organization'] === 'AQHA';
                $app = ShowAppearance::firstOrCreate(
                    [
                        'data_source_id' => $this->source->id, 'show_record_id' => $record->id, 'placing' => $row['placing'],
                        'event_name' => $row['event'].' (show #'.$row['show_no'].')', 'class_name' => $row['class_name'],
                        'class_code' => $row['class_code'], 'round' => $row['round'],
                    ],
                    [
                        'harvest_page_id' => $page->id, 'horse_name' => $name, 'event_year' => $row['year'], 'event_dates' => $row['dates'],
                        'organization' => $aqha ? 'AQHA' : $row['organization'], 'registry_inferred' => $aqha ? 'AQHA' : null,
                        'source_url' => $page->url,
                    ]
                );
                if ($app->wasRecentlyCreated) {
                    $n++;
                }
                $touched[$record->id] = true;
            }
            foreach (array_keys($touched) as $id) {
                CongressHarvester::refresh($id);
            }
            $page->fill(['status' => 'parsed', 'rows' => count($rows), 'year' => $rows[0]['year']])->save();

            return $n;
        });
    }
}
