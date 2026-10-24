<?php

namespace App\Harvest;

use Illuminate\Support\Facades\Process;

/**
 * Pitzer Ranch sale catalogs (pitzerranch.net PDFs printed from Robin Glenn QData). Each lot is a block: "Hip No."
 * and the hip number, a "Consigned by" line, the name in large type, "April 7, 2026 Red Roan Wn Stud" (foaling date,
 * color, age class, sex), a 3-generation chart (2 + 4 + 8 names, "{" braces between), then "NOTES:" prose. Pages
 * alternate the block left and right of a photo, and plain `pdftotext -layout` wraps the right-hand charts badly, so
 * the lots are read from word positions (`pdftotext -bbox`).
 *
 * Facts only: hip, name, registration number (rarely printed under the chart's left name), foaling date, color, sex
 * and pedigree. The consignor line (names, phone numbers), NOTES text and photos are never returned.
 */
class PitzerParser
{
    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

    /** Word boxes per page from a PDF: list of pages, each a list of [x0, y0, x1, y1, text]. Read 25 pages at a time. */
    public static function pagesFromPdf(string $path): array
    {
        $count = preg_match('/^Pages:\s+(\d+)/m', Process::run(['pdfinfo', $path])->output(), $m) ? (int) $m[1] : 0;
        $pages = [];
        for ($f = 1; $f <= $count; $f += 25) {
            $r = Process::timeout(300)->run(['pdftotext', '-bbox', '-f', (string) $f, '-l', (string) min($count, $f + 24), $path, '-']);
            array_push($pages, ...self::pages($r->output()));
        }

        return $pages;
    }

    /**
     * Pages that are scans with an OCR text layer, by page index. Robin Glenn's pages embed subset Arial fonts
     * ("AAAAAA+ArialMT"); Acrobat's OCR text uses its own unsubset fonts (Acumin, Adobe Text, Courier Std, or
     * "Arial-Bold-13266-Identity-H" on pages edited after the scan). A page without a subset Arial font is a scan.
     *
     * @return array<int, bool>
     */
    public static function ocrPages(string $path): array
    {
        $count = preg_match('/^Pages:\s+(\d+)/m', Process::run(['pdfinfo', $path])->output(), $m) ? (int) $m[1] : 0;
        $ocr = [];
        for ($p = 1; $p <= $count; $p++) {
            $fonts = Process::run(['pdffonts', '-f', (string) $p, '-l', (string) $p, $path])->output();
            $ocr[$p - 1] = ! preg_match('/^[A-Z]{6}\+Arial/m', $fonts);
        }

        return $ocr;
    }

    /** Parse `pdftotext -bbox` XHTML into word boxes per page. */
    public static function pages(string $xhtml): array
    {
        $pages = [];
        foreach (preg_split('/<page\b/', $xhtml) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/word>/s', $chunk, $m, PREG_SET_ORDER);
            $pages[] = array_map(fn ($w) => [(float) $w[1], (float) $w[2], (float) $w[3], (float) $w[4], html_entity_decode($w[5], ENT_QUOTES | ENT_HTML5, 'UTF-8')], $m);
        }

        return $pages;
    }

