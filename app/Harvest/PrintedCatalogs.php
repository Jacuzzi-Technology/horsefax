<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;

/**
 * The other sale catalogs in Richard's PDFNotes library (Richard, Oct 28 2026: "we should look through all the catalog
 * files in that folder then. I have them from other sales as well"). The library is Dropbox › Horsefax › Sale Catalogs ›
 * DoNotEdit, listed in Catalog Inventory.xlsx; Lolli and Billings have their own loaders. Each catalog is copied to
 * storage/app/private/printed-catalogs/<sale>/<year>-<month>.pdf and read there, never changed.
 *
 * One data source per sale company. The catalogs give no prices, so every lot is "offered" (catalogued for the sale)
 * and placed on its registration number, then by the registered-name rule (RegistrationOnlyMatch::place). Facts only:
 * consignors, owners, phone numbers and descriptions are never stored. See docs/printed-catalogs.md.
 */
class PrintedCatalogs
{
    /**
     * sale => data source, layout (PrintedCatalogParser, or "qdata" for QData hip pages read by LegacyCatalogParser) and
     * catalogs [file inside DoNotEdit, year, month or null, layout for that catalog]. "overlap": a source whose lots of the
     * same sale and lot number come first (Robin Glenn posts some of these sales' QData hip pages).
     */
    public const SALES = [
        'southwest' => [
            'key' => 'southwesthorsesale', 'name' => 'Southwest Horse Sale (Bush, LA)', 'sale' => 'Southwest Horse Sale', 'url' => 'https://www.swhorseco.com/',
            'layout' => 'southwest',
            'terms' => 'Checked Oct 29 2026: southwesthorsesale.com redirects to swhorseco.com (Wix); robots.txt allows all; no terms of use or legal page (Home, Bid Online, About Us, Contact only); no bot protection. Catalogs are Richard\'s own copies (PDFNotes); nothing fetched from the site. Facts only.',
            'catalogs' => [
                ['BBEB2DA70C8B051F/2766F2CCDDAF877B.pdf', 2014, 4], ['9ADCB11DF7010A5D/ABC9EC8994715B4E.pdf', 2014, 10],
                ['BDCE0B8E47072B06/49F957E4D6292CFB.pdf', 2016, 11], ['91D848386381B293/FD215A8A19CFE113.pdf', 2019, 11],
                ['29AE61BA620278AA/8E3F4E76FEBD1FCA.pdf', 2021, null], ['DE5FFEE132839308/79C79A4FBF8C4358.pdf', 2022, 4],
                ['F23B80F0FA6AB551/BD8C2CD1B1F83B49.pdf', 2024, null], ['1E4784F39403DF91/4FACBE3B003E3B9D.pdf', 2025, 3],
                ['9199BACA0B006F48/E406C3396FADBD8C.pdf', 2026, 3],
            ],
        ],
        'boone' => [
            'key' => 'boonecounty', 'name' => 'Boone County Draft Horse & Mule Sale (Sedalia, MO)', 'sale' => 'Boone County Draft Horse & Mule Sale',
            'url' => 'https://boonecountydrafthorseandmules.com/catalog/', 'layout' => 'boone',
            'terms' => 'Checked Oct 29 2026: boonecountyhorsesale.com redirects to boonecountydrafthorseandmules.com (WordPress); robots.txt (Yoast) allows all; no terms of use or legal page (Forms & Fees has sale terms only); no bot protection. Sale Results page gives category top prices only. Catalogs are Richard\'s own copies (PDFNotes); 2022-2023 copies are scans (not read yet). Facts only.',
            'catalogs' => [
                ['CCE5C2BEDFB555DB/8A933998AEE3783D.pdf', 2020, 9], ['03CFC1BCE3CC4521/BC6B9652EB0B67B8.pdf', 2021, 3],
                ['5C9CD86EFAB9C0AA/B0D7B385E11092C6.pdf', 2024, 3], ['FF5089ACE66911C6/4AAC3726B2BA21B1.pdf', 2024, 9],
                ['E304368CC8A7D0C5/372C13B53E653766.pdf', 2025, 3], ['171C387D9D7FF512/69F04CC1BCEFF63D.pdf', 2025, 9],
                ['4850E16E4007450E/F25838A29A2DD87B.pdf', 2026, 3], ['9DF99B20136B0539/FCC231D7ED59FF40.pdf', 2026, 9],
            ],
        ],
        'clovis' => [
            'key' => 'clovishorsesales', 'name' => 'Clovis Horse Sales (Clovis, NM; Levelland Horse Sales, Levelland, TX)', 'sale' => 'Clovis Horse Sale',
            'url' => 'https://www.levellandhorsesales.com/salecatalog', 'layout' => 'cla',
            'terms' => 'Checked Oct 29 2026: the sale moved from Clovis Livestock Auction (clovislivestock.auction, Joomla default robots.txt, no terms page) to Levelland Horse Sales (levellandhorsesales.com, Wix, robots.txt allows all). Its Terms of Use page is an image of the conditions of sale (read by OCR): no clause on copying or collecting. Catalogs are Richard\'s own copies (PDFNotes). Facts only.',
            'catalogs' => [['64011DBE7E519A88/D7091612F513B402.pdf', 2016, 3, 'cla', 'CLA Spring Horse Sale'], ['6C5A6402E766D6E0/769A8FD81F9F1DC3.pdf', 2021, 8, 'consignor', 'Clovis Horse Sales Fall Horse Sale']],
        ],
        'farmers' => [
            'key' => 'fandrlive', 'name' => 'Farmers & Ranchers Livestock Commission horse sales (Salina, KS)', 'sale' => 'Farmers & Ranchers Fall Classic Horse Sale',
            'url' => 'https://www.fandrlive.com/horse-sale-catalog.html', 'layout' => 'farmers',
            'terms' => 'Checked Oct 29 2026: fandrlive.com (Weebly) robots.txt disallows only /ajax/ and /apps/; no terms of use or legal page; no bot protection. Catalog is Richard\'s own copy (PDFNotes); its trees are set in justified type, so only sire and dam are read. Facts only.',
            'catalogs' => [['89EB5C508ED1061C/278008494B982883.pdf', 2021, 10]],
        ],
        'twincities' => [
            'key' => 'simonhorsecompany', 'name' => 'Simon Horse Company sales (Cannon Falls, MN)', 'sale' => 'Twin Cities Appaloosa, Paint & Quarter Horse Sale',
            'url' => 'https://www.simonhorsecompany.com/catalog-horse-sales', 'layout' => 'regline',
            'terms' => 'Checked Oct 29 2026: simonhorsecompany.com (Wix) robots.txt allows all; no terms of use or legal page; no bot protection. Catalog is Richard\'s own copy (PDFNotes). Facts only.',
            'catalogs' => [['7550B78812DDF58B/10F97E4611A0BB9C.pdf', 2019, 4]],
        ],
        'shawnee' => [
            'key' => 'shawneehorsesale', 'name' => 'Shawnee Horse Sale (Shawnee, OK)', 'sale' => 'Shawnee Horse Sale', 'url' => 'https://www.robinglenn.com/',
            'layout' => 'qdata', 'overlap' => ['robinglenn', 'Shawnee'],
            'terms' => 'Oct 29 2026: the sale\'s catalogs are posted on Robin Glenn (already a Horsefax source, which has Fall 2020 on); shawneehorsesales.com is a parked domain. Only Richard\'s Spring 2020 copy is read: his other Shawnee copies are sales Robin Glenn already gives. Facts only.',
            'catalogs' => [['8EA1F46CB7975932/E4344320883CA48A.pdf', 2020, 5]],
        ],
        'seymour' => [
            'key' => 'seymourselect', 'name' => 'Seymour Select Horse Sale (Seymour, IA)', 'sale' => 'Seymour Select Horse Sale', 'url' => 'https://www.robinglenn.com/',
            'layout' => 'seymour', 'overlap' => ['robinglenn', 'Seymour'],
            'terms' => 'Oct 29 2026: a benefit sale for the Seymour Amish schools with no website of its own; its QData hip pages are on Robin Glenn (already a source), so lots Robin Glenn has are skipped. Catalog is Richard\'s own copy. Facts only.',
            'catalogs' => [['1B551ECFF42C59EE/D505EFA22BB38A73.pdf', 2026, 5]],
        ],
    ];

