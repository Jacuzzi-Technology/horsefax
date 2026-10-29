<?php

namespace App\Harvest;

/**
 * Sale catalogs from Richard's PDFNotes library (Dropbox › Horsefax › Sale Catalogs › DoNotEdit) other than Lolli and
 * Billings, which have their own parsers. Every sale prints its own layout, but most give a lot (or hip) number with the
 * horse's name, a line with registry, number, sex, color and foaling date, and a positional pedigree tree. Each layout
 * here finds the lot anchors on a page; what's around an anchor (its block) is read the same way for all of them.
 *
 * Facts only: consignors, owners, phone numbers and the description paragraphs are never kept.
 */
class PrintedCatalogParser
{
    public const REG = 'AQHA|APHA|ApHC|APHC|AHA|AHR|PtHA|PTHA|PHBA|ABRA|IBHA|TWHBEA|MFTHBA|RMHA|KMSHA|NSHR|POA|ASPC|AMHA|AMHR|GVHS|JC|BHA|PHA|ABBA|ASHA|USTA|NRHA|NFTHBA|SSHBEA|AQHA\s*\/\s*APHA';

    /** Breed words a catalog prints in the description line (only used when the registry gives no breed). */
    private const BREED_WORDS = ['MULE|MOLLY|JOHN MULE' => 'Mule', 'DONKEY|JENNY|JACK' => 'Donkey', 'WELCH PONY|WELSH PONY|WELSH' => 'Welsh Pony',
        'SHETLAND(?: PONY)?' => 'Shetland Pony', 'MINI(?:ATURE)?(?: HORSE)?' => 'Miniature', 'PONY' => 'Pony', 'WARMBLOOD' => 'Warmblood', 'QH|QUARTER HORSE' => 'Quarter Horse',
        'HAFLINGER|HALFLINGER' => 'Haflinger', 'PERCHERON' => 'Percheron', 'BELGIAN' => 'Belgian', 'FRIESIAN' => 'Friesian', 'GYPSY(?: VANNER)?' => 'Gypsy Vanner',
        'THOROUGHBRED|TB' => 'Thoroughbred', 'ARABIAN' => 'Arabian', 'MORGAN' => 'Morgan', 'DRAFT(?: CROSS)?' => 'Draft', 'APPALOOSA' => 'Appaloosa'];

    private const SEX = 'STUD COLT|STALLION|STUD|GELDING|MARE|FILLY|COLT|HORSE';

    private const COLORS = 'DAPPLE GR[AE]Y|FLEA BITTEN GR[AE]Y|RED ROAN|BLUE ROAN|BAY ROAN|STRAWBERRY ROAN|RED DUN|BLUE DUN|LINEBACK DUN|SMOKY BLACK|SEAL BROWN|LIVER CHESTNUT|FLAXEN SORREL|'
        .'BLACK AND WHITE|BLACK & WHITE|BAY AND WHITE|SORREL AND WHITE|BROWN AND WHITE|SORREL|CHESTNUT|BAY|BLACK|BROWN|GR[AE]Y|ROAN|PALOMINO|BUCKSKIN|DUN|GRULLA|GRULLO|CREMELLO|PERLINO|'
        .'WHITE|BLONDE|CHAMPAGNE|TOBIANO|OVERO|PINTO|SPOTTED|DAPPLE';

