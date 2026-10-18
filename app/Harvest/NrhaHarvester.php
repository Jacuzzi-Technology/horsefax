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
 * Polite, resumable harvest of NRHA's public show results, newest shows first. Same rules as the Congress
 * harvester: identifies itself, one request every few seconds, never refetches a page it has, keeps an evidence
 * copy (only the results section, gzipped: NRHA pages are mostly site navigation), and stores only the horse's
 * name, placing and earnings. Shows that are the Congress are skipped (collected from the Congress directly).
 */
class NrhaHarvester
{
    public const UA = CongressHarvester::UA;
    public const BASE = 'https://nrha.com';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'nrha'], [
            'name' => 'National Reining Horse Association (NRHA)', 'url' => self::BASE.'/show-results/', 'permission' => 'no_terms_posted',
            'terms_note' => 'No login required and no terms of use posted (only a privacy policy and "© NRHA"), Oct 17 2026. robots.txt disallows only /wp-admin/ and explicitly allows admin-ajax.php. Richard approved collecting.',
            'contact' => 'NRHA, 3021 W. Reno Ave., Oklahoma City, OK 73107, 405-946-7400',
        ]);
    }

    private function say(string $l): void
    {
        if ($this->log) {
            ($this->log)($l);
        }
    }

    /** Harvest one year. Returns [class pages parsed, results stored]. */
    public function year(int $year, ?int $maxPages = null): array
    {
        $listUrl = self::BASE.'/wp-admin/admin-ajax.php?action=rh_fetch_show_results&year='.$year;
        $list = $this->load($listUrl, 'year', $year, keepWhole: true);
        if ($list === null) {
            return [0, 0];
        }
        $parsed = 0;
        $stored = 0;
        foreach (NrhaParser::yearList($list) as $show) {
            if (preg_match('/quarter horse congress/i', $show['name'])) {
                continue;
            }
            $showUrl = self::BASE.'/show-results-detail/?id='.$show['show_id'];
            $showPage = $this->load($showUrl, 'show', $year);
            if ($showPage === null) {
                continue;
            }
            foreach (NrhaParser::classCodes($showPage) as $code) {
                if ($maxPages !== null && $parsed >= $maxPages) {
                    return [$parsed, $stored];
                }
                $url = self::BASE.'/show-results-class-detail/?id='.$show['show_id'].'&class-code='.$code;
                $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'year' => $year, 'kind' => 'class']);
                if (in_array($page->status, ['parsed', 'skipped'], true)) {
                    continue;
                }
                $html = $this->load($url, 'class', $year, page: $page);
                if ($html === null) {
                    continue;
                }
                $stored += $this->import($page->fresh(), $html, $year, $show);
                $parsed++;
            }
        }

        return [$parsed, $stored];
    }

    private function load(string $url, string $kind, int $year, bool $keepWhole = false, ?HarvestPage $page = null): ?string
    {
        $page ??= HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'year' => $year, 'kind' => $kind]);
        if ($page->exists && $page->path && Storage::disk('local')->exists($page->path)) {
            return gzdecode(Storage::disk('local')->get($page->path)) ?: null;
        }
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(60)->retry(2, 10000, throw: false)->get($url);
            $this->say($r->status().' '.$url);
        } catch (\Throwable $e) {
            $this->say('ERR '.$url.' '.$e->getMessage());
            $page->fill(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500), 'fetched_at' => now()])->save();

            return null;
        }
        $page->fill(['http_status' => $r->status(), 'fetched_at' => now()]);
        if (! $r->successful()) {
            $page->fill(['status' => 'failed', 'error' => 'HTTP '.$r->status()])->save();

            return null;
        }
        $body = $r->body();
        $keep = $keepWhole ? $body : NrhaParser::evidence($body);
        $path = 'harvest/nrha/'.$year.'/'.sha1($url).'.html.gz';
        Storage::disk('local')->put($path, gzencode($keep, 9));
        $page->fill(['path' => $path, 'sha256' => hash('sha256', $body), 'status' => 'fetched'])->save();

        return $keep;
    }

    private function import(HarvestPage $page, string $html, int $year, array $show): int
    {
        $data = NrhaParser::classPage($html);
        if (! $data['rows']) {
            $page->fill(['status' => 'skipped', 'error' => 'No placed results'])->save();

            return 0;
        }
        $className = $data['class_name'] ?: ('Class '.$data['class_code']);

        return DB::transaction(function () use ($page, $data, $className, $year, $show) {
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
                        'data_source_id' => $this->source->id, 'horse_name' => $row['horse'],
                        'event_name' => $data['show'] ?: $show['name'], 'event_year' => $year,
                        'event_dates' => $data['dates'] ?: $show['dates'], 'class_code' => $data['class_code'],
                        'class_name' => $className, 'organization' => 'NRHA', 'registry_inferred' => null,
                        'earnings_cents' => $row['earnings_cents'], 'source_url' => $page->url,
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
            $page->fill(['kind' => 'class', 'status' => 'parsed', 'rows' => count($data['rows'])])->save();

            return $n;
        });
    }
}
