<?php

namespace App\Harvest;

/**
 * Legacy Reining Breeders Sale catalog pages (2018 on: the full catalog PDF, or one PDF per hip in the WordPress uploads).
 * Each hip's page reads:
 *
 *   Hip No.        Consigned by ...                      Hip No.
 *      1            Big Star N Diamonds                     1
 *                   April 4, 2023 Palomino Filly          (or "2011 Sorrel Mare" for older horses)
 *   [tree, drawn with "{" brackets: sire and dam in one column, grandparents in the next, great-grandparents right]
 *   Big Star N Diamonds
 *   6310337                                               (the AQHA number, under the name at the left)
 *   PERFORMANCE RECORD / NOTES / PRODUCE RECORD ...       (not read)
 *
 * Great-grandparent names sometimes wrap onto two lines ("{ Colonels" / "Smoking Gun"); when the outer column doesn't
 * read as exactly 8 names, only sire, dam and the four grandparents are kept. The consignor is never kept.
 */
class LegacyCatalogParser
{
    private const SEX = 'Colt|Filly|Mare|Gelding|Stallion|Horse';

    /** @return list<array{hip:string, name:string, birth_date:?string, foal_year:?int, color:?string, sex:?string, registration_number:?string, pedigree:array<string,string>}> */
    public static function catalog(string $pdf): array
    {
        $out = [];
        foreach (LolliParser::words($pdf) as $words) {
            if (! $words) {
                continue;
            }
            $page = LolliParser::segments($words);
            // One hip per page (Legacy) or several down the page (Shawnee 2020): each left-hand "Hip No." label starts a band.
            $heads = array_values(array_filter($page, fn ($s) => preg_match('/^Hip No\./', $s['t'])));
            usort($heads, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
            $heads = array_values(array_filter($heads, fn ($h) => ! array_filter($heads, fn ($o) => $o !== $h && abs($o['y'] - $h['y']) < 5 && $o['x'] < $h['x'])));
            foreach ($heads as $i => $head) {
                $end = isset($heads[$i + 1]) ? $heads[$i + 1]['y'] - 2 : INF;
                $segs = array_values(array_filter($page, fn ($s) => $s['y'] >= $head['y'] - 2 && $s['y'] < $end));
                if ($hip = self::hip($segs, $head)) {
                    $out[] = $hip;
                }
            }
        }

        return $out;
    }

    /** One hip's band of the page, from its "Hip No." label down to the next. */
    private static function hip(array $segs, array $head): ?array
    {
        $hip = $name = $desc = null;
        foreach ($segs as $s) {
            $dy = $s['y'] - $head['y'];
            if ($hip === null && $dy > 3 && $dy < 25 && preg_match('/^\d{1,3}$/', $s['t']) && $s['x'] < $head['x'] + 40) {
                $hip = $s['t'];
            }
        }
        foreach ($segs as $s) {
            $dy = $s['y'] - $head['y'];
            if ($name === null && $dy > 3 && $dy < 25 && $s['x'] > $head['x'] + 40 && ! preg_match('/^\d{1,3}$|Consigned|Hip No/i', $s['t'])) {
                $name = $s;
            } elseif ($name && $desc === null && $s['y'] > $name['y'] + 3 && $s['y'] < $name['y'] + 25 && preg_match('/\b('.self::SEX.')\b/i', $s['t'])) {
                $desc = $s;
            }
        }
        if ($hip === null || ! $name) {
            return null;
        }
        $clean = LolliParser::cleanName($name['t']);
        if ($clean === '' || preg_match('/^PENDING$/i', trim($name['t']))) {
            return null; // "Pending": not named yet
        }
        $stop = INF;
        $reg = null;
        foreach ($segs as $s) {
            if ($s['y'] > ($desc['y'] ?? $name['y']) && preg_match('/^(PERFORMANCE RECORD|NOTES|PRODUCE RECORD|By [A-Z]|1st dam|Sire:?$|Sire:)/i', $s['t'])) {
                $stop = min($stop, $s['y']);
            }
            if ($reg === null && preg_match('/^(\d{7})$/', $s['t'], $m) && $s['x'] < $head['x'] + 30 && $s['y'] > $name['y'] + 20) {
                $reg = $m[1];
            }
        }
        $below = ($desc['y'] ?? $name['y']) + 3;
        $tree = [];
        foreach ($segs as $s) {
            if ($s['y'] > $below && $s['y'] < $stop) {
                $t = trim(str_replace(['{', '}'], '', $s['t']));
                if ($t !== '' && LolliParser::cleanName($t) !== $clean) {
                    $s['x'] += strlen($s['t']) - strlen(ltrim($s['t'], '{ ')) > 0 ? 6 : 0; // a "{" before the name shifts nothing that matters
                    $s['t'] = $t;
                    $tree[] = $s;
                }
            }
        }

        return ['hip' => $hip, 'name' => $clean, 'registration_number' => $reg, 'pedigree' => self::pedigree($tree, $below, $clean)] + self::describe($desc['t'] ?? '');
    }

    /** The full tree when it reads as 2 + 4 + 8; otherwise the 6 names of the first two generations, or nothing. */
    private static function pedigree(array $tree, float $below, string $name): array
    {
        if ($full = LolliParser::pedigree($tree, $below, $name)) {
            return $full;
        }
        // First two columns only: the two leftmost x positions holding 2 and 4 names.
        $names = array_values(array_filter($tree, fn ($s) => LolliParser::nameLike($s['t'])));
        usort($names, fn ($a, $b) => $a['x'] <=> $b['x']);
        $cols = [];
        foreach ($names as $s) {
            $k = null;
            foreach ($cols as $i => $c) {
                if (abs($c['x'] - $s['x']) < 8) {
                    $k = $i;
                }
            }
            $k === null ? $cols[] = ['x' => $s['x'], 'n' => [$s]] : $cols[$k]['n'][] = $s;
        }
        $cols = array_values(array_filter($cols, fn ($c) => count($c['n']) >= 2));
        if (count($cols) < 2 || count($cols[0]['n']) !== 2 || count($cols[1]['n']) !== 4) {
            return [];
        }
        foreach ($cols as &$c) {
            usort($c['n'], fn ($a, $b) => $a['y'] <=> $b['y']);
        }
        unset($c);
        $ped = ['s' => $cols[0]['n'][0]['t'], 'd' => $cols[0]['n'][1]['t'], 'ss' => $cols[1]['n'][0]['t'], 'sd' => $cols[1]['n'][1]['t'], 'ds' => $cols[1]['n'][2]['t'], 'dd' => $cols[1]['n'][3]['t']];
        $ped = array_map(fn ($n) => LolliParser::cleanName($n), $ped);
        // The sire's parents sit above the sire's line and the dam's below the dam's: otherwise the columns are mixed up.
        if ($cols[1]['n'][1]['y'] > $cols[0]['n'][1]['y'] || $cols[1]['n'][2]['y'] < $cols[0]['n'][0]['y'] || in_array('', $ped, true)) {
            return [];
        }

        return $ped;
    }

    /**
     * The 2005 catalog: "Lot 1 • HES JUSTA BENZ" / "2004 Bay Stallion" / "Smart Starbuck x Justa Spring Freckle",
     * two or three lots across a page. Sire and dam only; no registration numbers.
     */
    public static function catalog2005(string $pdf): array
    {
        $out = [];
        foreach (LolliParser::words($pdf) as $words) {
            $segs = LolliParser::segments($words);
            foreach ($segs as $s) {
                if (! preg_match('/^Lot\s+(\d{1,3})\s*•\s*(.+)$/u', $s['t'], $m)) {
                    continue;
                }
                $desc = $parents = null;
                foreach ($segs as $o) {
                    $dy = $o['y'] - $s['y'];
                    if ($dy > 2 && $dy < 40 && abs($o['x'] - $s['x']) < 60) {
                        if (! $desc && preg_match('/^(19|20)\d\d\s+\S/', $o['t'])) {
                            $desc = $o['t'];
                        } elseif (! $parents && preg_match('/^(.+?)\s+x\s+(.+)$/', $o['t'], $p)) {
                            $parents = [LolliParser::cleanName($p[1]), LolliParser::cleanName($p[2])];
                        }
                    }
                }
                $name = LolliParser::cleanName($m[2]);
                if ($name === '' || ! $desc) {
                    continue;
                }
                $out[] = ['hip' => $m[1], 'name' => $name, 'registration_number' => null,
                    'pedigree' => $parents && $parents[0] && $parents[1] ? ['s' => $parents[0], 'd' => $parents[1]] : []] + self::describe($desc);
            }
        }

        return $out;
    }

    /** "April 4, 2023 Palomino Filly" or "2011 Sorrel Mare". */
    public static function describe(string $d): array
    {
        $d = trim($d);
        $birth = null;
        if (preg_match('/^([A-Z][a-z]+\s+\d{1,2},\s*((?:19|20)\d\d))\s+(.*)$/', $d, $m)) {
            [$birth, $year, $rest] = [date('Y-m-d', strtotime($m[1])), (int) $m[2], $m[3]];
        } elseif (preg_match('/^((?:19|20)\d\d)\s+(.*)$/', $d, $m)) {
            [$year, $rest] = [(int) $m[1], $m[2]];
        } else {
            return ['birth_date' => null, 'foal_year' => null, 'color' => null, 'sex' => null];
        }
        $sex = preg_match('/\b('.self::SEX.')\b/i', $rest, $s) ? strtolower($s[1]) : null;
        $color = trim(preg_replace('/\b('.self::SEX.')\b/i', '', $rest)) ?: null;

        return ['birth_date' => $birth, 'foal_year' => $year, 'color' => $color ? ucwords(strtolower($color)) : null, 'sex' => $sex === 'horse' ? 'stallion' : $sex];
    }
}