    /**
     * Words joined into cells: same baseline (yMin within 1pt), gap under 4pt (wider for the large name type). Braces are dropped (they sit between the
     * chart columns and on their own baselines). Returns [x0, y0, x1, y1, text] sorted top to bottom, left to right.
     */
    public static function cells(array $words): array
    {
        $words = array_values(array_filter(array_map(function ($w) {
            $w[4] = trim(str_replace(['{', '}'], '', $w[4]));

            return $w;
        }, $words), fn ($w) => $w[4] !== ''));
        usort($words, fn ($a, $b) => [round($a[1]), $a[0]] <=> [round($b[1]), $b[0]]);
        $cells = [];
        foreach ($words as $w) {
            foreach ($cells as &$c) {
                if (abs($c[1] - $w[1]) <= 1.0 && $w[0] - $c[2] >= -0.5 && $w[0] - $c[2] < max(4.0, 0.8 * ($w[3] - $w[1]))) {
                    $c[2] = $w[2];
                    $c[3] = max($c[3], $w[3]);
                    $c[4] .= ' '.$w[4];
                    continue 2;
                }
            }
            unset($c);
            $cells[] = $w;
        }
        unset($c);
        usort($cells, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return $cells;
    }

    /** Every lot in a catalog (pages of word boxes; $ocr flags scanned pages by index), in print order. */
    public static function catalog(array $pages, array $ocr = []): array
    {
        $lots = [];
        foreach ($pages as $i => $words) {
            foreach (self::pageLots(self::cells($words), $ocr[$i] ?? false) as $lot) {
                $lots[$lot['lot']] ??= $lot; // a hip printed twice (a reprint page) keeps its first block
            }
        }

        return array_values($lots);
    }

    /** The lots on one page of cells. */
    public static function pageLots(array $cells, bool $ocr = false): array
    {
        // Anchors: a "Hip No." cell, with the hip number printed just below it. On scanned pages the number can be
        // read into the name's cell ("DGJ x Miss Bucks Diamond 333"), at the end nearest the "Hip No." label.
        $anchors = [];
        foreach ($cells as $c) {
            if (! preg_match('/^Hip No\.?$/i', $c[4])) {
                continue;
            }
            $below = array_filter($cells, fn ($h) => $h[1] > $c[1] && $h[1] - $c[1] < 25);
            $hip = null;
            foreach ($below as $h) {
                if (preg_match('/^(\d{1,4}[A-Z]?)$/', $h[4], $m) && abs(($h[0] + $h[2]) / 2 - ($c[0] + $c[2]) / 2) < 25) {
                    $hip = $m[1];
                    break;
                }
            }
            foreach ($hip ? [] : $below as $h) {
                $left = $c[0] < 200;
                if ($h[3] - $h[1] > 18 && preg_match($left ? '/^(\d{1,4}[A-Z]?)\s+\S/' : '/\S\s+(\d{1,4}[A-Z]?)$/', $h[4], $m)) {
                    $hip = $m[1];
                    break;
                }
            }
            if ($hip !== null) {
                $anchors[] = ['hip' => $hip, 'y' => $c[1], 'x' => $c[0]];
            }
        }
        usort($anchors, fn ($a, $b) => $a['y'] <=> $b['y']);
        $lots = [];
        foreach ($anchors as $i => $a) {
            $top = $a['y'] - 12;
            $bottom = isset($anchors[$i + 1]) ? $anchors[$i + 1]['y'] - 12 : PHP_FLOAT_MAX;
            $block = array_values(array_filter($cells, fn ($c) => $c[1] >= $top && $c[1] < $bottom));
            if ($lot = self::lot($a['hip'], $block, $ocr)) {
                $lots[] = $lot;
            }
        }

        return $lots;
    }

    /** One lot from its block of cells, or null when there is no name and foaling line. */
    public static function lot(string $hip, array $block, bool $ocr = false): ?array
    {
        $date = null;
        foreach ($block as $c) {
            // "April 7, 2026 Red Roan Wn Stud"; older horses give the year only ("2021 Bay Gelding").
            if (preg_match('/^(?:('.self::MONTHS.')\s+(\d{1,2}),?\s+)?((?:19|20)\d\d)\s+([A-Za-z].*)$/', $c[4], $m)) {
                $date = [$c, $m];
                break;
            }
        }
        if (! $date) {
            return null;
        }
        [$dc, $dm] = $date;
        // The name: the tallest cell above the foaling line that isn't the hip number or a label (a hip number read into
        // it on a scanned page is cut off).
        $name = null;
        foreach ($block as $c) {
            if ($c[1] >= $dc[1] || preg_match('/^(Hip No\.?|\d{1,4}[A-Z]?|Consigned by\b.*|QData.*)$/i', $c[4])) {
                continue;
            }
            if (! $name || ($c[3] - $c[1]) > ($name[3] - $name[1])) {
                $name = $c;
            }
        }
        if (! $name) {
            return null;
        }
        $label = self::tidy(preg_replace('/^'.preg_quote($hip, '/').'\s+|\s+'.preg_quote($hip, '/').'$/', '', $name[4]));
        [$color, $age, $sex] = self::description($dm[4]);
        $notes = PHP_FLOAT_MAX;
        foreach ($block as $c) {
            if ($c[1] > $dc[1] && preg_match('/^NOTES:/i', $c[4])) {
                $notes = min($notes, $c[1]);
            }
        }
        $chart = array_values(array_filter($block, fn ($c) => $c[1] > $dc[1] + 2 && $c[1] < $notes - 1 && ! preg_match('/^(QData|Consigned by)/i', $c[4])));
        [$pedigree, $reg] = self::chart($chart, $label);
        // A scanned page with an OCR text layer (see ocrPages; its chart boxes are also about twice the printed type's
        // height): the reading is unreliable ("Ova Drifly Peppy" for Dva Drifty Peppy). Keep the lot only when the name
        // reads the same in both places it is printed (the heading and the chart's left column), and drop the chart
        // and the registration number.
        $heights = array_map(fn ($c) => $c[3] - $c[1], $chart);
        sort($heights);
        $ocr = $ocr || ($heights && $heights[intdiv(count($heights), 2)] > 13);
        if ($ocr) {
            $again = array_filter($chart, fn ($c) => $c[0] < $name[0] && self::tidy($c[4]) === $label);
            if (! $again || preg_match('/[^A-Za-z0-9 .\'&\/-]/', $label)) {
                return null;
            }
            [$pedigree, $reg] = [[], null];
        }

        return [
            'lot' => $hip, 'name' => NrchaHarvester::display(trim($label, '"“” ')), 'placeholder' => self::placeholder($label), 'ocr' => $ocr,
            'registration_number' => $reg, 'foal_year' => (int) $dm[3], 'birth_date' => $dm[1] ? date('Y-m-d', strtotime("$dm[1] $dm[2] $dm[3]")) : null,
            'color' => $color, 'age_class' => $age, 'sex' => $sex, 'breed' => 'Quarter Horse', 'pedigree' => $pedigree,
        ];
    }

    /** "Red Roan Wn Stud" -> ['Red Roan', 'weanling', 'colt']; "Sorrel Gelding" -> ['Sorrel', null, 'gelding']. */
    public static function description(string $s): array
    {
        $s = self::tidy($s);
        $age = null;
        if (preg_match('/\s+(Wn|Wean(?:ling)?|Yrl|Yrlg|Yearling)\b/i', $s, $a)) {
            $age = preg_match('/^w/i', $a[1]) ? 'weanling' : 'yearling';
            $s = trim(str_replace($a[0], '', $s));
        }
        $sex = null;
        if (preg_match('/\s+(Stud Colt|Stud|Stallion|Colt|Filly|Broodmare|Mare|Gelding)$/i', $s, $x)) {
            $word = strtolower($x[1]);
            $sex = match (true) {
                str_contains($word, 'colt') => 'colt',
                $word === 'broodmare' => 'mare',
                $word === 'stud' || $word === 'stallion' => $age ? 'colt' : 'stallion', // a weanling or yearling stud is a colt (as Robin Glenn codes them)
                default => $word,
            };
            $s = trim(substr($s, 0, -strlen($x[0])));
        }
        $color = $s !== '' && preg_match('/^[A-Za-z][A-Za-z ]{1,24}$/', $s) ? ucwords(strtolower($s)) : null;

        return [$color, $age, $sex];
    }

    /**
     * The chart: cells grouped into columns by left edge (within 6pt). The three rightmost columns are the generations
     * and are used only with exactly 2, 4 and 8 names; the leftmost holds the horse's own name and, now and then, its
     * registration number. Returns [pedigree, registration number].
     */
    public static function chart(array $cells, string $name): array
    {
        $reg = null;
        $named = [];
        foreach ($cells as $c) {
            if (preg_match('/^\d{6,8}$/', $c[4])) {
                $reg ??= $c[4];
                continue;
            }
            if (self::tidy($c[4]) === self::tidy($name) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9.\'’&\-\/ ]{0,44}$/u', $c[4])) {
                continue;
            }
            $named[] = $c;
        }
        $cols = [];
        foreach ($named as $c) {
            foreach ($cols as &$col) {
                if (abs($col['x'] - $c[0]) <= 6) {
                    $col['cells'][] = $c;
                    continue 2;
                }
            }
            unset($col);
            $cols[] = ['x' => $c[0], 'cells' => [$c]];
        }
        unset($col);
        usort($cols, fn ($a, $b) => $a['x'] <=> $b['x']);
        $gens = array_slice($cols, -3);
        if (count($gens) < 3) {
            return [[], $reg];
        }
        $names = array_map(function ($col) {
            usort($col['cells'], fn ($a, $b) => $a[1] <=> $b[1]);

            return array_map(fn ($c) => NrchaHarvester::display(self::tidy($c[4])), $col['cells']);
        }, $gens);
        $p = [];
        if (count($names[0]) === 2) {
            [$p['s'], $p['d']] = $names[0];
        }
        if (count($names[1]) === 4) {
            [$p['ss'], $p['sd'], $p['ds'], $p['dd']] = $names[1];
        }
        if (count($names[2]) === 8) {
            [$p['sss'], $p['ssd'], $p['sds'], $p['sdd'], $p['dss'], $p['dsd'], $p['dds'], $p['ddd']] = $names[2];
        }
        return [isset($p['s']) ? $p : [], $reg];
    }

