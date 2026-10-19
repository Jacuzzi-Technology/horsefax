<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A&M Show Services (showmypony.com), the show office for Ohio Valley, Kentucky, Michigan and Tennessee reining
 * affiliates, the National Reining Breeders Classic, the Homecoming and 100X (Isabella found it, Oct 19 2026). Its
 * season and show pages link to public Google Drive folders of draws, showbills and results; folders are listed and
 * files read through the Google Drive API (Richard's choice), one request every few seconds, with an evidence copy of
 * every listing and file. Only "Results_..." PDFs are read, and only the horse's name, NRHA horse number, placing,
 * score and payout are kept (AmShowsParser).
 *
 * Run in small batches (cron: a few files an hour, 30 seconds apart; Richard, Oct 19 2026: no rush). If Google shows
 * its "Sorry... automated queries" page, the run stops and the next day's batches are skipped.
 *
 * NRHA's own results already carry these shows' placed horses. When a row is the same horse, placing and class date
 * as an NRHA result, that result gets the score and horse number instead of a second copy.
 */
class AmShowsHarvester
{
    use PoliteFetch;

    public const SITE = 'https://showmypony.com';
    public const PAGES = ['/', '/home/', '/2020-season/', '/2021-season/', '/2022-season/', '/2023-season/', '/2024-season/', '/2025-season/',
        '/100x/', '/florida-reining-classic/', '/homecoming-reining/', '/kentucky-reining-horse-assocation/',
        '/michigan-reining-horse-association/', '/nrbc/', '/ovrha/', '/tnrha/'];
    private const API = 'https://www.googleapis.com/drive/v3/files';
    private const FOLDER = 'application/vnd.google-apps.folder';
    public const BLOCKED = 'harvest.amshows.blocked_until';

    private DataSource $source;
    private DataSource $nrha;
    private array $seen = [];

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, private bool $refresh = false)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'amshows'], [
            'name' => 'A&M Show Services (showmypony.com)', 'url' => self::SITE, 'permission' => 'no_terms_posted',
            'terms_note' => 'No terms of use posted; robots.txt disallows only /wp-admin/ (Oct 19 2026). Results are PDFs in public Google Drive folders linked from the site, read through the Google Drive API. Richard approved collecting.',
            'contact' => 'A&M Show Services, amshowservices@gmail.com, 740-837-7441',
        ]);
        $this->nrha = DataSource::firstOrCreate(['key' => 'nrha'], ['name' => 'National Reining Horse Association (NRHA)', 'url' => 'https://nrha.com/show-results/']);
    }

    /** @return array{folders:int, files:int, results:int, enriched:int} */
    public function run(?int $maxFiles = null): array
    {
        $key = config('horsefax.google_drive_key');
        if (! $key) {
            throw new \RuntimeException('GOOGLE_DRIVE_API_KEY is not set.');
        }
        $out = ['folders' => 0, 'files' => 0, 'results' => 0, 'enriched' => 0];
        if (($until = Cache::get(self::BLOCKED)) && now()->lt(\Illuminate\Support\Carbon::parse($until))) {
            $this->say('Paused until '.$until.': Google asked us to slow down.');

            return $out;
        }
        $roots = [];
        foreach (self::PAGES as $path) {
            $html = $this->page(self::SITE.$path, 'index');
            if ($html && preg_match_all('#drive\.google\.com/drive/(?:u/\d+/)?folders/([A-Za-z0-9_-]{10,})#', $html, $m)) {
                $roots = array_merge($roots, $m[1]);
            }
        }
        $queue = array_values(array_unique($roots));
        while ($queue) {
            $id = array_shift($queue);
            if (isset($this->seen[$id])) {
                continue;
            }
            $this->seen[$id] = true;
            $out['folders']++;
            foreach ($this->list($id, $key) as $f) {
                if ($f['mimeType'] === self::FOLDER) {
                    $queue[] = $f['id'];
                } elseif (preg_match('/^results/i', $f['name']) && $f['mimeType'] === 'application/pdf') {
                    if ($maxFiles !== null && $out['files'] >= $maxFiles) {
                        return $out;
                    }
                    [$stored, $enriched] = $this->file($f, $key);
                    $out['files']++;
                    $out['results'] += $stored;
                    $out['enriched'] += $enriched;
                }
            }
        }

        return $out;
    }

    /** A folder's files and subfolders, kept as evidence (re-listed with --refresh, so new shows are found). */
    private function list(string $folder, string $key): array
    {
        $url = 'https://drive.google.com/drive/folders/'.$folder;
        $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'folder']);
        if (! $this->refresh && $page->exists && $page->path && Storage::disk('local')->exists($page->path)) {
            return json_decode(gzdecode(Storage::disk('local')->get($page->path)), true) ?: [];
        }
        $files = [];
        $token = null;
        do {
            $r = $this->api(self::API, ['q' => "'{$folder}' in parents and trashed = false", 'pageSize' => 1000, 'pageToken' => $token,
                'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime)', 'key' => $key], $url);
            if (! $r) {
                $page->fill(['status' => 'failed', 'fetched_at' => now()])->save();

                return [];
            }
            $files = array_merge($files, $r->json('files') ?? []);
            $token = $r->json('nextPageToken');
        } while ($token);
        $json = json_encode($files);
        $path = 'harvest/amshows/folders/'.$folder.'.json.gz';
        Storage::disk('local')->put($path, gzencode($json, 9));
        $page->fill(['path' => $path, 'sha256' => hash('sha256', $json), 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();

        return $files;
    }

    /** @return array{0:int, 1:int} results stored, NRHA results given a score */
    private function file(array $f, string $key): array
    {
        $url = 'https://drive.google.com/file/d/'.$f['id'].'/view';
        $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'class']);
        if (in_array($page->status, ['parsed', 'skipped'], true)) {
            return [0, 0];
        }
        if ($page->exists && $page->path && Storage::disk('local')->exists($page->path)) {
            $pdf = gzdecode(Storage::disk('local')->get($page->path));
        } else {
            $r = $this->api(self::API.'/'.$f['id'], ['alt' => 'media', 'key' => $key], $url);
            if (! $r) {
                $page->fill(['status' => 'failed', 'fetched_at' => now()])->save();

                return [0, 0];
            }
            $pdf = $r->body();
            $path = 'harvest/amshows/files/'.$f['id'].'.pdf.gz';
            Storage::disk('local')->put($path, gzencode($pdf, 9));
            $page->fill(['path' => $path, 'sha256' => hash('sha256', $pdf), 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();
        }
        $classes = AmShowsParser::results(StatePdfHarvester::text($pdf));
        if (! array_filter($classes, fn ($c) => $c['rows'])) {
            $page->fill(['status' => 'skipped', 'error' => 'No results read ('.$f['name'].')'])->save();

            return [0, 0];
        }
        $out = $this->import($page->fresh(), $classes);
        $page->fill(['status' => 'parsed', 'rows' => array_sum(array_map(fn ($c) => count($c['rows']), $classes)),
            'year' => (int) substr((string) ($classes[0]['date'] ?? ''), 0, 4) ?: null])->save();

        return $out;
    }

    /** @return array{0:int, 1:int} */
    public function import(HarvestPage $page, array $classes): array
    {
        return DB::transaction(function () use ($page, $classes) {
            $stored = 0;
            $enriched = 0;
            $touched = [];
            foreach ($classes as $c) {
                $year = (int) substr((string) $c['date'], 0, 4);
                $event = $c['event'] ? AmShowsParser::eventName($c['event']) : 'A&M Show Services show';
                $org = preg_match('/^(\w*RHA|NRBC|100X)\b/i', $event, $m) ? strtoupper($m[1]) : 'NRHA affiliate';
                foreach ($c['rows'] as $row) {
                    $key = ShowRecord::key($row['horse']);
                    if ($key === '' || ! $year) {
                        continue;
                    }
                    $record = ShowRecord::firstOrCreate(['name_key' => $key], ['display_name' => $row['horse']]);
                    if ($this->enrichNrha($record, $row, $c['date'])) {
                        $enriched++;
                        continue;
                    }
                    $app = ShowAppearance::firstOrCreate(
                        ['harvest_page_id' => $page->id, 'show_record_id' => $record->id, 'event_name' => $event, 'class_code' => $c['class_code'],
                            'class_name' => $c['class_name'], 'round' => null, 'placing' => $row['placing']],
                        ['data_source_id' => $this->source->id, 'horse_name' => $row['horse'], 'event_year' => $year, 'event_dates' => $c['date'],
                            'organization' => $org, 'score' => $row['score'], 'earnings_cents' => $row['payout_cents'],
                            'competition_number' => $row['number'], 'competition_registry' => 'NRHA', 'source_url' => $page->url]
                    );
                    if ($app->wasRecentlyCreated) {
                        $stored++;
                        $touched[$record->id] = true;
                    }
                }
            }
            foreach (array_keys($touched) as $id) {
                CongressHarvester::refresh($id);
            }

            return [$stored, $enriched];
        });
    }

    /** The same horse and placing in an NRHA result whose show dates include this class's date: add score and number there. */
    private function enrichNrha(ShowRecord $record, array $row, ?string $date): bool
    {
        if (! $date) {
            return false;
        }
        $day = \Illuminate\Support\Carbon::parse($date);
        $match = ShowAppearance::where('show_record_id', $record->id)->where('data_source_id', $this->nrha->id)
            ->where('event_year', $day->year)->where('placing', $row['placing'])->get()
            ->first(function ($a) use ($day) {
                $parts = array_map('trim', preg_split('/\s+[—–-]\s+/u', (string) $a->event_dates));
                try {
                    $from = \Illuminate\Support\Carbon::parse(preg_replace('/^[A-Za-z]+day\s+/', '', $parts[0]))->startOfDay();
                    $to = \Illuminate\Support\Carbon::parse(preg_replace('/^[A-Za-z]+day\s+/', '', $parts[1] ?? $parts[0]))->endOfDay();
                } catch (\Throwable) {
                    return false;
                }

                return $day->between($from, $to);
            });
        if (! $match) {
            return false;
        }
        if (! $match->score && $row['score']) {
            $match->score = $row['score'];
            if ($match->horse_event_id) {
                \App\Models\HorseEvent::whereKey($match->horse_event_id)->whereNull('score')->update(['score' => $row['score']]);
            }
        }
        $match->competition_number ??= $row['number'];
        $match->competition_registry ??= 'NRHA';
        $match->save();

        return true;
    }

    /** One Drive API request on the house delay. The key never goes into a log line or a kept URL. */
    private function api(string $endpoint, array $query, string $shown): ?\Illuminate\Http\Client\Response
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(120)->retry(3, 15000, // only on network and server errors; a refusal is never retried
                    fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->serverError()), throw: false)
                ->get($endpoint, array_filter($query, fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            $this->say('failed '.$shown);

            return null;
        }
        $this->say($r->status().' '.$shown);
        // Google's "Sorry... automated queries" page: it has asked us to stop. Stop the run; never work around it.
        if (in_array($r->status(), [403, 429], true) && str_contains($r->body(), 'Sorry...')) {
            Cache::put(self::BLOCKED, now()->addDay()->toIso8601String(), now()->addDays(2)); // a day off before the next batch
            throw new \RuntimeException('Google is refusing automated downloads from this server (its "Sorry" page). Stopped; files not yet read are retried on the next run.');
        }

        return $r->successful() ? $r : null;
    }
}