    /**
     * Layouts: how a lot's anchor reads, and where its description is.
     *  - line: "LOT NO. 6  STORM TREASURE" then "TWHBEA 20804163 MARE PALOMINO 2008" on the next line (Southwest);
     *  - inline: "LOT 5 Rock Lane Twelve 4 yr old Registered Black Percheron Stallion. Stands 17.1 HH" (Boone County);
     *  - line with the name left of the hip: "LIL SUGAR CANDY     Hip # 26" / "AQHA 4879822-GRAY MARE MAY 5, 2006" (CLA, Clovis);
     *  - consignor: the name, "Consignor: ..." under it, then "2010 Gray AQHA GELDING // Reg #5279557"; the lot number
     *    printed large beside the tree (Clovis Horse Sales, 2021);
     *  - regline: the name, "AQHA 5549037" under it, "Foaled May 07, 2013- Gelding", "Color: Gray", then two generations
     *    with each ancestor's number; the lot number printed large to the right (Twin Cities, 2019);
     *  - fields: "Lot 25 - Coco Blackburn Champ, “Champ”   Quarter Horse" / "Reg. #6216001  DOB: 06/10/2022  Gelding" /
     *    "Consigned by: ...  Buckskin" (Seymour Select).
     */
    public const LAYOUTS = [
        'southwest' => ['anchor' => '/^LOT\s*NO\.?\s*(\d{1,4}[A-Z]?)\b\s*(.*)$/i', 'header' => 'line'],
        'boone' => ['anchor' => '/^LOT\s*#?\s*(\d{1,4}[A-Z]?)\b\s+(.+)$/', 'header' => 'inline'],
        'cla' => ['anchor' => '/^Hip\s*#\s*(\d{1,4}[A-Z]?)\s*()$/i', 'header' => 'line', 'name' => 'left'],
        'consignor' => ['anchor' => '/^Consignor:/i', 'header' => 'consignor'],
        'regline' => ['anchor' => '/^(AQHA|APHA|ApHC|APHC|PtHA|PTHA)\s+(\d{5,8})$/', 'header' => 'regline'],
        'seymour' => ['anchor' => '/^Lot\s+(\d{1,4}[A-Z]?)\s*[-–]\s*(.+)$/u', 'header' => 'fields'],
    ];

    /** @return list<array> lots: lot, name, registry, registration_number, registered, breed, sex, color, birth_date, foal_year, pedigree */
    public static function read(string $pdf, string $layout, ?int $saleYear = null): array
    {
        $out = [];
        foreach (LolliParser::words($pdf) as $words) {
            if (! $words) {
                continue;
            }
            $segs = LolliParser::segments($words);
            $anchors = self::anchors($segs, $layout);
            foreach ($anchors as $a) {
                $block = self::block($a, $anchors, $segs);
                if ($lot = self::lot($a, $block, $layout, $saleYear)) {
                    $out[] = $lot;
                }
            }
        }
        $best = [];
        foreach ($out as $l) {
            $score = fn ($x) => count($x['pedigree']) + ($x['registration_number'] ? 2 : 0) + ($x['foal_year'] ? 1 : 0);
            if (! isset($best[$l['lot']]) || $score($l) > $score($best[$l['lot']])) {
                $best[$l['lot']] = $l;
            }
        }

        return array_values($best);
    }

    /**
     * Farmers & Ranchers (Salina, KS) catalogs set their trees in justified type, so names come apart into words spread
     * across the column; only the header and the sire and dam (in capitals at the left margin) are read, from the text:
     *   LOT # 5 - MARE / MCC TRAVALIN RIOSTAR - AQHA / Foaled: 2010. Color: Bay. Consignor: ... / BOBS HICKORY RIO ... TRAVALIN BY CHOICE
     */
    public static function farmers(string $text): array
    {
        $out = [];
        $blocks = preg_split('/^\s*(?=LOT\s*#\s*\d)/m', $text);
        foreach ($blocks as $b) {
            if (! preg_match('/^LOT\s*#\s*(\d{1,4})\s*-\s*([A-Z ]+?)\s*$/m', $b, $h) || ! preg_match('/\n\s*(.+?)\s+-\s+([A-Za-z]{2,6})\s*\n/', $b, $n)) {
                continue;
            }
            $name = self::clean($n[1]);
            if ($name === '') {
                continue;
            }
            $reg = LolliParser::registry($n[2]);
            if (strtoupper($n[2]) === 'GRADE') {
                $reg['registered'] = false;
            } elseif (! $reg['registry'] && preg_match('/^('.self::REG.')$/i', $n[2])) {
                $reg = ['registry' => strtoupper($n[2]), 'breed' => null, 'registered' => true];
            }
            $year = preg_match('/Foaled:\s*((?:19|20)\d\d)/i', $b, $y) ? (int) $y[1] : null;
            $color = preg_match('/Color:\s*([A-Za-z \/&]+?)\s*\./i', $b, $c) ? ucwords(strtolower(trim($c[1]))) : null;
            $sex = self::free($h[2], null)['sex'];
            // Sire and dam: lines starting at the margin in capitals, between the Foaled line and the description.
            $parents = [];
            $after = preg_split('/Foaled:.*\n/i', $b, 2)[1] ?? '';
            foreach (explode("\n", $after) as $line) {
                if (preg_match('/^[A-Z][A-Z0-9\'’.& ]{2,}(?=\t|\s{2,}|$)/u', $line, $m) && ! preg_match('/[a-z]/', $m[0])) {
                    $parents[] = self::clean(preg_replace('/\s+/', ' ', $m[0]));
                } elseif (preg_match('/^[A-Z][a-z]+\s+[a-z]/', $line)) {
                    break; // the description
                }
            }
            $parents = array_values(array_filter($parents));
            $out[] = ['lot' => $h[1], 'name' => $name, 'registration_number' => null, 'sex' => $sex, 'color' => $color, 'birth_date' => null, 'foal_year' => $year,
                'pedigree' => count($parents) === 2 && $parents[0] !== $parents[1] && $parents[0] !== $name ? ['s' => $parents[0], 'd' => $parents[1]] : []] + $reg;
        }

        return $out;
    }

