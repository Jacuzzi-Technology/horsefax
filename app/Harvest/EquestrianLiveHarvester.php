<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * EquestrianLive.com (Split Second Sports Timing): live hunter, jumper and equitation results, read from the same data
 * feeds its pages load. The site lists only about the last 2½ months of shows, so this runs on a schedule and picks
 * up each show once it has finished. robots.txt allows everything with Crawl-delay 20, so one request every 20 seconds.
 * No terms posted (Oct 17 2026); results are marked "unofficial". Stores horse (show) name, class, placing and the
 * score or faults and time; never rider, owner or nation.
 */
class EquestrianLiveHarvester
{
    public const BASE = 'https://equestrianlive.com/';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 20.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'equestrianlive'], [
            'name' => 'EquestrianLive.com (Split Second Sports Timing)', 'url' => self::BASE.'shows.php', 'permission' => 'no_terms_posted',
            'terms_note' => 'Oct 17 2026: no terms page; robots.txt allows all agents with Crawl-delay 20; footer "Copyright Split Second Sports Timing, Inc." and results marked "unofficial". Only recent shows are listed (no archive). Richard approved an ongoing collector.',
        ]);
    }

    /** Feed lines: "type=x+key=value+..." split by "|"; first line is a timestamp. */
    public static function records(string $feed): array
    {
        $feed = str_replace('`', "'", mb_check_encoding($feed, 'UTF-8') ? $feed : mb_convert_encoding($feed, 'UTF-8', 'ISO-8859-1'));
        $out = [];
        foreach (explode('|', $feed) as $line) {
            $rec = [];
            foreach (explode('+', trim($line)) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2) {
                    $rec[$kv[0]] = $kv[1];
                }
            }
            if (isset($rec['type'])) {
                $out[] = $rec;
            }
        }

        return $out;
    }

    /** @return list<array{id:string, name:string, start:string, end:string}> shows that have finished (before today) */
    public function finishedShows(): array
    {
        $feed = $this->fetch(self::BASE.'includes/aj_shows.php?show=1') ?? '';
        $today = now()->startOfDay();

        return collect(self::records($feed))->where('type', 'show')
            ->filter(fn ($s) => ! empty($s['id']) && ! empty($s['e']) && Carbon::createFromFormat('n/j/Y', $s['e'])->startOfDay()->lt($today))
            ->map(fn ($s) => ['id' => $s['id'], 'name' => trim($s['n1'] ?? ''), 'start' => $s['s'] ?? '', 'end' => $s['e']])
            ->values()->all();
    }

    /** Returns [shows read, results stored]. */
    public function run(?int $maxShows = null): array
    {
        $shows = 0;
        $stored = 0;
        foreach ($this->finishedShows() as $show) {
            if ($maxShows !== null && $shows >= $maxShows) {
                break;
            }
            $index = HarvestPage::firstOrNew(['url' => self::BASE.'includes/aj_shows.php?show='.$show['id']], ['data_source_id' => $this->source->id, 'kind' => 'index']);
            if ($index->status === 'parsed') {
                continue;
            }
            $feed = $this->page($index);
            if ($feed === null) {
                continue;
            }
            $classes = collect(self::records($feed))->where('type', 'class')->filter(fn ($c) => ! empty($c['id']) && in_array($c['hj'] ?? '', ['H', 'J'], true));
            $failed = false;
            foreach ($classes as $c) {
                $url = self::BASE.'includes/aj_class'.($c['hj'] === 'H' ? 'hunter' : 'jumper').'.php?showid='.$show['id'].'&classid='.$c['id'].'&maxstamp=0';
                $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'class']);
                if (in_array($page->status, ['parsed', 'skipped'], true)) {
                    continue;
                }
                $body = $this->page($page);
                if ($body === null) {
                    $failed = true;
                    continue;
                }
                $stored += $this->import($page->fresh(), $show, $body);
            }
            $index->fill(['status' => $failed ? 'fetched' : 'parsed', 'rows' => $classes->count()])->save();
            $shows++;
        }

        return [$shows, $stored];
    }

    /** Hunter feeds carry the placing; jumper placings are worked out the way the site's own page does. */
    public static function results(string $feed): array
    {
        $recs = self::records($feed);
        $class = collect($recs)->firstWhere('type', 'class') ?? [];
        $rows = [];
        if (($class['hj'] ?? '') === 'H') {
            foreach ($recs as $r) {
                if ($r['type'] === 'h' && trim($r['H'] ?? '') !== '' && (int) ($r['r'] ?? 0) > 0) {
                    $rows[] = ['horse' => trim($r['H']), 'placing' => (string) (int) $r['r'], 'score' => ($r['t'] ?? '') !== '' ? $r['t'] : null];
                }
            }
        } else {
            foreach (JumperRanking::rank($class, array_values(array_filter($recs, fn ($r) => $r['type'] === 'j'))) as $c) {
                if ($c['rank'] > 0 && trim($c['horse']) !== '') {
                    $rows[] = ['horse' => trim($c['horse']), 'placing' => (string) $c['rank'], 'score' => JumperRanking::describe($c)];
                }
            }
        }

        return ['class_code' => $class['cn'] ?? null, 'class_name' => trim($class['d'] ?? '') ?: 'Class', 'kind' => $class['hj'] ?? null, 'rows' => $rows];
    }

    private function import(HarvestPage $page, array $show, string $feed): int
    {
        $res = self::results($feed);
        $year = (int) Carbon::createFromFormat('n/j/Y', $show['start'] ?: $show['end'])->format('Y');
        if (! $res['rows']) {
            $page->fill(['status' => 'skipped', 'year' => $year, 'error' => 'No placed results'])->save();

            return 0;
        }

        return DB::transaction(function () use ($page, $show, $res, $year) {
            $n = 0;
            $touched = [];
            foreach ($res['rows'] as $row) {
                $name = NrchaHarvester::display($row['horse']);
                $key = ShowRecord::key((string) $name);
                if ($key === '') {
                    continue;
                }
                $record = ShowRecord::firstOrCreate(['name_key' => $key], ['display_name' => $name]);
                $app = ShowAppearance::firstOrCreate(
                    ['harvest_page_id' => $page->id, 'show_record_id' => $record->id, 'event_name' => $show['name'], 'class_code' => $res['class_code'], 'class_name' => $res['class_name'], 'round' => null, 'placing' => $row['placing']],
                    [
                        'data_source_id' => $this->source->id, 'horse_name' => $name, 'event_year' => $year,
                        'event_dates' => $show['start'] === $show['end'] ? $show['start'] : $show['start'].' – '.$show['end'],
                        'organization' => $res['kind'] === 'H' ? 'Hunter/equitation' : 'Jumper', 'registry_inferred' => null,
                        'score' => $row['score'], 'source_url' => self::BASE.'shows.php',
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
            $page->fill(['status' => 'parsed', 'rows' => count($res['rows']), 'year' => $year])->save();

            return $n;
        });
    }

    private function page(HarvestPage $page): ?string
    {
        if ($page->path && Storage::disk('local')->exists($page->path)) {
            return gzdecode(Storage::disk('local')->get($page->path));
        }
        $body = $this->fetch($page->url, $page);
        if ($body === null) {
            return null;
        }
        $file = 'harvest/equestrianlive/'.sha1($page->url).'.txt.gz';
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
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)->retry(2, 20000, throw: false)->get($url);
        } catch (\Throwable $e) {
            $page?->fill(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500), 'fetched_at' => now()])->save();

            return null;
        }
        if ($this->log) {
            ($this->log)($r->status().' '.$url);
        }
        $page?->fill(['http_status' => $r->status(), 'fetched_at' => now()]);
        if (! $r->successful()) {
            $page?->fill(['status' => 'failed', 'error' => 'HTTP '.$r->status()])->save();

            return null;
        }

        return $r->body();
    }
}
