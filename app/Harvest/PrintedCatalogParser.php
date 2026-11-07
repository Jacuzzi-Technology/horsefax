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
        'THOROUGHBRED|TB' => 'Thoroughbred', 'STANDARDBRED' => 'Standardbred', 'ARABIAN' => 'Arabian', 'MORGAN' => 'Morgan', 'DRAFT(?: CROSS)?' => 'Draft', 'APPALOOSA' => 'Appaloosa'];

    /** "Orla Schrock - Ohio", "A & A Farms - Indiana": a consignor's line, never a horse's name. */
    private const STATES = 'Alabama|Alaska|Arizona|Arkansas|California|Colorado|Connecticut|Delaware|Florida|Georgia|Hawaii|Idaho|Illinois|Indiana|Iowa|Kansas|Kentucky|Louisiana|Maine|Maryland|Massachusetts|Michigan|Minnesota|Mississippi|Missouri|Montana|Nebraska|Nevada|New Hampshire|New Jersey|New Mexico|New York|North Carolina|North Dakota|Ohio|Oklahoma|Oregon|Pennsylvania|Rhode Island|South Carolina|South Dakota|Tennessee|Texas|Utah|Vermont|Virginia|Washington|West Virginia|Wisconsin|Wyoming|Ontario|Alberta|Canada';

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
     *  - model: "LOT 1   MC GENUINE LITTLEJET   LOT 1" / "2019 MODEL PALOMINO GELDING", a tree, then the description; some
     *    lots have only a barn name or "DUN STUD COLT" (Stroud Oklahoma Horse Auction);
     *  - fields: "Lot 25 - Coco Blackburn Champ, “Champ”   Quarter Horse" / "Reg. #6216001  DOB: 06/10/2022  Gelding" /
     *    "Consigned by: ...  Buckskin" (Seymour Select).
     */
    public const LAYOUTS = [
        'southwest' => ['anchor' => '/^LOT\s*NO\.?\s*(\d{1,4}[A-Z]?)\b\s*(.*)$/i', 'header' => 'line'],
        'boone' => ['anchor' => '/^(?:LOT|Lot)\s*#?\s*(\d{1,4}[A-Z]?)\b(?!\s*&)\s*[-–]?\s*(.+)$/u', 'header' => 'inline'], // "LOT 131 & 132": a team, skipped
        'cla' => ['anchor' => '/^Hip\s*#\s*(\d{1,4}[A-Z]?)\s*()$/i', 'header' => 'line', 'name' => 'left'],
        'consignor' => ['anchor' => '/^Consignor:/i', 'header' => 'consignor'],
        'regline' => ['anchor' => '/^(AQHA|APHA|ApHC|APHC|PtHA|PTHA)\s+(\d{5,8})$/', 'header' => 'regline'],
        'model' => ['anchor' => '/^LOT\s+(\d{1,4}[A-Z]?)$/', 'header' => 'model'],
        'seymour' => ['anchor' => '/^Lot\s+(\d{1,4}[A-Z]?)\s*[-–]\s*(.+)$/u', 'header' => 'fields'],
        // The rest are "generic": the name inline, on the line below, or in a labelled field; the description is the next line
        // or the lines matching "desc"; "reg" finds the registration number. See generic().
        'lotname' => ['anchor' => '/^LOT\s*#\s*(\d{1,4}[A-Z]?)$/i', 'header' => 'generic', 'name' => 'below', 'desc' => 'next'],
        'hipfields' => ['anchor' => '/^HIP\s+(\d{1,4}[A-Z]?)$/', 'header' => 'generic', 'name' => '/^HORSE NAME:\s*(.+)$/i', 'desc' => '/^(?:AGE|REGISTRATION NUMBER):/i'],
        'dots' => ['anchor' => '/^(\d{1,4}[A-Z]?)?\s*·\s*(.+?)\s*·(?:\s*\$)?$/u', 'header' => 'generic', 'name' => 'inline', 'desc' => '/(?:yr\.?\s*old|Foaled|\d{1,2}-\d{1,2}-\d{2,4}).*[·•]|[·•].*\b(?:Gelding|Mare|Stallion|Filly|Colt)\b/i'],
        'starred' => ['anchor' => '/^(\d{1,4}[A-Z]?)(?:\s+(\D.*))?$/', 'header' => 'generic', 'name' => 'inline', 'desc' => '/^\*\s*Foaled/i', 'needs' => '/^Consigned By/i'],
        'bignum' => ['anchor' => '/^(\d{1,4}[A-Z]?)\s+([A-Z].+)$/u', 'header' => 'generic', 'name' => 'inline', 'desc' => '/\bDOB:|\bREG:/i', 'minHeight' => 20],
        'gatrhs21' => ['anchor' => '/^(\d{1,3})$/', 'header' => 'generic', 'name' => 'inline', 'desc' => '/^(?:19|20)\d\d\s+[A-Z]/', 'minHeight' => 18, 'parents' => true, 'above' => true, 'reach' => 45], // Great American 2021: the hip printed large left of the name
        'hipline' => ['anchor' => '/^Hip\s*#\s*(\d{1,4}[A-Z]?)$/i', 'header' => 'generic', 'name' => 'below', 'desc' => '/\bFoaled\b/i'], // Heart of Cowboy Country 2013 (OCR)
        'form' => ['anchor' => '/^LOT\s*#?\s*(\d{1,4}[A-Z]?)(?![\d&])\s*-?\s*(?:Grade:\s*x)?$/i', 'header' => 'generic', 'name' => '/^Horse:\s*(.*?)(?:\s+Age:.*)?$/', 'desc' => 'form', 'teams' => true],
    ];

    /** @return list<array> lots: lot, name, registry, registration_number, registered, breed, sex, color, birth_date, foal_year, pedigree */
    public static function read(string $pdf, string $layout, ?int $saleYear = null): array
    {
        $out = [];
        foreach (LolliParser::words($pdf) as $words) {
            if (! $words) {
                continue;
            }
            $segs = LolliParser::segments(self::tidy($words));
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

    /**
     * Rules and braces drawn as text: "____" lines under each tree name (Midwest Draft Cross, 5 Star) and "{" before a
     * pair of parents (Five Star 2018, Heart and Soul) would join a name or shift its column.
     */
    private static function tidy(array $words): array
    {
        $out = [];
        foreach ($words as $w) {
            if (preg_match('/^(?:[_\-–—]{3,}|[{}]+)$/u', $w[4])) { // a rule or a brace; a lone "-" is "NAME - QH 5512093"
                continue;
            }
            if (preg_match('/^[{}]+\s*(.+)$/u', $w[4], $m)) {
                $w[0] += ($w[2] - $w[0]) * (mb_strlen($w[4]) - mb_strlen($m[1])) / max(mb_strlen($w[4]), 1);
                $w[4] = $m[1];
            }
            $out[] = $w;
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
            if ($layouts['header'] === 'generic') {
                if ($s['h'] < ($layouts['minHeight'] ?? 0)) {
                    continue;
                }
                $line = array_filter($segs, fn ($o) => $o !== $s && abs($o['yc'] - $s['yc']) < max(4, $s['h'] * 0.6));
                if (isset($layouts['needs']) && ! array_filter($segs, fn ($o) => $o['y'] > $s['y'] && $o['y'] - $s['y'] < $s['h'] * 3 && abs($o['x'] - $s['x']) < 30 && preg_match($layouts['needs'], $o['t']))) {
                    continue;
                }
                $lot = $m[1] ?? '';
                if (preg_match('/^(?:19|20)\d\d$/', $lot) || preg_match('/^\d/', trim($m[2] ?? ''))) {
                    continue; // "2015 Sorrel Mare" set large: a year, not a lot
                }
                $at = $s;
                if ($lot === '') { // "41" printed apart, left of "· a streak of nitro ·": the block starts at the number
                    $num = array_filter($line, fn ($o) => preg_match('/^\d{1,4}[A-Z]?$/', $o['t']) && $o['x1'] <= $s['x'] + 2);
                    if (! $num) {
                        continue;
                    }
                    $at = array_values($num)[0];
                    $lot = $at['t'];
                }
                $right = array_values(array_filter($line, fn ($o) => $o['x'] > $s['x1'] && ! preg_match('/^\$$/', $o['t'])));
                usort($right, fn ($a, $b) => $a['x'] <=> $b['x']);
                $edge = $s['x1'];
                foreach ($right as $i => $o) { // only what runs on from the anchor, not the next column's lot on the same line
                    if ($o['x'] - $edge > 150) {
                        $right = array_slice($right, 0, $i);
                        break;
                    }
                    $edge = $o['x1'];
                }
                $name = trim($m[2] ?? '');
                if (! empty($layouts['above'])) { // Great American 2022: the name sits just above the hip number, not beside it
                    $up = array_filter($segs, fn ($o) => $o['y'] < $s['y'] && $s['y'] - $o['y'] < $s['h'] * 0.8 && $o['x'] > $s['x1'] && $o['x'] - $s['x1'] < 150 && $o['yc'] < $s['y'] + $s['h'] * 0.3);
                    if ($up && ! array_filter($right, fn ($o) => $o['yc'] < $s['yc'] && ! preg_match('/^(?:19|20)\d\d\s/', $o['t']))) {
                        usort($up, fn ($a, $b) => $a['x'] <=> $b['x']);
                        $name = array_values($up)[0]['t'];
                    }
                }
                if ($name === '' && ($layouts['name'] ?? null) === 'inline' && $right) {
                    $i = array_key_first(array_filter($right, fn ($o) => ! preg_match('/^(?:19|20)\d\d\s/', $o['t']))) ?? 0; // not "2020 NGPA Bay Gelding" set beside it
                    $name = $right[$i]['t'];
                    array_splice($right, $i, 1);
                }
                $at = ['x' => $at['x'] - ($layouts['reach'] ?? 0)] + $at; // the tree starts left of the hip number (Great American 2022)
                $out[] = ['lot' => $lot, 'name' => $name, 'seg' => $at, 'same' => implode(' ', array_map(fn ($o) => $o['t'], $right))];

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
            if ($layouts['header'] === 'model') { // the lot number on both sides of the name: the left one is the anchor
                $line = array_filter($segs, fn ($o) => $o !== $s && abs($o['yc'] - $s['yc']) < 7);
                if (array_filter($line, fn ($o) => $o['t'] === $s['t'] && $o['x'] < $s['x'])) {
                    continue;
                }
                usort($line, fn ($a, $b) => $a['x'] <=> $b['x']);
                $between = array_values(array_filter($line, fn ($o) => $o['x'] > $s['x1'] && $o['t'] !== $s['t']));
                if ($between) {
                    $out[] = ['lot' => $m[1], 'name' => $between[0]['t'], 'seg' => $s];
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
        if ($header === 'generic') {
            return self::generic($a, $block, self::LAYOUTS[$layout], $saleYear);
        }
        $desc = '';
        $reg = null;
        $treeFrom = null;
        $firstNote = null;
        if ($header === 'model') {
            $model = array_values(array_filter(array_slice($block, 0, 3), fn ($o) => preg_match('/^(?:19|20)\d\d\s+MODEL\b/i', $o['t'])));
            $notes = array_values(array_filter($block, fn ($o) => mb_strlen($o['t']) > 36 || preg_match('/\b(yr|year|old|weanling|yearling)\b/i', $o['t'])));
            $firstNote = $notes ? $notes[0]['t'] : null;
            $desc = $model ? $model[0]['t'] : (string) $firstNote;
            $treeFrom = $model ? $model[0]['y'] + $model[0]['h'] : null;
        } elseif ($header === 'regline') {
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
        } elseif ($inline && $header !== 'model') { // the name runs into the description: "Rock Lane Twelve 4 yr old Registered ..."
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
        if ($header === 'model') {
            if (empty($model)) {
                $out['color'] = null; // a color in a sentence may be anyone's
            }
            if ($out['pedigree']) {
                $out['registered'] = true;
            }
            if (preg_match('/(?:\bold|\byrs?|\byear|\d{4})\s+('.self::REG.')\b/', (string) $firstNote, $r)) {
                $out = array_merge($out, array_filter(LolliParser::registry($r[1]), fn ($v) => $v !== null));
                $out['registry'] ??= $r[1];
                $out['registered'] = true;
            }
            // "SUNTAN SHOWGIRL W/2026 ...": a mare and foal, under the mare's name. "9 YR OLD", "2026 AQHA STALLION", "MARE & BLACK FILLY": no name.
            $name = trim(preg_replace('/\s+W\/.*$/', '', $name));
            $out['name'] = $name;
            if (preg_match('/\b(YRS?|YEARS?)\s+OLD\b|\bMODEL\b|^(?:19|20)\d\d\s|\b(?:MARE|FILLY|COLT|GELDING)\s*&|&\s*(?:[A-Z]+\s+)?(?:MARE|FILLY|COLT|GELDING)\b|&$/', $name)) {
                return null;
            }
            // "DUN STUD COLT", "BAY GELDING": no name at all.
            if (preg_match('/^(?:(?:'.self::COLORS.'|(?:19|20)\d\d|GRADE|PONY|MINI|DRAFT|CROSS|GYPSY|QUARTER|HORSE|QH|PAINT|BELGIAN|PERCHERON|FRIESIAN|WEANLING|YEARLING)\s+)*(?:STUD COLT|STUD|GELDING|MARE|FILLY|COLT|STALLION|PONY|MULE|DONKEY)$/', $name)) {
                return null;
            }
        }
        if ($name === '' || preg_match('/^(?:19|20)\d\d\s/', $name) || preg_match('/^(NO SALE|SCRATCH|WITHDRAWN|OUT)\b|^(WEANLING|YEARLING|FILLY|COLT|STUD COLT|MARE|GELDING|STALLION|PONY|MULE|UNNAMED|NO NAME|TBA|TBD|PASS)$/i', $name)) {
            return null;
        }

        return $out;
    }

    /**
     * The layouts set up in LAYOUTS by rules rather than code: the name is inline (in the anchor or just right of it), the
     * first large line below ("below"), or a labelled field (a regex); the description is the line after the name ("next"),
     * the lines matching a regex, or the label/value lines of a form ("form"). The description is read by free(); the
     * tree is whatever names sit below the name, read by tree().
     */
    private static function generic(array $a, array $block, array $cfg, ?int $saleYear): ?array
    {
        $at = $a['seg'];
        $lines = []; // the block's segments joined into lines
        foreach ($block as $s) {
            $k = array_key_last($lines);
            if ($k !== null && abs($lines[$k]['yc'] - $s['yc']) < max(3, $s['h'] * 0.45)) {
                $lines[$k]['segs'][] = $s;
                $lines[$k]['t'] .= ' '.$s['t'];
            } else {
                $lines[] = ['yc' => $s['yc'], 'y' => $s['y'], 'h' => $s['h'], 't' => $s['t'], 'segs' => [$s]];
            }
        }
        $name = $a['name'];
        $nameSeg = null;
        $nameRule = $cfg['name'] ?? 'inline';
        if ($nameRule === 'below') {
            foreach ($block as $s) {
                if ($s['y'] - $at['y'] > $at['h'] * 3.5) {
                    break;
                }
                if ($s['h'] >= $at['h'] * 0.5 && ! preg_match('/^Consign/i', $s['t'])) {
                    // The whole line: "COLONEL GOLDEN BOY" and "- 3965949" can come out as two runs.
                    $same = array_filter($block, fn ($o) => abs($o['yc'] - $s['yc']) < 3 && $o['x'] >= $s['x'] && $o['x'] - $s['x1'] < 40);
                    usort($same, fn ($p, $q) => $p['x'] <=> $q['x']);
                    [$name, $nameSeg] = [implode(' ', array_map(fn ($o) => $o['t'], $same)), $s];
                    break;
                }
            }
        } elseif ($nameRule !== 'inline') {
            foreach ($block as $s) {
                if (preg_match($nameRule, $s['t'], $m)) {
                    [$name, $nameSeg] = [$m[1], $s];
                    break;
                }
            }
            if ($nameSeg === null && preg_match($nameRule, $a['same'] ?? '', $m)) { // "LOT 196-   Horse: The Irish Rose Popy   Age: 12 yrs"
                $name = $m[1];
            }
        }
        $after = $nameSeg ? $nameSeg['y'] + $nameSeg['h'] : $at['y'] + $at['h'];
        $desc = [];
        $descRule = $cfg['desc'] ?? 'next';
        foreach ($lines as $i => $l) {
            if ($descRule === 'next') {
                if ($l['y'] >= $after - 1) {
                    $desc[] = $l;
                    break;
                }
            } elseif ($descRule === 'none') {
                break;
            } elseif ($descRule === 'form') { // "Horse: ... Age: 6 yrs" / "Registered: x Color: Bay Breed: Standardbred Sex: Mare"
                if ($l['y'] > $at['y'] + $at['h'] * 4 || preg_match('/^Description:/i', $l['t'])) {
                    break;
                }
                $desc[] = $l;
            } elseif (preg_match($descRule, $l['t'])) {
                $desc[] = $l;
            }
        }
        $text = implode(' ', array_map(fn ($l) => $l['t'], $desc)).' '.($a['same'] ?? '');
        $text = preg_replace('/Consign(?:or|ed by):.*?(?=\bFoaled\b|$)/i', ' ', $text); // a consignor's name may hold a color ("Bay Ranch")
        $text = preg_replace(['/\b(yrs?|yr)\./i', '/[·•*]/u', '/\b(?:Horse|Age|Color|Breed|Sex|AGE|REGISTRATION NUMBER|DOB|Foaled|Sired by):\s*/i', '/\bRegistered:\s*x\b/i', '/\bGrade:\s*x\b/i', '/\b(?:Registered|Grade):\s*/i'],
            ['$1', ' ', ' ', ' Registered ', ' Grade ', ' '], $text);
        $text = preg_replace('/\bREG:\s*#?\s*([\d,]{5,9})/i', 'REGNO $1', $text);
        $out = ['lot' => $a['lot'], 'registry' => null, 'registration_number' => null, 'registered' => null, 'breed' => null,
            'sex' => null, 'color' => null, 'birth_date' => null, 'foal_year' => null, 'pedigree' => []];
        $out = array_merge($out, self::free(trim(preg_replace('/\s+/', ' ', str_replace('REGNO', '', $text))), $saleYear));
        if (preg_match('/REGNO\s+([\d,]{5,9})/', $text, $r)) {
            $out['registration_number'] = str_replace(',', '', $r[1]);
            $out['registered'] = true;
        }
        if (preg_match('/\s[-–]\s*(?:'.self::STATES.')\s*$/i', (string) $name) || preg_match('/^Consign/i', (string) $name)) {
            return null; // the consignor's line, taken for the name: nothing of it is kept
        }
        [$name, $facts] = self::nameFacts((string) $name);
        foreach ($facts as $k => $v) {
            $out[$k] = $k === 'registered' ? $v : ($out[$k] ?? $v);
        }
        $out = ['name' => $name ?: null] + $out;
        // No name printed, only the registry and number ("AQHA 5583760") or the breed ("GYPSY VANNER", "KENTUCKY MOUNTAIN HORSE 201009021").
        if (preg_match('/^(?:'.self::REG.')\s*#?\s*\d|\d{6,}$|^(?:'.implode('|', array_keys(self::BREED_WORDS)).'|KENTUCKY MOUNTAIN(?: HORSE)?|TENNESSEE WALKER)$/', $name)) {
            return null;
        }
        if ($name === '' || preg_match('/^(?:19|20)\d\d\s/', $name) || preg_match('/^(NO SALE|SCRATCH|WITHDRAWN|OUT|OPEN)\b|^(WEANLING|YEARLING|FILLY|COLT|STUD COLT|MARE|GELDING|STALLION|PONY|MULE|UNNAMED|NO NAME|TBA|TBD|PASS)$/i', $name)) {
            return null;
        }
        if (! empty($cfg['teams']) && preg_match('/\s(?:&|AND)\s|^TEAM\b/', $name)) {
            return null; // "Charm & Charlie": a team sold as one lot
        }
        if ($out['registered'] !== false && ($cfg['tree'] ?? true)) {
            $descSegs = array_merge(...array_map(fn ($l) => $l['segs'], $desc ?: [['segs' => []]]));
            $tree = array_values(array_filter($block, fn ($s) => $s['y'] > $after - 1 && ! in_array($s, $descSegs, true) && $s !== $nameSeg && ! preg_match('/^(CONSIGN|PEDIGREE|HEIGHT|VIDEO|HORSE LOCATION|CALL)\b|^\d{1,4}\s*[-–]\s|\d{3}[-.]\d{3,4}/i', $s['t']))); // "41 - A Streak of Nitro" is the horse itself
            $tree = array_map(fn ($s) => ['t' => preg_replace('/\s+(?:QH|TB|PH|AQHA|APHA)$/', '', $s['t'])] + $s, $tree); // "Definitely A Dunit QH"
            $out['pedigree'] = self::tree($tree, $after, $name, true);
            if (! $out['pedigree'] && ! empty($cfg['parents'])) { // only sire and dam known: dotted leaders where the rest would be (Great American)
                $names = array_values(array_filter($tree, fn ($s) => self::nameOk($s['t']) && mb_strlen($s['t']) <= 36 && ! preg_match('/\d{3}|[.]{3}/', $s['t'])));
                $left = $names ? min(array_map(fn ($s) => $s['x'], $names)) : 0;
                $col = array_values(array_filter($names, fn ($s) => abs($s['x'] - $left) < 6 && $s['h'] < $at['h'] * 0.6));
                if (count($col) === 2 && $col[1]['y'] - $col[0]['y'] < 120) {
                    $out['pedigree'] = self::finish(['s' => $col[0]['t'], 'd' => $col[1]['t']], $name);
                }
            }
            if ($out['pedigree'] && ! isset($out['pedigree']['sd'])) { // sireLines() is for Boone County's short trees; elsewhere 2 + 2 is a broken tree
                $out['pedigree'] = array_intersect_key($out['pedigree'], ['s' => 1, 'd' => 1]);
            }
            if ($out['pedigree']) {
                $out['registered'] = true;
            }
        }

        return $out;
    }

    /**
     * A lot's name and what's printed with it: "SONNY RUNNING CASH - 870841" carries its number, "RICO - Andalusian/Friesian
     * Cross" its breed, "GOOSE - QH Grade" that it's grade; “Denver” beside a name is a barn name; a quoted name alone is one.
     *
     * @return array{0: string, 1: array<string, mixed>} the cleaned name, and registry, breed, registration_number, registered when printed
     */
    public static function nameFacts(string $name): array
    {
        $facts = [];
        $name = trim(preg_replace('/^\d{1,4}\s*[-–]\s*/', '', $name));
        if (preg_match('/^(.+?)\s*[-–]\s*((?:'.self::REG.'|QH|TB|POA)\b.*|[\d,]{5,9}|[A-Za-z][A-Za-z\/ ]+(?:\s+[\d,\-]{4,12})?)$/u', $name, $r) && ! preg_match('/^\d/', $r[1])) {
            $name = $r[1];
            $more = self::free(preg_replace('/^QH\b/', 'Quarter Horse', $r[2]), null);
            $facts = array_filter(['registry' => $more['registry'], 'breed' => $more['breed']]);
            if (preg_match('/([\d,]{5,9})\s*$/', $r[2], $n)) {
                $facts['registration_number'] = str_replace(',', '', $n[1]);
                $facts['registered'] = true;
            } elseif ($more['registered'] !== null) {
                $facts['registered'] = $more['registered'];
            }
        }
        $name = preg_match('/^[“"].*[”"]$/u', $name) ? trim($name, '“”" ') : trim(preg_replace('/\s*[“"(][^”")]*[”")]\s*/u', ' ', $name));

        return [self::clean($name), $facts];
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
        } elseif (preg_match('/\b(?:FOALED|BORN)\s+(?:IN\s+)?((?:19|20)\d\d)\b|\b((?:19|20)\d\d)\s+(?:MODEL|'.self::REG.'|GRADE|REGISTERED)\b|^\s*((?:19|20)\d\d)\s+[A-Z]/', $first, $d)) {
            $out['foal_year'] = (int) ($d[1] ?: ($d[2] ?: $d[3])); // "2015 AQHA Buckskin Gelding"
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
            $cross = preg_match('/\bCROSS\b|\sX\s|[A-Z]\s*\/\s*[A-Z]/', $first) && array_key_first($found) !== 'Draft'; // "Andalusian/Friesian Cross"
            $out['breed'] = count($found) === 1 && ! $cross ? array_key_first($found) : null; // "Percheron/Quarter Horse" is a cross: no one breed
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
    public static function tree(array $segs, float $below, string $name, bool $wrapped = false): array
    {
        if ($wrapped) {
            $segs = self::unwrap($segs);
        }
        $names = array_values(array_filter(array_map(fn ($s) => ['t' => str_replace(';', "'", $s['t'])] + $s, $segs), fn ($s) => self::nameOk($s['t']) && ! preg_match('/^('.self::REG.')\b|\b\d{5,}\b|^\d+$/', $s['t'])
            && ! preg_match('/^(?:'.implode('|', array_keys(self::BREED_WORDS)).'|GRADE|UNKNOWN|N\/A|UNREGISTERED)$/i', $s['t']))); // a draft cross's tree names breeds ("Percheron")
        if (count($names) < 4) { // four: sire, dam and their sires (sireLines)
            return [];
        }

        return self::treeBy($names, $name, fn ($s) => $s['x'], 6) ?: self::treeBy($names, $name, fn ($s) => ($s['x'] + $s['x1']) / 2, 12) // left-aligned or centered columns
            ?: self::treeBy($names, $name, fn ($s) => ($s['x'] + $s['x1']) / 2, 22); // centered in wide boxes (Midwest Draft Cross)
    }

    /**
     * Long names in a centered tree wrap onto a second, shorter line centered under the first ("POCO GRULLO" / "SPIRIT",
     * Anderson County): join them. Left-aligned columns are never joined, since there the next name starts at the same x.
     */
    public static function unwrap(array $segs): array
    {
        usort($segs, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
        $out = [];
        foreach ($segs as $s) {
            foreach ($out as $i => $o) {
                $center = abs(($o['x'] + $o['x1']) / 2 - ($s['x'] + $s['x1']) / 2);
                if ($center < 15 && $s['y'] > $o['y'] && $s['y'] - $o['y'] < $o['h'] * 1.3 && abs($s['h'] - $o['h']) < 2 && ($s['x'] - $o['x'] > 5 || $o['x'] - $s['x'] > 5)
                    && ! preg_match('/\d{4}|[a-z]{3,}\s+[a-z]{3,}\s+[a-z]{3,}/', $s['t'].' '.$o['t'])) {
                    $t = str_ends_with($o['t'], '-') ? substr($o['t'], 0, -1).$s['t'] : $o['t'].' '.$s['t']; // "ALLAMERICAN-" / "DREAMGIRL" is one word
                    $out[$i] = ['t' => $t, 'x' => min($o['x'], $s['x']), 'x1' => max($o['x1'], $s['x1']), 'yc' => ($o['y'] + $s['y'] + $s['h']) / 2] + $o;
                    continue 2;
                }
            }
            $out[] = $s;
        }

        return array_values($out);
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
            return ($p = self::sireLines($cols, $counts)) ? self::finish($p, $name) : [];
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

        return self::finish($ped, $name);
    }

    /**
     * The short tree some Boone County catalogs print: sire and dam, then only the sire line above each ("51's Leo" under
     * "King's Final"; "King's Fern" under "Johns Johnnie"): columns of 2 and 2 (and 2), each name above its child.
     */
    private static function sireLines(array $cols, array $counts): array
    {
        foreach ($counts as $i => $n) {
            if ($n !== 2 || ($counts[$i + 1] ?? 0) !== 2) {
                continue;
            }
            [$g1, $g2] = [$cols[$i]['n'], $cols[$i + 1]['n']];
            $above = fn ($p, $c) => $p['y'] < $c['y'] && $c['y'] - $p['y'] < 60;
            if (! $above($g2[0], $g1[0]) || ! $above($g2[1], $g1[1]) || $g2[1]['y'] < $g1[0]['y']) {
                return [];
            }
            $ped = ['s' => $g1[0]['t'], 'd' => $g1[1]['t'], 'ss' => $g2[0]['t'], 'ds' => $g2[1]['t']];
            if (($counts[$i + 2] ?? 0) === 2) {
                $g3 = $cols[$i + 2]['n'];
                if ($above($g3[0], $g2[0]) && $above($g3[1], $g2[1]) && $g3[1]['y'] > $g1[0]['y']) {
                    [$ped['sss'], $ped['dss']] = [$g3[0]['t'], $g3[1]['t']];
                }
            }

            return $ped;
        }

        return [];
    }

    private static function finish(array $ped, string $name): array
    {
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
