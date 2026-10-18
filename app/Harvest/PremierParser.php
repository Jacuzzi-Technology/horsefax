<?php

namespace App\Harvest;

use Illuminate\Support\Facades\Process;

/**
 * Premier Horse Sales (premierhorsesales.com) publishes, per sale, a "Full Sale Results" PDF (lot, horse, price or
 * "No Sale", consignor) and, before a sale, one page per catalogued horse (registered name, sex, breed, color, year
 * born, three-generation pedigree). The results PDF's column order and header wording change from year to year,
 * so its rows are rebuilt from word positions (pdftotext -bbox): find the header line, take each header's x-span
 * as a column, put each word in the nearest column. Only lot, horse and price are kept; the consignor column is read
 * to keep it out of the horse's name and then dropped.
 */
class PremierParser
{
    private const HEADERS = [
        'lot' => '/^(lot|hip|lot ?#|lot ?number|#)$/',
        'horse' => '/^(horse|title|horse ?name)$/',
        'price' => '/^(price|sale ?price)$/',
        'consignor' => '/^(consignor|trainer|seller|seller ?name)$/',
    ];

    private const VENUES = [
        '/ft-worth|fort-worth|forth-worth/' => 'Best of Texas (Fort Worth)',
        '/las-vegas/' => 'Dirt in the Dirt (Las Vegas)',
        '/ocala|ride-the-wave/' => 'Ride The Wave (Ocala)',
        '/big-sky/' => 'Big Sky (Montana)',
        '/socal|california|santa-barbara/' => 'SoCal',
    ];

    /** "2025-socal-results" -> "Premier Horse Sales 2025 SoCal" */
    public static function saleName(string $slug): string
    {
        preg_match('/(20\d\d)/', $slug, $y);
        foreach (self::VENUES as $pat => $venue) {
            if (preg_match($pat, $slug)) {
                return trim('Premier Horse Sales '.($y[1] ?? '').' '.$venue);
            }
        }

        return 'Premier Horse Sales '.trim(ucwords(str_replace('-', ' ', preg_replace('/-?(sale-)?results?$/', '', $slug))));
    }

    /** Words with positions per page, from pdftotext -bbox. @return list<list<array{0:float,1:float,2:float,3:string}>> */
    public static function words(string $pdf): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hfpdf');
        file_put_contents($tmp, $pdf);
        try {
            $html = Process::timeout(120)->run(['pdftotext', '-bbox', $tmp, '-'])->output();
        } finally {
            @unlink($tmp);
        }
        $pages = [];
        foreach (preg_split('/<page\b/', $html) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="[\d.]+">([^<]*)<\/word>/', $chunk, $m, PREG_SET_ORDER);
            $pages[] = array_map(fn ($w) => [(float) $w[1], (float) $w[2], (float) $w[3], html_entity_decode($w[4], ENT_QUOTES | ENT_HTML5)], $m);
        }