    private string $dir;

    public function __construct(private ?\Closure $log = null, ?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/printed-catalogs');
    }

    public static function library(): string
    {
        return getenv('HOME').'/Diamond J Dropbox/Richard Jacuzzi/Horsefax/Sale Catalogs/DoNotEdit';
    }

    public static function file(int $year, ?int $month): string
    {
        return sprintf('%04d-%02d.pdf', $year, $month ?? 0);
    }

    /** Copy the catalogs from the PDFNotes library (on Richard's Mac; the server gets the copies by rsync). */
    public function copy(?string $sale = null): int
    {
        $n = 0;
        foreach (self::SALES as $key => $s) {
            if ($sale && $sale !== $key) {
                continue;
            }
            @mkdir($this->dir.'/'.$key, 0775, true);
            foreach ($s['catalogs'] as [$file, $year, $month]) {
                $to = $this->dir.'/'.$key.'/'.self::file($year, $month);
                if (! is_file($to) && is_file(self::library().'/'.$file)) {
                    copy(self::library().'/'.$file, $to);
                    $n++;
                }
            }
        }

        return $n;
    }

    public function source(string $sale): DataSource
    {
        $s = self::SALES[$sale];

        return DataSource::firstOrCreate(['key' => $s['key']], [
            'name' => $s['name'], 'url' => $s['url'], 'permission' => 'no_terms_posted',
            'terms_note' => $s['terms'].' Richard asked to read all his catalogs (Oct 28 2026).',
        ]);
    }