    /**
     * The sale "Line Up" list (Fall 2022, an Excel printout from `pdftotext -layout`): "188  LA JOLLA STARLIGHT  2014
     * CHESTNUT  GELDING  4P HORSE CO". Lot, name, foaling year, color and sex; the consignor column is never kept.
     * No pedigree is printed. Unnamed foals are listed as dam "x" sire's initials ("CORONAS N CHEX x MBJ").
     */
    public static function lineUp(string $text): array
    {
        $lots = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (! preg_match('/^\s*(\d{1,4}[A-Z]?)\s{2,}(\S.*?)\s{2,}((?:19|20)\d\d)\s{2,}([A-Z][A-Z ]{1,24}?)\s{2,}((?:(?:WN|YRL)\s+)?(?:STUD|STALLION|COLT|FILLY|BROODMARE|MARE|GELDING))(?:\s{2,}|\s*$)/', $line, $m)) {
                continue;
            }
            [$color, $age, $sex] = self::description(ucwords(strtolower($m[4].' '.$m[5])));
            $label = self::tidy($m[2]);
            $display = str_replace(' X ', ' x ', NrchaHarvester::display(strtoupper(trim($label, '"“” '))));
            $lots[$m[1]] ??= ['lot' => $m[1], 'name' => $display, 'placeholder' => self::placeholder($label), 'ocr' => false,
                'registration_number' => null, 'foal_year' => (int) $m[3], 'birth_date' => null, 'color' => $color, 'age_class' => $age, 'sex' => $sex,
                'breed' => 'Quarter Horse', 'pedigree' => []];
        }

