<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;

/**
 * Lolli Bros. Livestock Market horse sales (Macon, MO), 2013-2026 (Richard, Oct 28 2026). lollibros.com: Joomla's
 * default robots.txt, no terms of use beyond the sale terms, no bot wall. Lolli posts each catalog as a PDF before the
 * sale and takes it down afterwards, so the catalogs come from three places, recorded per file in index.json:
 *  - richard_copy: the catalogs Richard kept in PDFNotes on his iPad (Dropbox/Horsefax/Sale Catalogs/DoNotEdit,
 *    listed in Catalog Inventory.xlsx), copied here and read, never changed; lots link to Lolli's horse page;
 *  - archive: Internet Archive copies of PDFs Lolli posted (only the copies the Archive kept whole);
 *  - dvauction: the 2013 catalog, a public download on DVAuction's file server.
 * Files are named "2015-03-fri.pdf" (sale year, month, and the day or part the catalog covers).
 *
 * The catalogs print no prices and no registration numbers (a few names carry their AQHA number), so every lot is
 * "offered" and placed by the registered-name rule (RegistrationOnlyMatch::place). Facts only: the consignor
 * ("OWNER:") and the description are never stored.
 */
class LolliHarvester
{
    public const HORSE_PAGE = 'https://www.lollibros.com/horse';

    private DataSource $source;

    private string $dir;

    public function __construct(private ?\Closure $log = null, ?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/lolli');
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'lolli'], [
            'name' => 'Lolli Bros. Livestock Market horse sales', 'url' => self::HORSE_PAGE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 22 2026: Joomla default robots.txt; no terms of use beyond the sale terms; no bot protection. Catalog PDFs are posted before each sale and removed after: catalogs from Richard\'s own copies (PDFNotes), Internet Archive copies, and DVAuction\'s public download (2013). Facts only: consignors and descriptions are never stored. Approved by Richard (Oct 28 2026).',
        ]);
    }

    /** @return array<string, array{origin:string, url:?string}> file => where it came from */
    public function index(): array
    {
        return is_file($this->dir.'/index.json') ? json_decode(file_get_contents($this->dir.'/index.json'), true) : [];
    }

    /** Lots from every kept catalog, one per sale and lot number (a supplement repeating a lot keeps the fuller copy). */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->index() as $file => $info) {
            $path = $this->dir.'/pdfs/'.$file;
            if (! preg_match('/^(\d{4})-(\d\d)-([a-z]+(?:-[ab])?)(?:-\d)?\.pdf$/', $file, $m) || ! is_file($path)) {
                continue;
            }
            $pdf = file_get_contents($path);
            $days = self::saleDays((int) $m[1], (int) $m[2], StatePdfHarvester::text($pdf));
            $saleName = 'Lolli Bros. Horse Sale, '.date('F Y', mktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]));
            $lots = LolliParser::catalog(LolliParser::words($pdf));
            $this->say($file.': '.count($lots).' lots, '.count(array_filter($lots, fn ($l) => $l['pedigree'])).' pedigrees');
            // Saturday lots are numbered from 500. A two-day catalog numbered straight through can't say which day a lot
            // sold, so its lots get the month only.
            $split = max(array_map(fn ($l) => (int) $l['lot'], $lots) ?: [0]) >= 500;
            foreach ($lots as $l) {
                $saturday = str_starts_with($m[3], 'sat') || (! str_starts_with($m[3], 'fri') && (int) $l['lot'] >= 500);
                $date = match (true) {
                    ! $days => null,
                    count($days) === 1 => $days[0],
                    str_starts_with($m[3], 'fri'), str_starts_with($m[3], 'sat'), $split => $saturday ? $days[1] : $days[0],
                    default => null,
                };
                $key = $m[1].'-'.$m[2].':'.$l['lot'];
                $row = $l + ['sale_key' => $key, 'sale_name' => $saleName, 'sale_date' => $date ?? sprintf('%04d-%02d-01', $m[1], $m[2]),
                    'date_precision' => $date ? 'day' : 'month', 'source_url' => $info['url'] ?? self::HORSE_PAGE, 'origin' => $info['origin'] ?? 'richard_copy'];
                $score = fn ($x) => ($x['pedigree'] ? 4 : 0) + ($x['foal_year'] ? 1 : 0);
                if (! isset($rows[$key]) || $score($row) > $score($rows[$key])) {
                    $rows[$key] = $row;
                }
            }
        }

        return array_values($rows);
    }

    /**
     * The sale days from the cover: "MARCH 6 & 7, 2015", "May 5-6, 2017", "November 7, 2015", "May 4th, 2024". Only a date
     * in the catalog's own month and year counts: covers also advertise the next sale ("May 3-4, 2019" in March's).
     *
     * @return list<string> one or two Y-m-d dates, or [] when the cover gives none
     */
    public static function saleDays(int $year, int $month, string $text): array
    {
        $name = date('F', mktime(0, 0, 0, $month, 1, 2000));
        if (! preg_match('/\b'.$name.'\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*(?:&|-|and)\s*(\d{1,2})(?:st|nd|rd|th)?)?,?\s+'.$year.'\b/i', substr($text, 0, 20000), $d)) {
            return [];
        }
        $days = [sprintf('%04d-%02d-%02d', $year, $month, (int) $d[1])];
        if (! empty($d[2]) && (int) $d[2] === (int) $d[1] + 1) {
            $days[] = sprintf('%04d-%02d-%02d', $year, $month, (int) $d[2]);
        }

        return $days;
    }

    public function load(bool $dryRun = false): array
    {
        $src = $this->source();
        $out = ['lots' => 0, 'pedigrees' => 0, 'foal_years' => 0, 'with_reg_number' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($this->rows() as $r) {
            $out['lots']++;
            $out['pedigrees'] += $r['pedigree'] ? 1 : 0;
            $out['foal_years'] += $r['foal_year'] ? 1 : 0;
            $out['with_reg_number'] += $r['registration_number'] ? 1 : 0;
            if ($dryRun) {
                continue;
            }
            $past = $r['sale_date'] < now()->subDays(3)->toDateString();
            $lot = SaleLot::record($src->id, 'lolli:'.$r['sale_key'], [
                'sale_name' => $r['sale_name'], 'sale_date' => $r['sale_date'], 'date_precision' => $r['date_precision'], 'lot' => $r['lot'],
                'horse_name' => mb_substr($r['name'], 0, 255), 'registered' => $r['registered'], 'registry' => $r['registry'],
                'registration_number' => $r['registration_number'], 'foal_year' => $r['foal_year'], 'birth_date' => $r['birth_date'],
                'sex' => $r['sex'], 'color' => $r['color'], 'breed' => $r['breed'],
                'sire_name' => $r['pedigree']['s'] ?? null, 'dam_name' => $r['pedigree']['d'] ?? null, 'dam_sire_name' => $r['pedigree']['ds'] ?? null, 'pedigree' => $r['pedigree'] ?: null,
                'status' => $past ? 'offered' : 'catalog', 'price_cents' => null, 'buyers_premium' => 'unknown', 'source_url' => $r['source_url'],
                'note' => $past ? 'Catalogued for the sale; Lolli\'s catalogs give no prices'.($r['origin'] === 'richard_copy' ? ' (from a printed catalog copy)' : '') : 'Catalogued',
            ]);
            $out[RegistrationOnlyMatch::place($lot, 'Lolli Bros.')]++;
        }

        return $out;
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