    /** Lot anchors: [lot, name segment or null, the anchor segment]. */
    private static function anchors(array $segs, string $layout): array
    {
        $layouts = self::LAYOUTS[$layout] ?? throw new \InvalidArgumentException("Unknown catalog layout $layout");
        $re = $layouts['anchor'];
        $out = [];
        foreach ($segs as $s) {
            if (! preg_match($re, $s['t'], $m)) {
                continue;
            }
            if ($layouts['header'] === 'regline') { // the name is the larger run just above; the lot number large at the right
                $above = array_filter($segs, fn ($o) => $o['y'] < $s['y'] && $s['y'] - $o['y'] < 20 && $o['h'] > $s['h'] && $o['x1'] > $s['x'] - 20 && $o['x'] < $s['x1'] + 20 && ! preg_match('/^\d+$/', $o['t']));
                usort($above, fn ($a, $b) => $b['y'] <=> $a['y']);
                $big = array_filter($segs, fn ($o) => preg_match('/^\d{1,4}$/', $o['t']) && $o['h'] > $s['h'] * 1.3 && abs($o['y'] - $s['y']) < 20 && $o['x'] > $s['x1']);
                usort($big, fn ($a, $b) => $a['x'] <=> $b['x']);
                if ($above && $big) {
                    $out[] = ['lot' => array_values($big)[0]['t'], 'name' => $above[0]['t'], 'seg' => ['x' => $above[0]['x'] - 90] + $above[0], 'regseg' => $s, 'reg' => [$m[1], $m[2]]];
                }

                continue;
            }
            if ($layouts['header'] === 'consignor') { // the name is the larger run just above
                $above = array_filter($segs, fn ($o) => $o['y'] < $s['y'] && $s['y'] - $o['y'] < 25 && $o['h'] > $s['h'] && $o['x1'] > $s['x'] && $o['x'] < $s['x1']);
                usort($above, fn ($a, $b) => $b['y'] <=> $a['y']);
                if ($above) {
                    // The block reaches left past the consignor line and to the lot number printed beside the tree.
                    $out[] = ['lot' => null, 'name' => $above[0]['t'], 'seg' => ['x' => min($above[0]['x'], $s['x']) - 60] + $above[0], 'consignor' => $s];
                }

                continue;
            }
            $name = trim($m[2] ?? '');
            if (($layouts['name'] ?? null) === 'left') { // "LIL SUGAR CANDY      Hip # 26": the name is the run to its left
                $left = array_filter($segs, fn ($o) => $o !== $s && abs($o['yc'] - $s['yc']) < 4 && $o['x1'] < $s['x'] && $s['x'] - $o['x1'] < 300);
                usort($left, fn ($a, $b) => $b['x1'] <=> $a['x1']);
                if ($left) {
                    $out[] = ['lot' => $m[1], 'name' => $left[0]['t'], 'seg' => $left[0]];
                }

                continue;
            }
            if ($name === '') { // the name printed apart on the same line
                $same = array_filter($segs, fn ($o) => $o !== $s && abs($o['yc'] - $s['yc']) < 3 && $o['x'] > $s['x1'] && $o['x'] - $s['x1'] < 160);
                usort($same, fn ($a, $b) => $a['x'] <=> $b['x']);
                $name = $same ? $same[0]['t'] : '';
            }
            $out[] = ['lot' => $m[1], 'name' => $name, 'seg' => $s];
        }

        return $out;
    }

