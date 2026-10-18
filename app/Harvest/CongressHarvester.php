<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Polite, resumable harvest of the Congress results archive (no terms posted; robots.txt allows results pages).
 * Identifies itself, waits between requests, stays inside each year's results directory, keeps every page it reads
 * as a private evidence copy, and never refetches a page it already has. Rerunning only picks up what's missing.
 */
class CongressHarvester
{
    public const UA = 'HorsefaxBot/1.0 (+https://horsefax.com/contact)';
    public const INDEX = 'https://www.quarterhorsecongress.com/historical-congress-results';
    public const EVENT = 'All American Quarter Horse Congress';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'congress'], [
            'name' => self::EVENT, 'url' => self::INDEX, 'permission' => 'no_terms_posted',
            'terms_note' => 'No terms of use or reuse statement found (Oct 16 2026); robots.txt disallows only sign-in and admin paths. Courtesy notice to the Ohio Quarter Horse Association planned.',
            'contact' => 'Ohio Quarter Horse Association, 614-505-7200',
        ]);
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }

    /** @return array<int, string> year => results page on quarterhorsecongress.com */
    public function yearPages(): array
    {
        $html = $this->get(self::INDEX)['body'] ?? '';
        $years = [];
        foreach (CongressParser::links($html, self::INDEX) as $url) {
            if (preg_match('#quarterhorsecongress\.com/(?:results/)?(\d{4})-(?:congress-)?(?:horse-show-)?results#i', $url, $m)) {
                $years[(int) $m[1]] = $url;
            }
        }
        krsort($years);

        return $years;
    }

    /** Harvest one year. Returns [pages parsed, results stored]. */
    public function year(int $year, string $yearUrl, ?int $maxPages = null): array
    {
        $yy = substr((string) $year, 2);
        $prefix = 'https://quarterhorsecongress.net/results/'.$yy.'results';
        $queue = [];
        $page = $this->get($yearUrl);
        foreach (CongressParser::links($page['body'] ?? '', $yearUrl) as $u) {
            if (str_starts_with($u, $prefix) && preg_match('/\.html?$/i', $u)) {
                $queue[] = [$u, 0];
            }
        }
        $seen = [];
        $parsed = 0;
        $stored = 0;
        while ($queue && ($maxPages === null || $parsed < $maxPages)) {
            [$url, $depth] = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $record = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'year' => $year, 'kind' => 'index']);
            $html = $this->fetchOrLoad($record);
            if ($html === null) {
                continue;
            }
            if (CongressParser::isClassPage($html)) {
                if ($record->status !== 'parsed') {
                    $stored += $this->importClass($record, $html, $year);
                    $parsed++;
                }
                continue;
            }
            if (stripos($html, 'Results are not yet available') !== false) {
                $record->fill(['kind' => 'class', 'status' => 'skipped', 'error' => 'No results posted (go-round results are not released)'])->save();
                continue;
            }
            if ($depth < 4) {
                foreach (CongressParser::links($html, $url) as $u) {
                    if (str_starts_with($u, $prefix) && preg_match('/\.html?$/i', $u) && ! isset($seen[$u])) {
                        $queue[] = [$u, $depth + 1];
                    }
                }
            }
        }

        return [$parsed, $stored];
    }

    /** The page from our evidence copy if we have it, otherwise fetched politely and saved. */
    private function fetchOrLoad(HarvestPage $record): ?string
    {
        if ($record->exists && $record->path && Storage::disk('local')->exists($record->path)) {
            return Storage::disk('local')->get($record->path);
        }
        $res = $this->get($record->url);
        $record->http_status = $res['status'];
        $record->fetched_at = now();
        if ($res['status'] !== 200 || $res['body'] === null) {
            $record->status = 'failed';
            $record->error = 'HTTP '.$res['status'];
            $record->save();

            return null;
        }
        $path = 'harvest/congress/'.($record->year ?? 'misc').'/'.sha1($record->url).'.html';
        Storage::disk('local')->put($path, $res['body']);
        $record->fill(['path' => $path, 'sha256' => hash('sha256', $res['body']), 'status' => 'fetched'])->save();

        return $res['body'];
    }

    private function get(string $url): array
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(30)->retry(2, 5000, throw: false)->get($url);
            $this->say($r->status().' '.$url);

            return ['status' => $r->status(), 'body' => $r->successful() ? $r->body() : null];
        } catch (\Throwable $e) {
            $this->say('ERR '.$url.' '.$e->getMessage());

            return ['status' => 0, 'body' => null];
        }
    }

    private function importClass(HarvestPage $page, string $html, int $year): int
    {
        $data = CongressParser::classPage($html);
        $className = $data['class_name'] ?: ('Class '.$data['class_code']);
        [$org, $registry] = CongressParser::sanction($className);
        $round = $data['round'] ? (strtoupper($data['round']) === 'F' ? 'Final' : 'Go '.$data['round']) : null;

        return DB::transaction(function () use ($page, $data, $className, $org, $registry, $round, $year) {
            $n = 0;
            $touched = [];
            foreach ($data['rows'] as $row) {
                $key = ShowRecord::key($row['horse']);
                if ($key === '') {
                    continue;
                }
                $record = ShowRecord::firstOrCreate(['name_key' => $key], ['display_name' => $row['horse']]);
                $app = ShowAppearance::firstOrCreate(
                    ['harvest_page_id' => $page->id, 'show_record_id' => $record->id, 'placing' => $row['placing']],
                    [
                        'data_source_id' => $this->source->id, 'horse_name' => $row['horse'], 'event_name' => self::EVENT,
                        'event_year' => $year, 'event_dates' => $data['dates'], 'class_code' => $data['class_code'],
                        'class_name' => $className, 'round' => $round, 'organization' => $org, 'registry_inferred' => $registry,
                        'earnings_cents' => $row['earnings_cents'], 'source_url' => $page->url,
                    ]
                );
                if ($app->wasRecentlyCreated) {
                    $n++;
                }
                $touched[$record->id] = true;
            }
            foreach (array_keys($touched) as $id) {
                self::refresh($id);
            }
            $page->fill(['kind' => 'class', 'status' => 'parsed', 'rows' => count($data['rows'])])->save();

            return $n;
        });
    }

    /** Recompute a name's totals from its appearances. */
    public static function refresh(string $recordId): void
    {
        $agg = ShowAppearance::where('show_record_id', $recordId)
            ->selectRaw('min(event_year) a, max(event_year) b, count(*) n, coalesce(sum(earnings_cents),0) e, bool_or(registry_inferred = \'AQHA\') aqha')->first();
        ShowRecord::whereKey($recordId)->update([
            'first_year' => $agg->a, 'last_year' => $agg->b, 'appearances' => $agg->n, 'earnings_cents' => $agg->e,
            'registry_inferred' => $agg->aqha ? 'AQHA' : null,
        ]);
    }
}
