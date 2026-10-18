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
 * Premier Sires (premiersires.com): the enrolled stallions (foal year, registry, sire x dam), the nominated-foal lookup
 * (every foal nominated since 2018: name, breed, foal year, sire, dam; read through the same data request the lookup
 * page makes) and The Premier futurity results (2024–25 result pages with sire x dam; 2023 show-office PDFs).
 * No terms of use (Oct 18 2026); robots.txt allows these pages and admin-ajax. One request every 5 seconds; each page
 * kept as evidence. Nominator, owner, breeder, rider and exhibitor names are never stored.
 */
class PremierSiresHarvester
{
    public const BASE = 'https://premiersires.com/';
    private const LOOKUP_TABLE = 20;
    private const PLACES = ['champion' => 1, 'reserve champion' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8,
        'ninth' => 9, 'tenth' => 10, 'eleventh' => 11, 'twelfth' => 12, 'thirteenth' => 13, 'fourteenth' => 14, 'fifteenth' => 15, 'sixteenth' => 16,
        'seventeenth' => 17, 'eighteenth' => 18, 'nineteenth' => 19, 'twentieth' => 20];

    private DataSource $source;
    private float $lastRequest = 0;
    private array $out = ['stallions' => 0, 'foals' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_owned' => 0, 'skipped_ambiguous' => 0, 'results' => 0];

    public function __construct(private float $delay = 5.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'premiersires'], [
            'name' => 'Premier Sires (premiersires.com)', 'url' => self::BASE.'stallions/', 'permission' => 'no_terms_posted',
            'terms_note' => 'Oct 18 2026: no terms of use; robots.txt allows the pages and admin-ajax. Richard approved collecting the stallions, the nominated-foal lookup and the futurity results, without nominator or owner names.',
        ]);
    }

    public function run(): array
    {
        $this->stallions();
        $this->foals();
        $this->results();

        return $this->out;
    }

    /** "Name" / "2019 AQHA/APHA Stallion" / "Sire x Dam" on the stallions page. */
    public static function stallionList(string $html): array
    {
        $body = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h\d|/li|/a)[^>]*>#i', "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $l = array_values(array_filter(array_map(fn ($x) => trim(preg_replace('/\s+/u', ' ', $x)), explode("\n", $text)), fn ($x) => $x !== ''));
        $out = [];
        foreach ($l as $i => $line) {
            if ($i === 0 || ! preg_match('#^(\d{4})\s+([A-Za-z/ ]+?)\s+Stallion$#i', $line, $m) || ! preg_match('/^(.+?)\s+x\s+(.+)$/i', $l[$i + 1] ?? '', $p)) {
                continue;
            }
            $out[] = self::horse($l[$i - 1], (int) $m[1], $m[2], 'stallion', $p[1], $p[2]);
        }

        return $out;
    }

    /** Rows of the lookup table: [.., 5 foal, 6 Premier no., 7 breed, 8 year, 9 stallion, 10 mare, 11+ nominator/owners (never read)]. */
    public static function foalRow(array $r): ?array
    {
        if (count($r) < 11 || trim((string) $r[5]) === '') {
            return null;
        }

        return self::horse((string) $r[5], is_numeric($r[8]) ? (int) $r[8] : null, (string) $r[7], null, (string) $r[9], (string) $r[10]);
    }

    /** 2024–25 result pages: a class heading, then "Champion – $3,275 – Horse (Sire x Dam) bred by …". */
    public static function resultPage(string $html): array
    {
        $year = preg_match('#<title>[^<]*?(\d{4})#', $html, $y) ? (int) $y[1] : null;
        preg_match_all('#<h5[^>]*>(.*?)</h5>#is', $html, $m);
        $rows = [];
        $class = null;
        foreach ($m[1] as $h) {
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (preg_match('/^(.+?)\s+[–-]\s+(?:\$([\d,]+)\s+[–-]\s+)?(.+?)\s+\((.+?)\s+x\s+(.+?)\)/u', $t, $r) && isset(self::PLACES[strtolower($r[1])])) {
                if ($class) {
                    $rows[] = ['class' => $class, 'placing' => (string) self::PLACES[strtolower($r[1])], 'horse' => $r[3], 'sire' => $r[4], 'dam' => $r[5],
                        'earnings_cents' => $r[2] !== '' ? (int) str_replace(',', '', $r[2]) * 100 : null, 'year' => $year];
                }
            } elseif ($t !== '' && ! str_contains($t, '–') && mb_strlen($t) < 120) {
                $class = $t;
            }
        }

        return $rows;
    }

    private static function horse(string $name, ?int $year, string $breed, ?string $sex, string $sire, string $dam): array
    {
        $registry = stripos($breed, 'AQHA') !== false ? 'AQHA' : (stripos($breed, 'APHA') !== false ? 'APHA' : null);

        return [
            'name' => NrchaHarvester::display(trim($name)), 'foal_year' => $year, 'registry' => $registry, 'color' => null, 'sex' => $sex,
            'birth_date' => null, 'registration_number' => null, 'results' => [],
            'pedigree' => array_filter(['s' => NrchaHarvester::display(trim($sire)), 'd' => NrchaHarvester::display(trim($dam))]),
        ];
    }

    private function import(array $h, string $url): void
    {
        if ($h['name']) {
            $this->out[app(ImportPublishedHorse::class)($h, $url)]++;
        }
    }

    private function stallions(): void
    {
        $page = HarvestPage::firstOrNew(['url' => self::BASE.'stallions/'], ['data_source_id' => $this->source->id, 'kind' => 'index']);
        if ($page->status === 'parsed' || ($html = $this->get($page)) === null) {
            return;
        }
        foreach (self::stallionList($html) as $h) {
            $this->import($h, $page->url);
            $this->out['stallions']++;
        }
        $page->fill(['status' => 'parsed', 'rows' => $this->out['stallions']])->save();
    }

    private function foals(): void
    {
        $lookup = $this->fetch(self::BASE.'horse-lookup/')?->body() ?? '';
        if (! preg_match('#name="wdtNonceFrontendServerSide_'.self::LOOKUP_TABLE.'" value="([a-f0-9]+)"#', $lookup, $n)) {
            return;
        }
        $length = 500;
        for ($start = 0; $start < 100000; $start += $length) {
            $url = self::BASE.'wp-admin/admin-ajax.php?action=get_wdtable&table_id='.self::LOOKUP_TABLE.'&start='.$start.'&length='.$length;
            $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'foal_lookup']);
            $json = $page->path && Storage::disk('local')->exists($page->path) ? gzdecode(Storage::disk('local')->get($page->path)) : null;
            if ($json === null) {
                $r = $this->fetch(self::BASE.'wp-admin/admin-ajax.php?action=get_wdtable&table_id='.self::LOOKUP_TABLE, [
                    'draw' => 1, 'start' => $start, 'length' => $length, 'wdtNonce' => $n[1],
                ]);
                if (! $r || ! $r->successful() || ! is_array($r->json('data'))) {
                    $page->fill(['status' => 'failed', 'http_status' => $r?->status(), 'error' => 'No table data', 'fetched_at' => now()])->save();

                    return;
                }
                $json = $r->body();
                $file = 'harvest/premiersires/'.sha1($url).'.json.gz';
                Storage::disk('local')->put($file, gzencode($json, 9));
                $page->fill(['path' => $file, 'sha256' => hash('sha256', $json), 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();
            }
            $data = json_decode($json, true);
            if ($page->status !== 'parsed') {
                foreach ($data['data'] ?? [] as $row) {
                    if ($h = self::foalRow($row)) {
                        $this->import($h, self::BASE.'horse-lookup/');
                        $this->out['foals']++;
                    }
                }
                $page->fill(['status' => 'parsed', 'rows' => count($data['data'] ?? [])])->save();
                if ($this->log) {
                    ($this->log)('foals to '.($start + $length).': '.json_encode($this->out));
                }
            }
            if ($start + $length >= (int) ($data['recordsTotal'] ?? 0)) {
                return;
            }
        }
    }

    private function results(): void
    {
        $index = $this->fetch(self::BASE.'results/')?->body() ?? '';
        preg_match_all('#href="(https://premiersires\.com/(?:thepremier-results-\d{4}/|wp-content/uploads/[^"]+\.pdf))"#', $index, $m);
        foreach (array_unique($m[1]) as $url) {
            if (str_contains($url, 'Riders-List')) {
                continue;
            }
            $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'results']);
            if (in_array($page->status, ['parsed', 'skipped'], true) || ($body = $this->get($page)) === null) {
                continue;
            }
            if (str_ends_with($url, '.pdf')) {
                $rows = array_map(fn ($r) => ['class' => $r['class_name'], 'placing' => $r['placing'], 'horse' => $r['horse'], 'sire' => null, 'dam' => null,
                    'earnings_cents' => $r['earnings_cents'] ?? null, 'year' => $r['year'], 'event' => $r['event'].' (show #'.$r['show_no'].')', 'code' => $r['class_code']],
                    StatePdfParser::publicationReport(StatePdfHarvester::text($body)));
            } else {
                $rows = self::resultPage($body);
            }
            $this->out['results'] += $this->store($page, $rows);
            $page->fill(['status' => $rows ? 'parsed' : 'skipped', 'rows' => count($rows)])->save();
        }
    }

    private function store(HarvestPage $page, array $rows): int
    {
        return DB::transaction(function () use ($page, $rows) {
            $n = 0;
            $touched = [];
            foreach ($rows as $r) {
                $name = NrchaHarvester::display($r['horse']);
                if (! $r['year'] || ShowRecord::key((string) $name) === '') {
                    continue;
                }
                $record = ShowRecord::firstOrCreate(['name_key' => ShowRecord::key($name)], ['display_name' => $name]);
                $a = ShowAppearance::firstOrCreate(
                    ['harvest_page_id' => $page->id, 'show_record_id' => $record->id, 'event_name' => $r['event'] ?? 'The Premier '.$r['year'],
                        'class_code' => $r['code'] ?? null, 'class_name' => $r['class'], 'round' => null, 'placing' => $r['placing']],
                    ['data_source_id' => $this->source->id, 'horse_name' => $name, 'event_year' => $r['year'], 'organization' => 'Premier Sires',
                        'earnings_cents' => $r['earnings_cents'], 'sire_name' => $r['sire'] ? NrchaHarvester::display($r['sire']) : null,
                        'dam_name' => $r['dam'] ? NrchaHarvester::display($r['dam']) : null, 'source_url' => $page->url]
                );
                $n += $a->wasRecentlyCreated ? 1 : 0;
                $touched[$record->id] = true;
            }
            foreach (array_keys($touched) as $id) {
                CongressHarvester::refresh($id);
            }

            return $n;
        });
    }

    private function get(HarvestPage $page): ?string
    {
        if ($page->path && Storage::disk('local')->exists($page->path)) {
            return gzdecode(Storage::disk('local')->get($page->path));
        }
        $r = $this->fetch($page->url);
        $page->fill(['http_status' => $r?->status(), 'fetched_at' => now()]);
        if (! $r || ! $r->successful()) {
            $page->fill(['status' => 'failed', 'error' => $r ? 'HTTP '.$r->status() : 'Request failed'])->save();

            return null;
        }
        $file = 'harvest/premiersires/'.sha1($page->url).'.gz';
        Storage::disk('local')->put($file, gzencode($r->body(), 9));
        $page->fill(['path' => $file, 'sha256' => hash('sha256', $r->body()), 'status' => 'fetched'])->save();

        return $r->body();
    }

    private function fetch(string $url, ?array $post = null): ?\Illuminate\Http\Client\Response
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $http = Http::withHeaders(['User-Agent' => CongressHarvester::UA, 'Referer' => self::BASE.'horse-lookup/'])->timeout(90)->retry(2, 10000, throw: false);
            $r = $post ? $http->asForm()->post($url, $post) : $http->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        if ($this->log) {
            ($this->log)($r->status().' '.$url);
        }

        return $r;
    }
}