    /** What belongs to an anchor: below it, in its page column, down to the next anchor in that column. */
    private static function block(array $a, array $anchors, array $segs): array
    {
        $s = $a['seg'];
        $right = INF;
        $bottom = INF;
        foreach ($anchors as $o) {
            $os = $o['seg'];
            if ($os === $s) {
                continue;
            }
            if ($os['x'] > $s['x'] + 60 && $os['x'] < $right) {
                $right = $os['x'];
            }
            if (abs($os['x'] - $s['x']) <= 60 && $os['y'] > $s['y'] + 5 && $os['y'] < $bottom) {
                $bottom = $os['y'];
            }
        }
        if ($right === INF) { // the only column on the page, or the right one: up to the page edge
            $right = PHP_FLOAT_MAX;
        }

        return array_values(array_filter($segs, fn ($o) => $o['y'] > $s['y'] + 1 && $o['y'] < $bottom - 1 && $o['x'] >= $s['x'] - 25 && $o['x'] < $right - 5));
    }

    private static function lot(array $a, array $block, string $layout, ?int $saleYear): ?array
    {
        $header = self::LAYOUTS[$layout]['header'];
        $inline = $header !== 'line';
        usort($block, fn ($x, $y) => [$x['y'], $x['x']] <=> [$y['y'], $y['x']]);
        $desc = '';
        $reg = null;
        $treeFrom = null;
        if ($header === 'regline') {
            $lines = array_values(array_filter($block, fn ($o) => $o['y'] > $a['regseg']['y'] + 2 && $o['y'] - $a['regseg']['y'] < 40 && ! preg_match('/^Owner/i', $o['t'])));
            $desc = $a['reg'][0].' '.implode(' ', array_map(fn ($o) => preg_replace('/^Color:\s*/i', '', $o['t']), $lines));
            $reg = $a['reg'][1];
            $owner = array_values(array_filter($block, fn ($o) => preg_match('/^Owner/i', $o['t'])));
            $treeFrom = $owner ? $owner[0]['y'] + $owner[0]['h'] : $a['regseg']['y'] + 40;
            // Each ancestor's number beside its name ("Haidas Viper 3229529") or on the line under it ("3989247").
            $block = array_map(fn ($o) => ['t' => trim(preg_replace('/\s+\d{6,8}$/', '', $o['t']))] + $o, $block);
        } elseif ($header === 'consignor') {
            $big = array_filter($block, fn ($s) => preg_match('/^\d{1,4}$/', $s['t']) && $s['h'] > $a['seg']['h'] * 1.5);
            if (! $big) {
                return null;
            }
            usort($big, fn ($p, $q) => $p['x'] <=> $q['x']);
            $a['lot'] = $big[0]['t'];
            $id = array_values(array_filter($block, fn ($s) => $s['y'] > $a['consignor']['y'] + 2 && $s['y'] - $a['consignor']['y'] < 20));
            $desc = $id ? $id[0]['t'] : '';
            $reg = preg_match('/Reg\.?\s*#\s*([0-9,]{5,9})\b/i', $desc, $r) ? str_replace(',', '', $r[1]) : null;
            $treeFrom = $id ? $id[0]['y'] + $id[0]['h'] : $a['consignor']['y'] + $a['consignor']['h'];
        } elseif ($header === 'fields') {
            $a['name'] = preg_split('/,|\s+[“"]/u', $a['name'])[0];
            $lines = array_filter($block, fn ($s) => $a['seg']['h'] * 3.6 > $s['y'] - $a['seg']['y']);
            $same = array_filter($block, fn ($s) => abs($s['yc'] - $a['seg']['yc']) < 3);
            $text = implode(' ', array_map(fn ($s) => $s['t'], array_merge($same, $lines)));
            $reg = preg_match('/Reg\.?\s*#\s*([0-9,]{5,9})\b/i', $text, $r) ? str_replace(',', '', $r[1]) : null;
            $text = preg_replace(['/Consigned by:.*?(•\s*[\d\-. ]+|$)/i', '/Reg\.?\s*#\s*[0-9,]*/i', '/DOB:\s*/i', '/\./'], ' ', $text);
            $desc = ($reg ? 'Registered ' : '').$text;
            $consigned = array_filter($block, fn ($s) => preg_match('/^Consigned/i', $s['t']));
            $treeFrom = $consigned ? max(array_map(fn ($s) => $s['y'] + $s['h'] / 2, $consigned)) : null; // the tree's top name sits beside the color
        } elseif ($inline) { // the name runs into the description: "Rock Lane Twelve 4 yr old Registered ..."
            [$a['name'], $desc] = self::splitName($a['name']);
            if (count($block) && $block[0]['y'] - $a['seg']['y'] < $a['seg']['h'] * 2.5) {
                $desc .= ' '.$block[0]['t']; // a description wrapped onto the next line ("Grade Dapple / Gray Percheron Gelding")
            }
        }
        $name = self::clean($a['name']);
        $out = ['lot' => $a['lot'], 'name' => $name ?: null, 'registry' => null, 'registration_number' => null, 'registered' => null, 'breed' => null,
            'sex' => null, 'color' => null, 'birth_date' => null, 'foal_year' => null, 'pedigree' => []];
        $below = $treeFrom ?? $a['seg']['y'] + $a['seg']['h'];
        // The identity line just under the name: "AQHA 5243447 GELDING SORREL 2009", "GRADE GELDING DUN ROAN 7 YR OLD".
        foreach (array_slice($block, 0, 4) as $s) {
            if ($s['y'] - $a['seg']['y'] > 30) {
                break;
            }
            if (preg_match('/^(?:('.self::REG.')\s*#?\s*([\d,]{4,9}|[A-Z]?\d[\dA-Z]{3,8})?|(GRADE))(?:\s*-\s*|\s+)(.*)$/i', $s['t'], $m)) {
                $out = array_merge($out, self::identity($m[1] ?: null, $m[2] ?? null, ! empty($m[3]), $m[4]));
                $below = $s['y'] + $s['h'];
                break;
            }
        }
        if ($inline) {
            $out = array_merge($out, self::free($desc, $saleYear));
            if ($reg) {
                $out['registration_number'] = $reg;
                $out['registered'] = true;
            }
        }
        if ($name !== '' && $out['registered'] !== false) {
            $tree = array_values(array_filter($block, fn ($s) => $s['y'] > $below + 1 && ! preg_match('/^CONSIGN/i', $s['t'])));
            $out['pedigree'] = self::tree($tree, $below, $name);
        }
        if ($name === '' || preg_match('/^(NO SALE|SCRATCH|WITHDRAWN|OUT)\b|^(WEANLING|YEARLING|FILLY|COLT|STUD COLT|MARE|GELDING|STALLION|PONY|MULE|UNNAMED|NO NAME|TBA|TBD|PASS)$/i', $name)) {
            return null;
        }

        return $out;
    }

