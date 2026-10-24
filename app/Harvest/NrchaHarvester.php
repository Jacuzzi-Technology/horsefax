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
 * NRCHA's public show results (results.nrcha.com), read from the same data files its results page loads:
 *   {BASE}/index/years.json -> {BASE}/index/{year}.json (shows) -> {BASE}/shows/{id}.json (classes + placed results).
 * No terms restricting website content (Oct 17 2026: its "Terms of Service" covers only SMS; the privacy policy is
 * silent on reuse); robots rules allow these files. Same rules as the other harvesters: identifies itself, waits
 * between requests, never refetches, keeps a gzipped evidence copy. Stores the horse's name, placing, earnings,
 * score and its published sire / dam / dam's sire; never rider or owner.
 */
class NrchaHarvester
{
    public const BASE = 'https://43962946.fs1.hubspotusercontent-na1.net/hubfs/43962946/results/v1';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'nrcha'], [
            'name' => 'National Reined Cow Horse Association (NRCHA)', 'url' => 'https://results.nrcha.com/show-results', 'permission' => 'no_terms_posted',
            'terms_note' => 'Oct 17 2026: the site\'s "Terms of Service" covers only its SMS program; the privacy policy has nothing on reuse; robots rules allow the results data files. Richard approved collecting.',
            'contact' => 'NRCHA, 9702 Mineral Wells Hwy, Weatherford, TX 76088, 940-488-1500, info@nrcha.com',
        ]);
    }

    /** @return list<int> years NRCHA publishes, newest first */
    public function years(): array
    {
        $data = $this->json('/index/years.json', 'index', null, cache: false);

        return collect($data['years'] ?? [])->pluck('year')->map(fn ($y) => (int) $y)->sortDesc()->values()->all();
    }

    /** Returns [shows read, results stored]. */
    public function year(int $year, ?int $maxShows = null): array
    {
        $index = $this->json('/index/'.$year.'.json', 'year', $year);
        $shows = 0;
        $stored = 0;
        foreach ($index['shows'] ?? [] as $show) {
            if ($maxShows !== null && $shows >= $maxShows) {
                break;
            }
            $path = '/shows/'.rawurlencode((string) $show['id']).'.json';
            $page = HarvestPage::firstOrNew(['url' => self::BASE.$path], ['data_source_id' => $this->source->id, 'year' => $year, 'kind' => 'class']);
            if (in_array($page->status, ['parsed', 'skipped'], true)) {
                continue;
            }
            $data = $this->json($path, 'class', $year, page: $page);
            if ($data === null) {
                continue;
            }
            $stored += $this->import($page->fresh(), $data, $year);
            $shows++;
        }

        return [$shows, $stored];
    }

    private function json(string $path, string $kind, ?int $year, bool $cache = true, ?HarvestPage $page = null): ?array
    {
        $url = self::BASE.$path;
        $page ??= HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'year' => $year, 'kind' => $kind]);
        if ($cache && $page->exists && $page->path && Storage::disk('local')->exists($page->path)) {
            return json_decode(gzdecode(Storage::disk('local')->get($page->path)), true);
        }
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)->retry(2, 10000, throw: false)->get($url);
            if ($this->log) {
                ($this->log)($r->status().' '.$url);
            }
        } catch (\Throwable $e) {
            $page->fill(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500), 'fetched_at' => now()])->save();

            return null;
        }
        $page->fill(['http_status' => $r->status(), 'fetched_at' => now()]);
        if ($r->status() === 404) {
            $page->fill(['status' => 'skipped', 'error' => 'Results not published'])->save();

            return null;
        }
        if (! $r->successful()) {
            $page->fill(['status' => 'failed', 'error' => 'HTTP '.$r->status()])->save();

            return null;
        }
        $file = 'harvest/nrcha/'.($year ?? 'index').'/'.sha1($url).'.json.gz';
        Storage::disk('local')->put($file, gzencode($r->body(), 9));
        $page->fill(['path' => $file, 'sha256' => hash('sha256', $r->body()), 'status' => 'fetched'])->save();

        return $r->json();
    }

    /** Registered names are published in capitals: show them in title case. */
    public static function display(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        if ($name !== strtoupper($name)) {
            return $name;
        }

        // Title case, but keep initials ("JM", "NM", "E.L.", "J.D.'s", "BP's") in capitals.
        return implode(' ', array_map(fn ($w) => \App\Support\NameCase::word($w), explode(' ', $name)));
    }

    private function import(HarvestPage $page, array $data, int $year): int
    {
        $rows = collect($data['earnings'] ?? [])->filter(fn ($r) => ! empty($r['horse']) && (int) ($r['placing'] ?? 0) > 0);
        if ($rows->isEmpty()) {
            $page->fill(['status' => 'skipped', 'error' => 'No placed results'])->save();

            return 0;
        }
        $event = $data['event'] ?? [];

        return DB::transaction(function () use ($page, $rows, $event, $year) {
            $n = 0;
            $touched = [];
            foreach ($rows as $row) {
                $name = self::display($row['horse']);
                $key = ShowRecord::key($name);
                if ($key === '') {
                    continue;
                }
                $record = ShowRecord::firstOrCreate(['name_key' => $key], ['display_name' => $name]);
                $class = trim((string) ($row['class'] ?? '')) ?: 'Class';
                $app = ShowAppearance::firstOrCreate(
                    ['harvest_page_id' => $page->id, 'show_record_id' => $record->id, 'placing' => (string) (int) $row['placing'], 'class_name' => $class],
                    [
                        'data_source_id' => $this->source->id, 'horse_name' => $name,
                        'event_name' => trim((string) ($event['name'] ?? 'NRCHA show')), 'event_year' => $year,
                        'event_dates' => trim(($event['date'] ?? '').(! empty($event['endDate']) && ($event['endDate'] ?? '') !== ($event['date'] ?? '') ? ' – '.$event['endDate'] : '')) ?: null,
                        'organization' => 'NRCHA', 'registry_inferred' => null,
                        'earnings_cents' => isset($row['earnings']) && $row['earnings'] !== '' ? (int) round((float) $row['earnings'] * 100) : null,
                        'score' => ! empty($row['composite']) ? (string) $row['composite'] : null,
                        'sire_name' => self::display($row['sire'] ?? null), 'dam_name' => self::display($row['dam'] ?? null),
                        'dam_sire_name' => self::display($row['damSire'] ?? null),
                        'source_url' => 'https://results.nrcha.com/show-results',
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
            $page->fill(['status' => 'parsed', 'rows' => $rows->count()])->save();

            return $n;
        });
    }
}
