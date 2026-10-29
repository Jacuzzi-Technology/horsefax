<?php

namespace App\Harvest;

use Illuminate\Support\Facades\Process;

/**
 * Lolli Bros. Livestock Market (Macon, MO) horse-sale catalogs, 2013-2026, from word positions (pdftotext -bbox).
 *
 * Every lot prints a header, then a three-generation pedigree tree of 14 names and the seller's description:
 *  - 2013 to 2024: "LOT 21  PLAYBOYSSMOKENBOON  AQHA" / "FOALED: 4/23/2001  MARE-BRED  CHESTNUT" / "OWNER: ..."
 *  - Nov 2023 on: the registered name in large type, the lot number beside "04/28/2013 • MARE BRED • BUCKSKIN • AQHA"
 *    (or " - " between the parts), then the consignor.
 * Two lots sit side by side on most pages, so a lot's words are those below its header, in its column, above the next
 * header in that column. In the tree the sire and dam are left-aligned in one column and the four grandparents in a
 * second; the eight great-grandparents sit further right (often right-aligned, so their left edges vary). A tree is kept
 * only when it reads as exactly 2 + 4 + 8 names; anything else (a grade horse, a missing line, names run together) is
 * left out rather than guessed.
 *
 * The 2015-2017 catalogs use a small-caps font whose text comes out in mixed case ("blueligHt jAc e"): names are
 * matched in any case and stored in capitals. The consignor ("OWNER:") and the description are never kept.
 */
class LolliParser
{
    private const SEX = 'MARE|GELDING|STALLION|STUD COLT|STUD|COLT|FILLY|HORSE|PONY|MULE|MOLLY|JOHN|JENNY|JACK|YEARLING|WEANLING|GELDING PONY|MARE PONY';

    /** Registry as printed => [registry, breed]. "ApHA" in the 2015 catalogs is the Paint registry misspelt (sorrel and white horses). */
    private const REGISTRIES = [
        'AQHA' => ['AQHA', 'Quarter Horse'], 'APHA' => ['APHA', 'Paint Horse'], 'APHC' => ['ApHC', 'Appaloosa'],
        'PTHA' => ['PtHA', 'Pinto'], 'PHBA' => ['PHBA', 'Palomino'], 'ABRA' => ['ABRA', 'Buckskin'], 'IBHA' => ['IBHA', 'Buckskin'],
        'ASPC' => ['ASPC', 'Shetland Pony'], 'POA' => ['POA', 'Pony of the Americas'], 'MFTHBA' => ['MFTHBA', 'Missouri Fox Trotter'],
        'TWHBEA' => ['TWHBEA', 'Tennessee Walking Horse'], 'JC' => ['JC', 'Thoroughbred'], 'GVHS' => ['GVHS', 'Gypsy Vanner'],
        'RMHA' => ['RMHA', 'Rocky Mountain Horse'], 'KMSHA' => ['KMSHA', 'Kentucky Mountain Saddle Horse'], 'NSHR' => ['NSHR', 'Spotted Saddle Horse'],
    ];

    /** Words with positions per page: [xMin, yMin, xMax, yMax, text]. @return list<list<array{0:float,1:float,2:float,3:float,4:string}>> */
    public static function words(string $pdf): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hfpdf');
        file_put_contents($tmp, $pdf);
        try {
            $html = Process::timeout(300)->run(['pdftotext', '-bbox', $tmp, '-'])->output();
        } finally {
            @unlink($tmp);
        }
        $pages = [];
        foreach (preg_split('/<page\b/', $html) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<\/word>/', $chunk, $m, PREG_SET_ORDER);
            $words = [];
            foreach ($m as $w) {
                $t = trim(str_replace("\u{00A0}", ' ', html_entity_decode($w[5], ENT_QUOTES | ENT_HTML5)));
                if ($t !== '') {
                    $words[] = [(float) $w[1], (float) $w[2], (float) $w[3], (float) $w[4], $t];
                }
            }
            $pages[] = $words;
        }