    /** A pedigree name; draft and warmblood names run longer than the 24 characters LolliParser allows. */
    private static function nameOk(string $t): bool
    {
        $t = trim($t);
        if (mb_strlen($t) <= 24) {
            return LolliParser::nameLike($t);
        }

        return mb_strlen($t) <= 36 && LolliParser::nameLike(preg_replace('/\s+\S*$/', '', mb_substr($t, 0, 24)))
            && preg_match("/^[A-Z0-9][A-Za-z0-9 '’&.\\-\\/#()]*$/u", $t) && count(preg_split('/\s+/', $t)) <= 6
            && ! preg_match('/[a-z]{3,}\.$|\b[A-Za-z]{3,}\.\s|\s(a|an|the|and|is|of|to|in|with|for|has|was|will)\s/', $t); // a sentence
    }

    private static function clean(string $n): string
    {
        // Initials ("M.G.'s Prince", "H.J.M. Captain's Tribune") read as a sentence break to LolliParser: hide their dots.
        $n = preg_replace('/(?<![A-Za-z])([A-Z])\.(?=[A-Z]|[\'’]|\s)/u', '$1·', $n);
        $c = str_replace('·', '.', LolliParser::cleanName($n));
        if ($c === '' && mb_strlen(trim($n)) > 25 && mb_strlen(trim($n)) <= 36 && self::nameOk($n)) {
            $c = LolliParser::cleanName(mb_substr($n, 0, 25)) !== '' ? str_replace('·', '.', strtoupper(trim(preg_replace('/\s+/', ' ', str_replace('’', "'", $n))))) : '';
        }

        return $c;
    }