        return $pages;
    }

    /** @return list<array{lot:string, horse:string, sold:bool, price_cents:?int}> */
    public static function results(array $pages): array
    {
        $rows = [];
        $cols = null;
        foreach ($pages as $words) {
            $lines = self::lines($words);
            $start = 0;
            foreach (array_slice($lines, 0, 12) as $i => $line) {
                if ($c = self::columns($line)) {
                    $cols = $c;
                    $start = $i + 1;
                    break;
                }
            }
            if (! $cols) {
                continue;
            }
            foreach (array_slice($lines, $start) as $line) {
                $rec = self::assign($line, $cols);
                $blob = implode(' ', $rec);
                $price = null;
                if (preg_match('/\$\s*([\d,]+)(?:\.\d\d)?/', $rec['price'] ?? '', $m) || preg_match('/\$\s*([\d,]+)(?:\.\d\d)?/', $blob, $m)) {
                    $price = (int) str_replace(',', '', $m[1]) * 100;
                    $sold = true;
                } elseif (preg_match('/no\s*sale|^\s*ns\s*$|passed|scratch|withdrawn/i', $blob)) {
                    $sold = false;
                } else {
                    continue; // not a data row
                }
                $horse = trim(preg_replace(['/\$\s*[\d,]+(\.\d\d)?/', '/\bno\s*sale\b/i', '/\s+/'], ['', '', ' '], $rec['horse'] ?? ''), " .-");
                if ($horse === '' || preg_match('/^(total|average|gross)/i', $horse)) {
                    continue;
                }
                $rows[] = ['lot' => preg_replace('/[^A-Za-z0-9]/', '', $rec['lot'] ?? ''), 'horse' => $horse, 'sold' => $sold, 'price_cents' => $price];
            }
        }

        return $rows;
    }

    /** Group words into lines (4pt tolerance), left to right. */
    private static function lines(array $words): array
    {
        $rows = [];
        foreach ($words as $w) {
            if (trim($w[3]) !== '') {
                $rows[(int) round($w[1] / 4)][] = $w;
            }
        }
        ksort($rows);

        return array_values(array_map(function ($ws) {
            usort($ws, fn ($a, $b) => $a[0] <=> $b[0]);

            return $ws;
        }, $rows));
    }

    private static function columns(array $line): ?array
    {
        $joined = strtolower(implode(' ', array_column($line, 3)));
        if (! str_contains($joined, 'price')) {
            return null;
        }
        $merged = [];
        foreach ($line as $w) {
            $n = count($merged);
            if ($n && $w[0] - $merged[$n - 1][1] < 6) {
                $merged[$n - 1] = [$merged[$n - 1][0], $w[2], $merged[$n - 1][2].' '.$w[3]];
            } else {
                $merged[] = [$w[0], $w[2], $w[3]];
            }
        }
        $cols = [];
        foreach ($merged as [$x0, $x1, $text]) {
            $t = trim(preg_replace('/[^a-z# ]/', '', strtolower($text)));
            foreach (self::HEADERS as $field => $pat) {
                if (! isset($cols[$field]) && preg_match($pat, $t)) {
                    $cols[$field] = [$x0, $x1];
                }
            }
        }

        return isset($cols['price']) && count($cols) >= 3 ? $cols : null;
    }

    private static function assign(array $line, array $cols): array
    {
        $centres = array_map(fn ($c) => ($c[0] + $c[1]) / 2, $cols);
        $out = array_fill_keys(array_keys($cols), []);
        foreach ($line as $w) {
            $c = ($w[0] + $w[2]) / 2;
            $best = null;
            foreach ($centres as $f => $x) {
                if ($best === null || abs($x - $c) < abs($centres[$best] - $c)) {
                    $best = $f;
                }
            }
            $out[$best][] = $w[3];
        }

        return array_map(fn ($ws) => trim(implode(' ', $ws)), $out);
    }

    /** A catalogued horse's page. @return array{lot:?string, name:?string, sex:?string, breed:?string, color:?string, foal_year:?int, registry:?string, pedigree:array}|null */
    public static function catalogHorse(string $html): ?array
    {
        $text = fn (string $s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5)));
        $field = function (string $label) use ($html, $text) {
            return preg_match('/'.preg_quote($label, '/').'\s*:?\s*<\/[^>]+>\s*(?:<[^>]+>\s*)*([^<]+)</i', $html, $m) ? ($text($m[1]) ?: null) : null;
        };
        $name = $field('Registered Name');
        if (! $name) {
            return null;
        }
        preg_match('/Lot\s*#\s*(?:<[^>]+>\s*)*(\d+[A-Za-z]?)/i', $html, $lot);
        $year = $field('Year Born');
        $pedigree = [];
        if (preg_match('/<table[^>]*class="[^"]*pedigree_table[^"]*"[^>]*>(.*?)<\/table>/is', $html, $t)) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $t[1], $cells);
            $names = array_values(array_filter(array_map($text, $cells[1]), fn ($v) => $v !== ''));
            // Reading order of the three-generation table: horse, sire, sire's sire, its sire, its dam, sire's dam ...
            $positions = ['self', 's', 'ss', 'sss', 'ssd', 'sd', 'sds', 'sdd', 'd', 'ds', 'dss', 'dsd', 'dd', 'dds', 'ddd'];
            if (count($names) === 15) {
                foreach ($positions as $i => $pos) {
                    if ($pos !== 'self') {
                        $pedigree[$pos] = NrchaHarvester::display($names[$i]);
                    }
                }
            }
        }

        return [
            'lot' => $lot[1] ?? null, 'name' => $name, 'sex' => $field('Gender'), 'breed' => $field('Breed'), 'color' => $field('Color'),
            'foal_year' => $year && preg_match('/^(19|20)\d\d$/', $year) ? (int) $year : null,
            'registry' => $field('Registration Status'), 'pedigree' => $pedigree,
        ];
    }
}