        return array_values($lots);
    }

    /**
     * A catalog label that isn't a registered name: a foal not yet named, sire's initials "x" dam ("MB x Jessie Jo
     * Jackie"), "Pending", or a barn name in quotes ("“Blazer”"). No record is made from it.
     */
    public static function placeholder(string $name): bool
    {
        return (bool) preg_match('/\s[xX]\s|^pending\b|^unnamed\b|^tba?d?$|^["“].*["”]$/iu', trim($name));
    }

    /** Which sale: season, year, first and last day from the cover ("Friday SEPTEMBER 5 & Saturday SEPTEMBER 6, 2025"). */
    public static function sale(array $pages, ?string $season = null, ?int $year = null): array
    {
        $text = '';
        foreach (array_slice($pages, 0, 2) as $words) {
            $text .= implode(' ', array_column($words, 4)).' ';
        }
        $from = $to = null;
        if (preg_match('/\b('.self::MONTHS.')\s+(\d{1,2})\s*(?:&|-|–|and)\s*(?:[A-Za-z]+day\s+)?(?:('.self::MONTHS.')\s+)?(\d{1,2}),?\s+(20\d\d)/i', $text, $m)) {
            $from = date('Y-m-d', strtotime("$m[1] $m[2] $m[5]"));
            $to = date('Y-m-d', strtotime(($m[3] ?: $m[1])." $m[4] $m[5]"));
        } elseif (preg_match('/\b('.self::MONTHS.')\s+(\d{1,2}),?\s+(20\d\d)/i', $text, $m)) {
            $from = $to = date('Y-m-d', strtotime("$m[1] $m[2] $m[3]"));
        }
        $year ??= $from ? (int) substr($from, 0, 4) : null;
        $season ??= $from ? ((int) substr($from, 5, 2) >= 7 ? 'Fall' : 'Spring') : null;

        return ['season' => $season, 'year' => $year, 'from' => $from, 'to' => $to,
            'key' => strtolower((string) $season).'-'.$year, 'name' => 'Pitzer Ranch '.$season.' Sale '.$year];
    }

    private static function tidy(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['’', '‘'], "'", $s)));
    }
}