        return $pages;
    }

    /**
     * @param  list<list<array>>  $pages  from words()
     * @return list<array{lot:string, name:?string, registration_number:?string, registry:?string, breed:?string, registered:?bool, birth_date:?string, foal_year:?int, sex:?string, color:?string, pedigree:array<string,string>}>
     */
    public static function catalog(array $pages): array
    {
        $out = [];
        foreach ($pages as $words) {
            if (! $words) {
                continue;
            }
            $width = max(array_map(fn ($w) => $w[2], $words));
            $segs = self::segments($words);
            $anchors = self::anchors($segs);
            foreach ($anchors as $a) {
                $region = self::region($a, $anchors, $segs, $width);
                if ($lot = self::lot($a, $region, $segs)) {
                    $out[] = $lot;
                }
            }
        }
        // A lot printed twice (a catalog that repeats a page): keep the fuller copy.
        $best = [];
        foreach ($out as $l) {
            $score = fn ($x) => ($x['pedigree'] ? 4 : 0) + ($x['name'] ? 2 : 0) + ($x['foal_year'] ? 1 : 0);
            if (! isset($best[$l['lot']]) || $score($l) > $score($best[$l['lot']])) {
                $best[$l['lot']] = $l;
            }
        }

        return array_values($best);
    }

    /** Words -> runs of text: same line, small gap, same type size. */
    public static function segments(array $words): array
    {
        usort($words, fn ($a, $b) => [round(($a[1] + $a[3]) / 5), $a[0]] <=> [round(($b[1] + $b[3]) / 5), $b[0]]);
        $segs = [];
        foreach ($words as $w) {
            $h = $w[3] - $w[1];
            $yc = ($w[1] + $w[3]) / 2;
            $n = count($segs);
            if ($n) {
                $s = &$segs[$n - 1];
                $ratio = $h / max($s['h'], 0.1);
                if (abs($s['yc'] - $yc) <= 2.5 && $w[0] - $s['x1'] >= 0 && $w[0] - $s['x1'] < max(4.0, $h * 0.9) && $ratio > 0.7 && $ratio < 1.43) {
                    $s['t'] .= ' '.$w[4];
                    $s['x1'] = $w[2];
                    unset($s);

                    continue;
                }
                unset($s);
            }
            $segs[] = ['x' => $w[0], 'y' => $w[1], 'x1' => $w[2], 'yc' => $yc, 'h' => $h, 't' => $w[4]];
        }

        return $segs;
    }

    /** Lot headers on a page: [kind, lot, x, y, head (segment index), foal (segment index), name]. */
    private static function anchors(array &$segs): array
    {
        // "LOT" and its number are printed large and can come out as two runs.
        foreach ($segs as $i => $s) {
            if (strtoupper($s['t']) !== 'LOT') {
                continue;
            }
            $next = null;
            foreach ($segs as $j => $o) {
                if ($j !== $i && abs($o['yc'] - $s['yc']) < 4 && $o['x'] - $s['x1'] >= 0 && $o['x'] - $s['x1'] < 40 && preg_match('/^\d{1,4}[A-Z]?\b/', $o['t'])
                    && ($next === null || $o['x'] < $segs[$next]['x'])) {
                    $next = $j;
                }
            }
            if ($next !== null) {
                $segs[$i]['t'] = 'LOT '.$segs[$next]['t'];
                $segs[$i]['x1'] = $segs[$next]['x1'];
                $segs[$next]['t'] = '';
            }
        }
        $anchors = [];
        foreach ($segs as $i => $s) {
            if (! preg_match('/^LOT\s+(\d{1,4}[A-Z]?)\b\s*(.*)$/i', $s['t'], $m)) {
                continue;
            }
            $foal = null;
            foreach ($segs as $j => $f) {
                $dy = $f['y'] - $s['y'];
                if ($dy > 0 && $dy < 40 && abs($f['x'] - $s['x']) < 140 && preg_match('/^(FOALED|\d{1,2}[\/-]\d{1,3}[\/-]\d{2,4}$)/i', $f['t'])
                    && ($foal === null || $dy < $segs[$foal]['y'] - $s['y'])) {
                    $foal = $j;
                }
            }
            if ($foal === null) {
                continue; // a photo caption ("Lot 21") or a lot label with no header under it
            }
            $name = trim($m[2]);
            if ($name === '') { // the name is the next run on the header line
                $best = null;
                foreach ($segs as $j => $o) {
                    if ($j !== $i && self::sameLine($o, $s) && $o['x'] - $s['x1'] > 0 && $o['x'] - $s['x1'] < 120 && ($best === null || $o['x'] < $segs[$best]['x'])) {
                        $best = $j;
                    }
                }
                $name = $best !== null ? $segs[$best]['t'] : '';
            }
            $anchors[$m[1]] ??= ['kind' => 'A', 'lot' => $m[1], 'x' => min($s['x'], $segs[$foal]['x']), 'y' => $s['y'], 'head' => $i, 'foal' => $foal, 'name' => $name];
            if ($name !== '' && $anchors[$m[1]]['name'] === '') {
                $anchors[$m[1]] = ['kind' => 'A', 'lot' => $m[1], 'x' => min($s['x'], $segs[$foal]['x']), 'y' => $s['y'], 'head' => $i, 'foal' => $foal, 'name' => $name];
            }
        }
        if ($anchors) {
            return array_values($anchors);
        }
        // From Nov 2023: "04/28/2013 • MARE BRED • BUCKSKIN • AQHA" with the lot number in large type to its left.
        foreach ($segs as $j => $s) {
            if (! preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}\s*[•\-–]\s*/u', $s['t'])) {
                continue;
            }
            $num = null;
            $cost = INF;
            foreach ($segs as $k => $n) {
                if (preg_match('/^\d{1,4}$/', $n['t']) && $n['x1'] < $s['x'] + 5 && abs($n['yc'] - $s['yc']) < 30 && $s['x'] - $n['x1'] < 120) {
                    $c = abs($n['yc'] - $s['yc']) + ($s['x'] - $n['x1']) / 10;
                    if ($c < $cost) {
                        [$num, $cost] = [$k, $c];
                    }
                }
            }
            if ($num === null) {
                continue;
            }
            // The registered name: the large-type run just above the lot number, in the same column.
            $name = null;
            $nameY = -INF;
            foreach ($segs as $o) {
                $dy = $segs[$num]['y'] - $o['y'];
                if ($o['h'] > $s['h'] * 1.5 && $o['h'] < $segs[$num]['h'] * 0.9 && $dy > -5 && $dy < 45 && $o['x'] > $segs[$num]['x'] - 20 && $o['x'] < $segs[$num]['x'] + 200 && $o['y'] > $nameY) {
                    [$name, $nameY] = [$o['t'], $o['y']];
                }
            }
            $anchors[] = ['kind' => 'B', 'lot' => $segs[$num]['t'], 'x' => $segs[$num]['x'], 'y' => min($segs[$num]['y'], $s['y']), 'head' => $num, 'foal' => $j, 'name' => $name ?? ''];
        }

        return $anchors;
    }

    /** The runs belonging to a lot: below its header, in its column, above the next header in that column. */
    private static function region(array $a, array $anchors, array $segs, float $width): array
    {
        $bottom = INF;
        $right = $width + 1;
        foreach ($anchors as $b) {
            if ($b === $a) {
                continue;
            }
            if (abs($b['x'] - $a['x']) < $width * 0.25 && $b['y'] > $a['y']) {
                $bottom = min($bottom, $b['y']);
            }
        }
        foreach ($anchors as $b) {
            if ($b['x'] > $a['x'] + $width * 0.25 && $b['y'] < $bottom + 1) {
                $right = min($right, $b['x'] - 14); // the right-hand lot's sire starts a little left of its header
            }
        }

        return array_values(array_filter($segs, fn ($s) => $s['t'] !== '' && $s['y'] >= $a['y'] - 2 && $s['y'] < $bottom - 2 && $s['x'] >= $a['x'] - 30 && $s['x'] < $right - 5));
    }

    private static function lot(array $a, array $region, array $segs): ?array
    {
        $foal = $segs[$a['foal']];
        $name = self::cleanName($a['name']);
        $reg = null;
        if (preg_match('/^(.+?)\s+#?(\d{7})$/', $name, $r)) { // "REY LENA METALLIC 6161578": the AQHA number after the name
            [$name, $reg] = [$r[1], $r[2]];
        }
        // Header line: the registry at the right end (2013-2024).
        $breedText = null;
        $foalText = $foal['t'];
        if ($a['kind'] === 'A') {
            $head = $segs[$a['head']];
            foreach ($region as $s) {
                if (self::sameLine($s, $head) && abs($s['yc'] - $foal['yc']) >= 3 && $s['x'] > $head['x1'] && strtoupper(self::cleanName($s['t'])) !== $name && preg_match('/^[A-Za-z][A-Za-z .\/-]{1,24}$/', $s['t'])) {
                    $breedText = $s['t'];
                }
                if (abs($s['yc'] - $foal['yc']) < 3 && $s['x'] > $foal['x']) {
                    $foalText .= ' '.$s['t'];
                }
            }
            $id = self::describe($foalText, $breedText);
        } else {
            $id = self::describeLine($foalText);
        }
        if (! $name || ! preg_match('/\pL/u', $name) || preg_match('/\b(NOT SELLING|SCRATCHED|WITHDRAWN)\b/', $name)) {
            return null;
        }

        if ($id['color'] && ($r = self::registry($id['color'])) && $r['registry']) { // "AQHA" printed where the color goes
            $id = array_merge($id, $id['registry'] ? [] : $r, ['color' => null]);
        } elseif (in_array($id['color'], ['Paint', 'Grade', 'Pinto', 'Appaloosa', 'Aqha'], true)) {
            $id['color'] = null;
        }

        return ['lot' => $a['lot'], 'name' => $name, 'registration_number' => $reg && ($id['registry'] ?? null) === 'AQHA' ? $reg : null,
            'pedigree' => self::pedigree($region, $foal['y'] + 1, $name)] + $id;
    }

    /** $o sits on $s's line: its middle falls inside $s's height (the large "LOT 21" and the name beside it don't share a middle). */
    private static function sameLine(array $o, array $s): bool
    {
        return $o['yc'] > $s['y'] - 1 && $o['yc'] < $s['y'] + $s['h'] + 1 && $o['h'] <= $s['h'] * 1.43;
    }

    /** A pedigree name: one line, short, not a sentence. */
    public static function nameLike(string $t): bool
    {
        $t = trim($t);
        if (mb_strlen($t) < 2 || mb_strlen($t) > 24 || ! preg_match("/^[A-Za-z0-9][A-Za-z0-9 '’&.\\-\\/#()]*$/u", $t) || preg_match('/^\d+$/', $t)) {
            return false;
        }
        if (preg_match('/\b(LOT|FOALED|OWNER|SOLD|PENDING|NOT SELLING)\b/', strtoupper($t))) {
            return false;
        }
        if (preg_match('/[a-z]{4,}\.$|\b[A-Za-z]{4,}\.\s/', $t) || count(preg_split('/\s+/', $t)) > 5) {
            return false; // a sentence ("MR." / "JR." are fine)
        }

        return ! preg_match('/^[A-Z][a-z]+(\s+[a-z]+)+/', $t); // sentence case is description text
    }

    /** @return array<string,string> s, d, ss, sd, ds, dd, sss ... ddd, or [] when it doesn't read as a full tree */
    public static function pedigree(array $region, float $belowY, string $name): array
    {
        $below = array_values(array_filter($region, fn ($s) => $s['y'] > $belowY && self::nameLike($s['t'])));
        if (count($below) < 6) {
            return [];
        }
        $hs = array_map(fn ($s) => $s['h'], $below);
        sort($hs);
        $body = $hs[intdiv(count($hs), 2)];
        $cand = array_values(array_filter($below, fn ($s) => $s['h'] <= $body * 1.3)); // not the next lot's name in large type
        $xs = array_map(fn ($s) => $s['x'], $cand);
        sort($xs);
        $clusters = [];
        foreach ($xs as $x) {
            $k = count($clusters) - 1;
            if ($k >= 0 && $x - end($clusters[$k]) <= 6) {
                $clusters[$k][] = $x;
            } else {
                $clusters[] = [$x];
            }
        }
        $clusters = array_values(array_filter($clusters, fn ($c) => count($c) >= 2));
        if (count($clusters) < 2) {
            return [];
        }
        $l0 = array_sum($clusters[0]) / count($clusters[0]);
        $l1 = null;
        foreach (array_slice($clusters, 1) as $c) {
            if (array_sum($c) / count($c) > $l0 + 15) {
                $l1 = array_sum($c) / count($c);
                break;
            }
        }
        if ($l1 === null) {
            return [];
        }
        $lv = [[], [], []];
        foreach ($cand as $s) {
            $k = abs($s['x'] - $l0) < 6 ? 0 : (abs($s['x'] - $l1) < 6 ? 1 : ($s['x'] > $l1 + 25 ? 2 : null));
            if ($k !== null) {
                $lv[$k][] = $s;
            }
        }
        foreach ($lv as &$l) {
            usort($l, fn ($a, $b) => $a['y'] <=> $b['y']);
        }
        unset($l);
        if (count($lv[2]) >= 2) { // the tree spans the first to the last great-grandparent
            [$top, $bot] = [$lv[2][0]['y'] - 3, end($lv[2])['y'] + 3];
            for ($k = 0; $k < 2; $k++) {
                $lv[$k] = array_values(array_filter($lv[$k], fn ($s) => $s['y'] >= $top && $s['y'] <= $bot));
            }
        }
        if (count($lv[0]) !== 2 || count($lv[1]) !== 4 || count($lv[2]) !== 8) {
            return [];
        }
        $keys = [['s', 'd'], ['ss', 'sd', 'ds', 'dd'], ['sss', 'ssd', 'sds', 'sdd', 'dss', 'dsd', 'dds', 'ddd']];
        $ped = [];
        foreach ($keys as $k => $names) {
            foreach ($names as $i => $pos) {
                $ped[$pos] = self::cleanName($lv[$k][$i]['t']);
            }
        }
        // A misread tree repeats a name inside itself (a horse as its own parent, or as both parents of one horse).
        $key = fn ($n) => preg_replace('/[^A-Z0-9]/', '', $n);
        foreach (['' => ['s', 'd'], 's' => ['ss', 'sd'], 'd' => ['ds', 'dd'], 'ss' => ['sss', 'ssd'], 'sd' => ['sds', 'sdd'], 'ds' => ['dss', 'dsd'], 'dd' => ['dds', 'ddd']] as $child => [$sire, $dam]) {
            $c = $child === '' ? $name : $ped[$child];
            if ($key($ped[$sire]) === $key($ped[$dam]) || $key($ped[$sire]) === $key($c) || $key($ped[$dam]) === $key($c)) {
                return [];
            }
        }

        return $ped;
    }

    /** "FOALED: 4/16/2013  STALLION  CHESTNUT" + "AQHA" (2013-2024 headers). */
    public static function describe(string $foal, ?string $breed): array
    {
        $t = strtoupper(trim(preg_replace('/^FOALED:?\s*/i', '', $foal)));
        $date = preg_match('/^(\S+)\s*/', $t, $d) ? $d[1] : '';
        $rest = trim(substr($t, strlen($d[0] ?? '')));
        [$sex, $color] = self::sexColor($rest);

        return self::date($date) + ['sex' => $sex, 'color' => $color] + self::registry($breed);
    }

    /** "04/28/2013 • MARE BRED • BUCKSKIN • AQHA" or "6/1/2023 - MARE - SORREL & WHITE - GYPSY CROSS" (from Nov 2023). */
    public static function describeLine(string $line): array
    {
        $parts = array_map('trim', preg_split('/\s+[•\-–]\s+|\s*•\s*/u', strtoupper($line)));
        $date = array_shift($parts) ?? '';
        $breed = count($parts) >= 3 ? array_pop($parts) : null;
        [$sex, $color] = self::sexColor(implode(' ', $parts));

        return self::date($date) + ['sex' => $sex, 'color' => $color] + self::registry($breed);
    }

    /** "MARE-OPEN RED ROAN" -> [mare, Red Roan]; "STUD COLT BUCKSKIN" -> [colt, Buckskin]. */
    private static function sexColor(string $t): array
    {
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if (! preg_match('/^('.self::SEX.')\b(?:\s*[\/-]\s*|\s+)?(?:(OPEN|BRED|EXPOSED|IN FOAL|PAIR)\b)?\s*(.*)$/', $t, $m)) {
            return [null, $t !== '' && mb_strlen($t) <= 30 ? ucwords(strtolower($t)) : null];
        }
        $sex = match (true) {
            str_contains($m[1], 'STUD COLT'), $m[1] === 'COLT' => 'colt',
            str_starts_with($m[1], 'STUD'), $m[1] === 'STALLION' => 'stallion',
            str_starts_with($m[1], 'GELDING') => 'gelding',
            str_starts_with($m[1], 'MARE') => 'mare',
            $m[1] === 'FILLY' => 'filly',
            default => null,
        };
        $color = trim($m[3]);

        return [$sex, $color !== '' && mb_strlen($color) <= 30 && ! preg_match('/\d/', $color) ? ucwords(strtolower($color)) : null];
    }

    /** "4/16/2013", "5/2014", "4/18/11", "07/030/2008" (a typo: day unreadable, year kept). */
    private static function date(string $d): array
    {
        $d = preg_replace('/^(\d{1,2})[-.](\d{1,3})[-.](\d{2,4})$/', '$1/$2/$3', trim($d, " ,;"));
        if (! preg_match('/^(\d{1,2})\/(?:(\d{1,3})\/)?(\d{2}|\d{4})$/', $d, $m)) {
            return ['birth_date' => null, 'foal_year' => null];
        }
        $y = (int) $m[3];
        $y = strlen($m[3]) === 2 ? ($y > (int) date('y') + 1 ? 1900 + $y : 2000 + $y) : $y;
        if ($y < 1975 || $y > (int) date('Y') + 1) {
            return ['birth_date' => null, 'foal_year' => null];
        }
        [$mo, $day] = [(int) $m[1], $m[2] !== '' ? (int) $m[2] : null];
        $birth = $day && $mo >= 1 && $mo <= 12 && checkdate($mo, $day, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $day) : null;

        return ['birth_date' => $birth, 'foal_year' => $y];
    }

    private static function registry(?string $breed): array
    {
        $b = strtoupper(trim((string) $breed));
        $b = $b === 'APHA' || $b === 'APHA.' ? 'APHA' : preg_replace('/[^A-Z]/', '', $b);
        if ($b === '' ) {
            return ['registry' => null, 'breed' => null, 'registered' => null];
        }
        if ($b === 'GRADE') {
            return ['registry' => null, 'breed' => null, 'registered' => false];
        }
        [$registry, $name] = self::REGISTRIES[$b] ?? [null, null];

        return ['registry' => $registry, 'breed' => $name, 'registered' => $registry ? true : null];
    }

    /** Names in capitals, tidy spaces and apostrophes; "(PENDING)" and "APPL FOR" mean no registered name yet. */
    public static function cleanName(?string $n): string
    {
        $n = strtoupper(trim(preg_replace('/\s+/', ' ', str_replace('’', "'", (string) $n))));
        // A barn name beside the registered one: "TWO SWEET EYED (SWEETIE)", "LSF JIGSAW PUZZLE “JIGS”", "... AKA ROMEO".
        $n = trim(preg_replace(['/^\s*(\([^)]*\)|[“"][^”"]+[”"])\s*/u', '/\s*\([^)]*\)\s*$/', '/\s*[“"‘\'][^”"’\']+[”"’\']\s*$/u', '/\s+(AKA|A\/K\/A)\s+.*$/'], '', $n));
        if (preg_match('/^(APPL(IED)? FOR|PENDING|NONE|UNKNOWN|N\/A)$|^(OWNER|PENDING|AKA)\b|\bAKA$/', $n) || preg_match('/^FOALED\b|\d{1,2}\/\d{1,2}\/\d{2}|[,!?]|\.\S/', $n) || mb_strlen($n) > 25) {
            return ''; // the foaled line or a sentence where the name should be
        }

        return $n;
    }
}