    /** Where the name ends: at the age, foaling date, "Registered" or "Grade", or the first sentence break. */
    public static function splitName(string $t): array
    {
        $stop = '/(?:^|\s+)(?=(?:\d+|ONE|TWO|THREE|FOUR|FIVE|SIX|SEVEN|EIGHT|NINE|TEN|ELEVEN|TWELVE)\s*(?:-|\s)?\s*(?:YR|YEAR)S?\b|FOALED\b|BORN\b|DOB\b|YEARLING\b|WEANLING\b|COMING\b|REGISTERED\b|REG\.|GRADE\b|(?:'.self::REG.')\b|\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})/i';
        $parts = preg_split($stop, $t, 2);
        if (count($parts) < 2) {
            $parts = preg_split('/[.,:;]\s+|\s+-\s+/', $t, 2) + [1 => ''];
        }

        return [trim($parts[0], ' .,-'), $parts[1]];
    }

    /** A description in words: "4 yr old Registered Black Percheron Stallion.", "Foaled 4/21/23 Registered Sorrel Belgian Stud Colt." */
    public static function free(string $t, ?int $saleYear): array
    {
        $first = strtoupper(preg_split('/(?<![A-Z]\.[A-Z])(?<!\b[A-Z])\.\s|\n/', $t, 2)[0]);
        $out = ['registry' => null, 'registration_number' => null, 'registered' => null, 'breed' => null, 'sex' => null, 'color' => null, 'birth_date' => null, 'foal_year' => null];
        if (preg_match('/\b('.self::REG.')\b\s*#?\s*([\d,]{4,9})?/i', $first, $r)) {
            $out = array_merge($out, LolliParser::registry($r[1]) ?: []);
            $out['registry'] ??= strtoupper($r[1]);
            $out['registered'] = true;
            $out['registration_number'] = isset($r[2]) && $r[2] !== '' ? str_replace(',', '', $r[2]) : null;
        } elseif (preg_match('/\bREGISTERED\b|\bREG\.\s/', $first) && ! preg_match('/\bPENDING\b|\bELIGIBLE\b|\bNOT REGISTERED\b/', $first)) {
            $out['registered'] = true;
        } elseif (preg_match('/\bGRADE\b/', $first)) {
            $out['registered'] = false;
        }
        if (preg_match('/\b(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\.?\s+(\d{1,2}),?\s+((?:19|20)\d\d)\b/', $first, $d)) {
            $mo = (int) date('n', strtotime($d[1].' 1 2000'));
            $out['foal_year'] = (int) $d[3];
            $out['birth_date'] = checkdate($mo, (int) $d[2], (int) $d[3]) ? sprintf('%04d-%02d-%02d', $d[3], $mo, $d[2]) : null;
        } elseif (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})\b/', $first, $d)) {
            $y = (int) $d[3] + (strlen($d[3]) === 2 ? ((int) $d[3] > (int) date('y') + 1 ? 1900 : 2000) : 0);
            $out['foal_year'] = $y;
            $out['birth_date'] = checkdate((int) $d[1], (int) $d[2], $y) ? sprintf('%04d-%02d-%02d', $y, $d[1], $d[2]) : null;
        } elseif (preg_match('/\b(?:FOALED|BORN)\s+(?:IN\s+)?((?:19|20)\d\d)\b|\b((?:19|20)\d\d)\s+MODEL\b|^\s*((?:19|20)\d\d)\s+[A-Z]/', $first, $d)) {
            $out['foal_year'] = (int) ($d[1] ?: ($d[2] ?: $d[3]));
        } elseif ($saleYear) {
            $words = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5, 'SIX' => 6, 'SEVEN' => 7, 'EIGHT' => 8, 'NINE' => 9, 'TEN' => 10, 'ELEVEN' => 11, 'TWELVE' => 12];
            if (preg_match('/\b(\d{1,2}|'.implode('|', array_keys($words)).')\s*(?:-|\s)?\s*(?:YR|YEAR)S?\b/', $first, $d)) {
                $out['foal_year'] = $saleYear - (int) ($words[$d[1]] ?? $d[1]); // horses age on January 1
            } elseif (preg_match('/\bYEARLING\b/', $first)) {
                $out['foal_year'] = $saleYear - 1;
            } elseif (preg_match('/\bWEANLING\b|\bSPRING FOAL\b/', $first)) {
                $out['foal_year'] = $saleYear;
            }
        }
        if ($out['foal_year'] !== null && ($out['foal_year'] < 1975 || $out['foal_year'] > (int) date('Y') + 1)) {
            [$out['foal_year'], $out['birth_date']] = [null, null];
        }
        if (preg_match('/\b('.str_replace('|HORSE', '', self::SEX).')\b/', $first, $x)) { // "Quarter Horse" is no stallion
            $out['sex'] = match ($x[1]) {
                'STUD COLT', 'COLT' => 'colt', 'STUD', 'STALLION', 'HORSE' => 'stallion', default => strtolower($x[1])
            };
        }
        if (preg_match('/\b('.self::COLORS.')\b/', $first, $c)) {
            $out['color'] = ucwords(strtolower(str_replace('GREY', 'GRAY', $c[1])));
        }
        if (! $out['breed']) {
            $found = [];
            foreach (self::BREED_WORDS as $re => $b) {
                if (preg_match('/\b('.$re.')\b/', $first)) {
                    $found[$b] = true;
                }
            }
            $out['breed'] = count($found) === 1 ? array_key_first($found) : null; // "Percheron/Quarter Horse" is a cross: no one breed
        }

        return $out;
    }

    /** "GELDING SORREL 2009", "MARE BAY 03/08/2008", "STALLION CHESTNUT 04/02/13", "GELDING DUN ROAN 7 YR OLD". */
    public static function identity(?string $registry, ?string $number, bool $grade, string $rest): array
    {
        $reg = $registry ? LolliParser::registry(preg_replace('/\s*\/.*$/', '', $registry)) : ['registry' => null, 'breed' => null, 'registered' => $grade ? false : null];
        if ($registry && ! $reg['registry']) {
            $reg = ['registry' => strtoupper($registry) === 'APHC' ? 'ApHC' : $registry, 'breed' => null, 'registered' => true];
        }
        $rest = strtoupper(trim($rest));
        $birth = $year = null;
        if (preg_match('/\b(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\.?\s+(\d{1,2}),?\s+((?:19|20)\d\d)\b/', $rest, $d)) {
            $year = (int) $d[3];
            $mo = (int) date('n', strtotime($d[1].' 1 2000'));
            $birth = checkdate($mo, (int) $d[2], $year) ? sprintf('%04d-%02d-%02d', $year, $mo, $d[2]) : null;
            $rest = str_replace($d[0], ' ', $rest);
        } elseif (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})\b/', $rest, $d)) {
            $year = (int) $d[3] + (strlen($d[3]) === 2 ? ((int) $d[3] > (int) date('y') + 1 ? 1900 : 2000) : 0);
            $birth = checkdate((int) $d[1], (int) $d[2], $year) ? sprintf('%04d-%02d-%02d', $year, $d[1], $d[2]) : null;
            $rest = str_replace($d[0], ' ', $rest);
        } elseif (preg_match('/\b((?:19|20)\d\d)\b/', $rest, $d)) {
            $year = (int) $d[1];
            $rest = str_replace($d[0], ' ', $rest);
        }
        $rest = trim(preg_replace('/\b(\d+|X)\s*-?\s*(YRS?|YEARS?)\s*-?\s*(OLD)?\b|\bAPPROX\.?|\s+/', ' ', $rest));
        // Breed words printed beside the color: "PONY MARE SORREL", "WELCH PONY", "WARMBLOOD/QH", "MULE".
        $breed = null;
        foreach (self::BREED_WORDS as $re => $b) {
            if (preg_match('/\b('.$re.')\b/', $rest, $bw)) {
                $breed ??= $b;
                $rest = trim(str_replace($bw[0], ' ', $rest), ' /');
            }
        }
        $sex = null;
        if (preg_match('/\b('.self::SEX.')\b/', $rest, $s)) {
            $sex = match ($s[1]) {
                'STUD COLT', 'COLT' => 'colt', 'STUD', 'STALLION', 'HORSE' => 'stallion', default => strtolower($s[1])
            };
            $rest = trim(str_replace($s[0], ' ', $rest));
        }
        $color = trim(preg_replace('/\s+/', ' ', $rest));
        $number = $number ? str_replace(',', '', $number) : null;

        if ($breed && ! $reg['breed']) {
            $reg['breed'] = $breed;
        }

        return $reg + ['registration_number' => $number ?: null, 'sex' => $sex, 'birth_date' => $birth,
            'foal_year' => $year && $year >= 1975 && $year <= (int) date('Y') + 1 ? $year : null,
            'color' => $color !== '' && mb_strlen($color) <= 30 && ! preg_match('/\d/', $color) ? ucwords(strtolower($color)) : null];
    }

    /**
     * A positional tree: columns of 2, 4 and 8 names left to right (any deeper column is ignored). The full 14 when it
     * reads as 2 + 4 + 8; otherwise sire, dam and grandparents when the first two columns read as 2 + 4; otherwise nothing.
     */
    public static function tree(array $segs, float $below, string $name): array
    {
        $names = array_values(array_filter(array_map(fn ($s) => ['t' => str_replace(';', "'", $s['t'])] + $s, $segs), fn ($s) => self::nameOk($s['t']) && ! preg_match('/^('.self::REG.')\b|\b\d{5,}\b|^\d+$/', $s['t'])));
        if (count($names) < 6) {
            return [];
        }

        return self::treeBy($names, $name, fn ($s) => $s['x'], 6) ?: self::treeBy($names, $name, fn ($s) => ($s['x'] + $s['x1']) / 2, 12); // left-aligned or centered columns
    }

    private static function treeBy(array $names, string $name, \Closure $at, float $gap): array
    {
        usort($names, fn ($a, $b) => $at($a) <=> $at($b));
        $cols = [];
        foreach ($names as $s) {
            $k = count($cols) - 1;
            if ($k >= 0 && $at($s) - $cols[$k]['x1'] <= $gap) {
                $cols[$k]['n'][] = $s;
                $cols[$k]['x1'] = $at($s);
            } else {
                $cols[] = ['x1' => $at($s), 'n' => [$s]];
            }
        }
        foreach ($cols as &$c) {
            usort($c['n'], fn ($a, $b) => $a['y'] <=> $b['y']);
        }
        unset($c);
        $counts = array_map(fn ($c) => count($c['n']), $cols);
        $start = null;
        foreach ($counts as $i => $n) {
            if ($n === 2 && ($counts[$i + 1] ?? 0) === 4) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return [];
        }
        [$g1, $g2] = [$cols[$start]['n'], $cols[$start + 1]['n']];
        $g3 = ($counts[$start + 2] ?? 0) === 8 ? $cols[$start + 2]['n'] : null;
        // Each parent sits between its own two parents: otherwise the columns are something else.
        $between = fn ($child, $a, $b) => $a['y'] < $child['y'] + 2 && $b['y'] > $child['y'] - 2;
        if (! $between($g1[0], $g2[0], $g2[1]) || ! $between($g1[1], $g2[2], $g2[3]) || $g2[1]['y'] > $g1[1]['y']) {
            return [];
        }
        $ped = ['s' => $g1[0]['t'], 'd' => $g1[1]['t'], 'ss' => $g2[0]['t'], 'sd' => $g2[1]['t'], 'ds' => $g2[2]['t'], 'dd' => $g2[3]['t']];
        if ($g3) {
            $ok = true;
            foreach ($g2 as $i => $p) {
                $ok = $ok && $between($p, $g3[2 * $i], $g3[2 * $i + 1]);
            }
            if ($ok) {
                foreach (['sss', 'ssd', 'sds', 'sdd', 'dss', 'dsd', 'dds', 'ddd'] as $i => $k) {
                    $ped[$k] = $g3[$i]['t'];
                }
            }
        }
        $ped = array_map(fn ($n) => self::clean($n), $ped);
        if (in_array('', [$ped['s'], $ped['d']], true)) {
            return [];
        }
        $ped = array_filter($ped, fn ($n) => $n !== '');
        $key = fn ($n) => preg_replace('/[^A-Z0-9]/', '', $n);
        foreach (['' => ['s', 'd'], 's' => ['ss', 'sd'], 'd' => ['ds', 'dd']] as $child => [$sire, $dam]) {
            $c = $child === '' ? $name : $ped[$child];
            if (isset($ped[$sire], $ped[$dam]) && ($key($ped[$sire]) === $key($ped[$dam]) || $key($ped[$sire]) === $key($c))) {
                return [];
            }
        }

        return $ped;
    }
}
