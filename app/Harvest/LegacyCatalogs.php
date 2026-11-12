<?php

namespace App\Harvest;

use App\Actions\Record\AddPublishedIdentity;
use App\Actions\Record\BuildRecordsFromResults;
use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Legacy Reining Breeders Sale catalogs (Richard, Oct 28 2026: "this sale has been going on for many years ... if we
 * can find the previous ones too"). The results pages (LegacySaleHarvester) give hip, horse and price; the catalogs add
 * the AQHA number, foaling date, color, sex and the three-generation pedigree.
 *
 * Where they are: the full catalog PDF for 2024, 2025 and the current sale (/pdf/), and from 2018 one PDF per hip in the
 * WordPress uploads (/wp-content/uploads/2018/08/1.pdf, ..._Part1.pdf), whose names come from the Internet Archive's
 * index. Each file is read from Legacy's own server; the Archive copy only when Legacy no longer has it. Same house
 * rules as the results harvest (robots.txt disallows only /wp-admin/ and the wpforms uploads; no terms of use).
 *
 * A catalog hip fills the results lot of the same year and hip when the names agree (TopekaParser::sameName), and that
 * lot's record gets what it's missing (AddPublishedIdentity); a lot without a record is placed again with its number.
 * Before 2018 the catalogs are in other layouts (2005, 2010 and 2015 full catalogs, 2014-15 per-hip pages, Archive
 * copies only) and aren't read yet.
 */
class LegacyCatalogs
{
    public const BASE = LegacySaleHarvester::BASE;

    /** Legacy's results pages start in 2014; an earlier catalog is the only record of its sale. */
    public const FIRST_RESULTS_YEAR = 2014;

    /** Full catalogs on Legacy's server, by sale year. */
    public const FULL = [
        2024 => '/pdf/2024_Legacy_Catalog.pdf',
        2025 => '/pdf/Legacy25Catalog.pdf',
        2026 => '/pdf/catalog.pdf',
    ];

    private DataSource $source;

    private float $lastRequest = 0;

    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->delay = max($this->delay, app()->runningUnitTests() ? 0 : 3.0);
        $this->source = DataSource::where('key', 'legacysale')->firstOrFail();
        $this->dir = storage_path('app/private/legacysale/catalogs');
        @mkdir($this->dir, 0775, true);
    }

    /** @return array<string, array{year:int, url:string, archive:?string}> local file => where it comes from */
    public function files(): array
    {
        $files = [];
        foreach (self::FULL as $year => $path) {
            $files[$year.'-full.pdf'] = ['year' => $year, 'url' => self::BASE.$path, 'archive' => null];
        }
        // The Archive's index of the uploads; the last good copy is kept (the Archive is sometimes down: 503).
        $index = $this->dir.'/archive-index.json';
        $r = $this->get('https://web.archive.org/cdx/search/cdx?url=legacysale.com/wp-content/uploads/*&output=json&fl=original,timestamp&filter=mimetype:application/pdf&filter=statuscode:200&collapse=urlkey&limit=5000');
        $rows = json_decode((string) $r, true);
        if (is_array($rows) && count($rows) > 1) {
            file_put_contents($index, json_encode($rows));
        } else {
            $rows = is_file($index) ? json_decode(file_get_contents($index), true) : [];
        }
        foreach (array_slice($rows, 1) as [$original, $ts]) {
            if (! preg_match('#/wp-content/uploads/(20\d\d)/(0[89])/([^/]+\.pdf)$#i', $original, $m) || preg_match('/consign|contract|form|entry|agreement|schedule|map|flyer|w-?9|invoice|terms|condition/i', $m[3])) {
                continue; // catalog pages only, named differently each year ("1.pdf", "FRIDAY-HORSES-10.pdf", "2021-Legacy-Final-1-128-10.pdf")
            }
            $files[$m[1].'-'.$m[2].'-'.$m[3]] = ['year' => (int) $m[1], 'url' => self::BASE.'/wp-content/uploads/'.$m[1].'/'.$m[2].'/'.$m[3],
                'archive' => 'https://web.archive.org/web/'.$ts.'id_/'.preg_replace('#^http:#', 'https:', $original)];
        }
        ksort($files, SORT_NATURAL);

        return $files;
    }

    /** Download every catalog file not already kept. */
    public function collect(): array
    {
        $out = ['files' => 0, 'downloaded' => 0, 'from_archive' => 0, 'cached' => 0, 'unavailable' => 0];
        $files = $this->files();
        foreach ($files as $file => $f) {
            $out['files']++;
            $path = $this->dir.'/'.$file;
            if (is_file($path) && filesize($path) > 0) {
                $out['cached']++;
                continue;
            }
            $body = $this->get($f['url']);
            $from = 'downloaded';
            if (($body === null || ! str_starts_with($body, '%PDF')) && $f['archive']) {
                [$body, $from] = [$this->get($f['archive']), 'from_archive'];
            }
            if ($body === null || ! str_starts_with($body, '%PDF')) {
                $out['unavailable']++;
                continue;
            }
            file_put_contents($path, $body);
            $out[$from]++;
        }
        file_put_contents($this->dir.'/index.json', json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    /** Read the kept catalogs and fill the results lots and their records. */
    public function load(): array
    {
        $out = ['hips' => 0, 'with_reg' => 0, 'full_pedigrees' => 0, 'catalog_lots' => 0, 'lots_filled' => 0, 'records_filled' => 0, 'no_results_lot' => 0, 'other_name' => 0, 'placed' => []];
        $system = BuildRecordsFromResults::system();
        $seen = [];
        // Older catalogs (2005, 2010, 2015 full catalogs; Internet Archive copies) are kept in old/ as "2010.pdf".
        foreach (array_merge(glob($this->dir.'/*.pdf'), glob(dirname($this->dir).'/old/*.pdf')) as $path) {
            if (! preg_match('/^(20\d\d)\b/', basename($path), $y)) {
                continue;
            }
            $year = (int) $y[1];
            $pdf = file_get_contents($path);
            foreach ($year === 2005 ? LegacyCatalogParser::catalog2005($pdf) : LegacyCatalogParser::catalog($pdf) as $h) {
                if (isset($seen[$year][$h['hip']])) {
                    continue; // the same hip in the full catalog and its own page
                }
                $seen[$year][$h['hip']] = true;
                $out['hips']++;
                $out['with_reg'] += $h['registration_number'] ? 1 : 0;
                $out['full_pedigrees'] += count($h['pedigree']) === 14 ? 1 : 0;
                $lots = SaleLot::where('data_source_id', $this->source->id)->where('sale_year', $year)->where('lot', $h['hip'])->get();
                $lot = $lots->first(fn ($l) => TopekaParser::sameName($l->horse_name, $h['name']));
                if (! $lot && $lots->isEmpty() && $year < self::FIRST_RESULTS_YEAR) {
                    // No results pages before 2014: the catalog is the only record of the sale.
                    $lot = $this->catalogLot($year, $h, basename($path));
                    $out['catalog_lots']++;
                    $p = RegistrationOnlyMatch::place($lot, LegacySaleHarvester::SALE);
                    $out['placed'][$p] = ($out['placed'][$p] ?? 0) + 1;
                    continue;
                }
                if (! $lot) {
                    $out[$lots->isEmpty() ? 'no_results_lot' : 'other_name']++;
                    continue;
                }
                $lot->forceFill(array_filter([
                    'registration_number' => $lot->registration_number ? null : $h['registration_number'], 'registry' => $lot->registry ?: ($h['registration_number'] ? 'AQHA' : null),
                    'registered' => $h['registration_number'] ? true : null,
                    'birth_date' => $lot->birth_date ? null : $h['birth_date'], 'foal_year' => $lot->foal_year ? null : $h['foal_year'],
                    'sex' => $lot->sex ? null : $h['sex'], 'color' => $lot->color ? null : $h['color'],
                    'pedigree' => count((array) $lot->pedigree) >= count($h['pedigree']) ? null : ($h['pedigree'] ?: null),
                    'sire_name' => $lot->sire_name ? null : ($h['pedigree']['s'] ?? null), 'dam_name' => $lot->dam_name ? null : ($h['pedigree']['d'] ?? null),
                    'dam_sire_name' => $lot->dam_sire_name ? null : ($h['pedigree']['ds'] ?? null),
                ], fn ($v) => $v !== null))->save();
                $out['lots_filled']++;
                if ($lot->horse) {
                    $filled = app(AddPublishedIdentity::class)($lot->horse, $system, ['registry' => $lot->registry, 'registration_number' => $lot->registration_number,
                        'foal_year' => $lot->foal_year, 'pedigree' => (array) $lot->pedigree], 'sale lot '.$lot->id);
                    $out['records_filled'] += $filled ? 1 : 0;
                } else {
                    $p = RegistrationOnlyMatch::place($lot, LegacySaleHarvester::SALE);
                    $out['placed'][$p] = ($out['placed'][$p] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    /** A hip from a catalog with no published results (before 2014): offered, no price. */
    private function catalogLot(int $year, array $h, string $file): SaleLot
    {
        $archived = ['2005.pdf' => 'https://web.archive.org/web/20051221083317/http://www.legacysale.com/2005LegacyCatalog.pdf',
            '2010.pdf' => 'https://web.archive.org/web/20100910160810/http://www.legacysale.com/2010-Legacy_Sale-Catalog.pdf'][$file] ?? self::BASE;

        return SaleLot::record($this->source->id, 'legacy-catalog:'.$year.':'.$h['hip'], [
            'sale_name' => $year.' '.LegacySaleHarvester::SALE, 'sale_date' => $year.'-09-01', 'date_precision' => 'month', 'lot' => $h['hip'],
            'horse_name' => mb_substr($h['name'], 0, 255), 'registered' => $h['registration_number'] || $h['pedigree'] ? true : null,
            'registry' => $h['registration_number'] ? 'AQHA' : null, 'registration_number' => $h['registration_number'],
            'foal_year' => $h['foal_year'], 'birth_date' => $h['birth_date'], 'sex' => $h['sex'], 'color' => $h['color'], 'breed' => 'Quarter Horse',
            'sire_name' => $h['pedigree']['s'] ?? null, 'dam_name' => $h['pedigree']['d'] ?? null, 'dam_sire_name' => $h['pedigree']['ds'] ?? null, 'pedigree' => $h['pedigree'] ?: null,
            'status' => 'offered', 'price_cents' => null, 'buyers_premium' => 'unknown', 'source_url' => $archived,
            'note' => 'Catalogued for the sale (Internet Archive copy of the catalog); no results published for this year',
        ]);
    }

    private function get(string $url): ?string
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(300)->get($url);
        } catch (\Throwable $e) {
            $this->say('failed '.$url.': '.$e->getMessage());

            return null;
        }
        $this->say($r->status().' '.$url);
        if (in_array($r->status(), [403, 429], true) && str_contains($url, 'legacysale.com')) {
            throw new HarvestBlocked("Blocked or rate-limited at $url (HTTP {$r->status()}): stopping. No workaround.");
        }

        return $r->successful() ? $r->body() : null;
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
