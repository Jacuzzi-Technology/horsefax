<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Triangle Horse Sales (trianglehorsesales.net, Shawnee OK; Winter, Spring and Fall sales), checked Oct 22 2026:
 * WordPress/Yoast robots.txt allows everything but one plugin file; the only terms posted cover marketing emails and
 * texts; no bot wall. Collected under Richard's standing approval.
 *
 * The catalog PDFs are listed by the site's own WordPress media list (/wp-json/wp/v2/media, PDFs only). For each sale
 * the latest version is used (v2/v3/"Final"). Lots are read with the Heritage/QData reader (hip, name, AQHA number,
 * foal year, color, sex, 3-generation pedigree). Triangle publishes no per-hip results (its online results are on
 * CCI.live, whose terms forbid copying, so they are never fetched): past-sale lots are "offered".
 *
 * Robin Glenn QData may hold the same catalogs: a lot whose hip and name match a Robin Glenn lot of a sale within three
 * days is not loaded again. Facts only: NOTES text, consignors and phone numbers are never kept (the kept PDFs still
 * print them). One request at a time, at least 3 s apart, as HorsefaxBot; a 403/429 or a bot check stops the run.
 */
class TriangleHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://trianglehorsesales.net';

    private DataSource $source;
    private float $lastRequest = 0;
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY);
        }
        $this->dir = $dir ?? storage_path('app/private/triangle');
        @mkdir($this->dir.'/pdf', 0775, true);
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'triangle'], [
            'name' => 'Triangle Horse Sales', 'url' => self::BASE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 22 2026: robots.txt allows everything but one plugin file; the only terms posted cover marketing emails and texts; no bot protection. Catalog PDFs from the site\'s media list. Facts only with a link back: notes, consignors and phone numbers are never stored. Results on CCI.live are not used (its terms forbid copying). Collected under Richard\'s standing approval (Oct 22 2026).',
        ]);
    }

    /** Which sale a catalog file is: [year, season] or null for forms, stall charts and the rest. */
    public static function saleOf(string $file): ?array
    {
        $f = strtolower($file);
        if (! preg_match('/catalog|_web_final/', $f) || preg_match('/stall|consign|form|repository|transfer/', $f)) {
            return null;
        }
        $season = match (true) {
            str_contains($f, 'winter') || (bool) preg_match('/\d\dtws/', $f) => 'Winter',
            str_contains($f, 'spring') || (bool) preg_match('/\d\dtss/', $f) => 'Spring',
            str_contains($f, 'fall') || (bool) preg_match('/\d\dtfs/', $f) => 'Fall',
            default => null,
        };
        $year = preg_match('/(20\d\d)/', $f, $y) ? (int) $y[1] : (preg_match('/(?:^|\D)(\d\d)t[wsf]s/', $f, $y) ? 2000 + (int) $y[1] : null);

        return $season && $year ? [$year, $season] : null;
    }

    /** Every catalog version per sale, newest first: sale key => list of [url, file, modified, rank]. */
    public static function versions(array $media): array
    {
        $all = [];
        foreach ($media as $m) {
            $url = $m['source_url'] ?? '';
            $file = basename(parse_url($url, PHP_URL_PATH) ?: '');
            if (! ($sale = self::saleOf($file))) {
                continue;
            }
            $key = $sale[0].'-'.strtolower($sale[1]);
            $rank = (preg_match('/_v(\d)/i', $file, $v) ? (int) $v[1] : 1) + (stripos($file, 'final') !== false ? 10 : 0);
            $all[$key][] = ['key' => $key, 'year' => $sale[0], 'season' => $sale[1], 'url' => $url, 'file' => $file, 'modified' => $m['modified'] ?? '', 'rank' => $rank];
        }
        foreach ($all as &$list) {
            usort($list, fn ($a, $b) => [$b['rank'], $b['modified']] <=> [$a['rank'], $a['modified']]);
        }
        ksort($all);

        return $all;
    }

    /** A catalog whose text layer reads: the catalog reader gets at least 20 hips from it (some versions have scrambled fonts). */
    public static function readable(string $pdf): bool
    {
        return count(HeritageHarvester::bestPerHip(HeritageParser::catalog(PremierParser::words($pdf)))) >= 20;
    }

    /** The newest version of each sale's catalog in the media list: sale key => [url, file, modified]. */
    public static function pickCatalogs(array $media): array
    {
        $best = [];
        foreach ($media as $m) {
            $url = $m['source_url'] ?? '';
            $file = basename(parse_url($url, PHP_URL_PATH) ?: '');
            if (! ($sale = self::saleOf($file))) {
                continue;
            }
            $key = $sale[0].'-'.strtolower($sale[1]);
            $rank = (preg_match('/_v(\d)/i', $file, $v) ? (int) $v[1] : 1) + (stripos($file, 'final') !== false ? 10 : 0);
            if (! isset($best[$key]) || [$rank, $m['modified'] ?? ''] > [$best[$key]['rank'], $best[$key]['modified']]) {
                $best[$key] = ['key' => $key, 'year' => $sale[0], 'season' => $sale[1], 'url' => $url, 'file' => $file, 'modified' => $m['modified'] ?? '', 'rank' => $rank];
            }
        }
        ksort($best);

        return $best;
    }

    /** Download the newest catalog of each sale not already kept. */
    public function collect(): array
    {
        $out = ['media' => 0, 'catalogs' => 0, 'downloaded' => 0, 'cached' => 0];
        $media = [];
        for ($page = 1; $page <= 10; $page++) {
            $body = $this->get(self::BASE.'/wp-json/wp/v2/media?mime_type=application/pdf&per_page=100&page='.$page);
            $rows = $body ? json_decode($body, true) : null;
            if (! is_array($rows) || ! $rows) {
                break;
            }
            $media = array_merge($media, $rows);
            if (count($rows) < 100) {
                break;
            }
        }
        $out['media'] = count($media);
        $cats = [];
        $out['unreadable'] = 0;
        foreach (self::versions($media) as $key => $list) {
            // The newest version whose text reads; an older one when the newest has scrambled fonts.
            foreach ($list as $c) {
                $path = $this->dir.'/pdf/'.$c['key'].'_'.$c['file'];
                if (! (is_file($path) && filesize($path) > 0)) {
                    $pdf = $this->get($c['url']);
                    if ($pdf === null || ! str_starts_with($pdf, '%PDF')) {
                        continue;
                    }
                    file_put_contents($path, $pdf);
                    $out['downloaded']++;
                } else {
                    $out['cached']++;
                }
                if (self::readable(file_get_contents($path))) {
                    $cats[$key] = $c;
                    break;
                }
                $out['unreadable']++;
            }
        }
        $out['catalogs'] = count($cats);
        file_put_contents($this->dir.'/catalogs.json', json_encode(array_values($cats), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    /** One row per catalogued hip. */
    public function rows(): array
    {
        $rows = [];
        $cats = is_file($this->dir.'/catalogs.json') ? json_decode(file_get_contents($this->dir.'/catalogs.json'), true) : [];
        foreach ($cats as $c) {
            $path = $this->dir.'/pdf/'.$c['key'].'_'.$c['file'];
            if (! is_file($path)) {
                continue;
            }
            $pdf = file_get_contents($path);
            $hips = HeritageHarvester::bestPerHip(HeritageParser::catalog(PremierParser::words($pdf)));
            $text = StatePdfHarvester::text($pdf);
            $date = self::firstDate(substr($text, 0, 12000), $c['year'], $text, $c['season']);
            foreach ($hips as $h) {
                [$name, $registry] = HeritageHarvester::registryOf($h['name'], (bool) $h['reg']);
                $ped = $h['pedigree'];
                $rows[] = ['sale_key' => $c['key'], 'sale_name' => 'Triangle Horse Sales '.$c['season'].' Sale '.$c['year'], 'sale_date' => $date, 'hip' => $h['hip'],
                    'name' => NrchaHarvester::display($name), 'grade' => $h['grade'], 'registry' => $registry, 'registration_number' => $h['reg'],
                    'foal_year' => $h['foal_year'], 'birth_date' => $h['birth_date'] ?? null, 'color' => $h['color'], 'sex' => $h['sex'],
                    'sire' => $ped['s'] ?? null, 'dam' => $ped['d'] ?? null, 'dam_sire' => $ped['ds'] ?? null, 'pedigree' => $ped, 'source_url' => $c['url']];
            }
        }

        return $rows;
    }

    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

    /**
     * The sale day: a dated cover line ("OCTOBER 30-31, 2026", "JANUARY 25, 26 & 27, 2024") in the first pages, else
     * the weekday + date the schedule pages mention most ("Saturday, May 6th"), in the catalog's year.
     */
    public static function firstDate(string $cover, int $year, string $all = '', ?string $season = null): ?string
    {
        // A date must fall in the sale's season: covers and ads also announce the next sale.
        $months = ['Winter' => [12, 1, 2], 'Spring' => [3, 4, 5, 6], 'Fall' => [8, 9, 10, 11]][$season] ?? range(1, 12);
        $fits = fn (string $d) => in_array((int) substr($d, 5, 2), $months, true);
        preg_match_all('/\b('.self::MONTHS.')\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*(?:,|[-–&]|and)\s*\d{1,2}(?:st|nd|rd|th)?)*,?\s+('.$year.')\b/i', $cover, $cm, PREG_SET_ORDER);
        foreach ($cm as [, $mon, $d, $y]) {
            $date = date('Y-m-d', strtotime("$mon $d $y"));
            if ($fits($date)) {
                return $date;
            }
        }
        preg_match_all('/\b(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),?\s+('.self::MONTHS.')\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $all, $mm, PREG_SET_ORDER);
        $days = [];
        foreach ($mm as [, $mon, $d]) {
            $k = date('Y-m-d', strtotime("$mon $d $year"));
            if ($fits($k)) {
                $days[$k] = ($days[$k] ?? 0) + 1;
            }
        }
        arsort($days);

        return array_key_first($days);
    }

    /** A Robin Glenn lot of the same horse at the same sale (hip and name agree, sale within three days). */
    public static function inRobinGlenn(array $r): bool
    {
        if (! $r['sale_date']) {
            return false;
        }
        $rg = DataSource::where('key', 'robinglenn')->value('id');

        return $rg && SaleLot::where('data_source_id', $rg)->where('lot', $r['hip'])->where('name_key', \App\Models\ShowRecord::key($r['name']))
            ->whereBetween('sale_date', [date('Y-m-d', strtotime($r['sale_date'].' -3 days')), date('Y-m-d', strtotime($r['sale_date'].' +3 days'))])->exists();
    }

    public function load(): array
    {
        $src = $this->source();
        $out = ['loaded' => 0, 'skipped_no_date' => 0, 'skipped_in_robinglenn' => 0, 'with_reg_number' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        $rows = $this->rows();
        // A sale whose hips mostly match Robin Glenn lots is Robin Glenn's catalog of it: none of its hips load again
        // (the few that don't match there differ only in spelling or reading, and would duplicate a horse).
        $hits = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['in_rg'] = self::inRobinGlenn($r);
            $hits[$r['sale_key']][] = $rows[$i]['in_rg'];
        }
        $rgSale = array_map(fn ($h) => array_sum($h) * 2 >= count($h), $hits);
        foreach ($rows as $r) {
            if (! $r['sale_date']) {
                $out['skipped_no_date']++;
                continue;
            }
            if ($r['in_rg'] || $rgSale[$r['sale_key']]) {
                $out['skipped_in_robinglenn']++;
                continue;
            }
            $past = $r['sale_date'] < now()->subDays(3)->toDateString();
            $lot = SaleLot::record($src->id, 'tri:'.$r['sale_key'].':'.$r['hip'], [
                'sale_name' => $r['sale_name'], 'sale_date' => $r['sale_date'], 'date_precision' => 'day', 'lot' => $r['hip'],
                'horse_name' => mb_substr($r['name'], 0, 255), 'registered' => $r['registration_number'] ? true : ($r['grade'] ? false : null),
                'registry' => $r['registry'], 'registration_number' => $r['registration_number'], 'foal_year' => $r['foal_year'], 'birth_date' => $r['birth_date'],
                'sex' => $r['sex'], 'color' => $r['color'], 'breed' => $r['registry'] === 'AQHA' ? 'Quarter Horse' : null,
                'sire_name' => $r['sire'], 'dam_name' => $r['dam'], 'dam_sire_name' => $r['dam_sire'], 'pedigree' => $r['pedigree'] ?: null,
                'status' => $past ? 'offered' : 'catalog', 'price_cents' => null, 'buyers_premium' => 'unknown', 'source_url' => $r['source_url'],
                'note' => $past ? 'Catalogued for the sale; Triangle publishes no per-hip prices' : 'Catalogued',
            ]);
            $out['loaded']++;
            $out['with_reg_number'] += $lot->registration_number ? 1 : 0;
            $out[RegistrationOnlyMatch::place($lot, 'Triangle Horse Sales')]++;
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
            try {
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(180)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            if ($this->log) {
                ($this->log)($status.' '.$url);
            }
            $challenge = ! str_starts_with($body, '%PDF') && preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!|Access denied)|cf-chl-/i', substr($body, 0, 20000));
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
}