    public function load(?string $sale = null, bool $dryRun = false): array
    {
        $all = [];
        foreach (self::SALES as $key => $s) {
            if ($sale && $sale !== $key) {
                continue;
            }
            $out = ['catalogs' => 0, 'lots' => 0, 'with_reg' => 0, 'pedigrees' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
            $src = $dryRun ? null : $this->source($key);
            $overlap = isset($s['overlap']) ? DataSource::where('key', $s['overlap'][0])->value('id') : null;
            foreach ($s['catalogs'] as $c) {
                [, $year, $month] = $c;
                $layout = $c[3] ?? $s['layout'];
                $path = $this->dir.'/'.$key.'/'.self::file($year, $month);
                if (! is_file($path)) {
                    $this->say("missing $path");

                    continue;
                }
                $pdf = file_get_contents($path);
                $lots = match ($layout) {
                    'qdata' => self::qdata($pdf),
                    'farmers' => PrintedCatalogParser::farmers(StatePdfHarvester::text($pdf)),
                    default => PrintedCatalogParser::read($pdf, $layout, $year),
                };
                $out['catalogs']++;
                $this->say($key.' '.self::file($year, $month).': '.count($lots).' lots, '.count(array_filter($lots, fn ($l) => $l['pedigree'])).' pedigrees');
                $days = $month ? LolliHarvester::saleDays($year, $month, StatePdfHarvester::text($pdf)) : [];
                [$date, $precision] = match (true) {
                    count($days) === 1 => [$days[0], 'day'],
                    (bool) $month => [sprintf('%04d-%02d-01', $year, $month), 'month'],
                    default => [$year.'-01-01', 'year'],
                };
                $saleName = ($c[4] ?? $s['sale']).', '.($month ? date('F ', mktime(0, 0, 0, $month, 1, 2000)) : '').$year;
                foreach ($lots as $l) {
                    if ($overlap && SaleLot::where('data_source_id', $overlap)->where('lot', $l['lot'])->where('sale_name', 'ilike', '%'.$s['overlap'][1].'%')
                        ->whereBetween('sale_date', [date('Y-m-d', strtotime($date.' -45 days')), date('Y-m-d', strtotime($date.' +45 days'))])->exists()) {
                        $out['skipped_overlap'] = ($out['skipped_overlap'] ?? 0) + 1;

                        continue; // the same hip from the sale's own posting on Robin Glenn
                    }
                    $out['lots']++;
                    $out['with_reg'] += $l['registration_number'] ? 1 : 0;
                    $out['pedigrees'] += $l['pedigree'] ? 1 : 0;
                    if ($dryRun) {
                        continue;
                    }
                    $lot = SaleLot::record($src->id, $key.':'.sprintf('%04d-%02d', $year, $month ?? 0).':'.$l['lot'], [
                        'sale_name' => $saleName, 'sale_date' => $date, 'date_precision' => $precision, 'lot' => $l['lot'],
                        'horse_name' => mb_substr($l['name'], 0, 255), 'registered' => $l['registered'], 'registry' => $l['registry'],
                        'registration_number' => $l['registration_number'], 'foal_year' => $l['foal_year'], 'birth_date' => $l['birth_date'],
                        'sex' => $l['sex'], 'color' => $l['color'], 'breed' => $l['breed'],
                        'sire_name' => $l['pedigree']['s'] ?? null, 'dam_name' => $l['pedigree']['d'] ?? null, 'dam_sire_name' => $l['pedigree']['ds'] ?? null,
                        'pedigree' => $l['pedigree'] ?: null, 'status' => 'offered', 'price_cents' => null, 'buyers_premium' => 'unknown', 'source_url' => $s['url'],
                        'note' => 'Catalogued for the sale (from a printed catalog copy); the catalog gives no prices',
                    ]);
                    $out[RegistrationOnlyMatch::place($lot, $s['sale'])]++;
                }
            }
            $all[$key] = $out;
        }

        return $all;
    }

    /** QData hip pages ("Hip No." layout) in PrintedCatalogParser's lot shape. */
    public static function qdata(string $pdf): array
    {
        return array_map(function ($h) {
            $grade = (bool) preg_match('/\bGrade\b/i', (string) $h['color']);
            $color = trim(preg_replace('/\b(Grade|Pony)\b/i', '', (string) $h['color'])) ?: null;

            return ['lot' => $h['hip'], 'name' => $h['name'], 'registry' => null, 'registration_number' => $grade ? null : $h['registration_number'],
                'registered' => $grade ? false : ($h['registration_number'] || $h['pedigree'] ? true : null), 'breed' => null, 'sex' => $h['sex'], 'color' => $color,
                'birth_date' => $h['birth_date'], 'foal_year' => $h['foal_year'], 'pedigree' => $grade ? [] : $h['pedigree']];
        }, LegacyCatalogParser::catalog($pdf));
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
