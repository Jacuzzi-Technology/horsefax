<?php

namespace App\Harvest;

use App\Models\SaleLot;
use App\Models\ShowRecord;

/**
 * Waverly Midwest Horse Sale catalogs, from `pdftotext -layout` text. Three layouts:
 *
 *  - 2013–2025 ("LOT 11 - Walnut View Buttercup M140348      Belgian Mare", "Foaled: March 18, 2019", a color line,
 *    then "Sire: <name> <reg>, by <name>, out of <name>" with the sire's sire and dam on the next lines, the same for
 *    "Dam:"; 2013 prints "2nd Dam:" / "3rd Dam:" instead). Grade lots are prose: "LOT 8 - Pete, a grade 2 year old
 *    black Percheron gelding."
 *  - 2026 ("Lot 66 - Osage Louise of Nightlight      Registered Clydesdale Mare", a 3-generation chart in columns,
 *    "Reg. #29473", "Foaled 03/04/2023" in the description).
 *  - 2003–04 "partial listing" flyers in two or three columns, no lot numbers: "- Long Lane Angie Registered Belgian
 *    Mare Foaled: April 20th, 2000. Red sorrel, stripe ... Sire: Heins' Classic Mark. Dam: Long Lane Rosie." Read in
 *    reading order (`pdftotext` without -layout) by catalogFlyer().
 *
 * Never returned: consignor, "Fitted by", towns, phone numbers and the description text.
 */
class WaverlyParser
{
    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';
    private const SEX = 'Mare|Gelding|Stallion|Filly|Colt|Stud';
    private const BREEDS = 'Belgian|Percheron|Clydesdale|Shire|Suffolk(?: Punch)?|Haflinger|Halflinger|Fjord|Norwegian Fjord|Friesian|Morgan|Standardbred|Quarter Horse|Quarter Pony|Paint|Appaloosa|Gypsy(?: Vanner)?|Welsh(?: Pony)?|Shetland(?: Pony)?|Miniature(?: Horse| Pony)?|Pony|Hackney(?: Pony)?|Spotted Draft|American Cream(?: Draft)?|Brabant|Draft Cross(?:bred)?|Draft|Arabian|Thoroughbred|Saddlebred|Tennessee Walking Horse|Fox Trotter|Mule|Crossbred';
    /** A registration number as these catalogs print it: S68529, M140348, 293141, 23839-M, A-22807-M, GR183, B-2281. */
    private const REG = '(?:[A-Z]{1,2}-?\d{2,7}(?:-[A-Z])?|\d{3,7}(?:-[A-Z])?|[A-Z]-\d{3,6}(?:-[A-Z])?)';

    /**
     * A page of the live site: catalog PDFs it links, and sale dates, "Fall HORSE SALE October 6-10, 2026" or "Our Next
     * Auction Begins On October 6, 2026" (a start only), keyed "fall-2026".
     *
     * @return array{pdfs: list<string>, dates: array<string, array{from:string, to:string}>}
     */
    public static function salePage(string $html): array
    {
        preg_match_all('#https?://(?:www\.)?waverlysales\.com/wp-content/uploads/[^"\'\s<>]+?\.pdf#i', $html, $p);
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(preg_replace('/<[^>]*>/', ' ', preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $dates = [];
        $add = function (string $month, string $d1, ?string $d2, string $year) use (&$dates) {
            $from = date('Y-m-d', strtotime("$month $d1 $year"));
            $key = ((int) substr($from, 5, 2) <= 6 ? 'spring' : 'fall').'-'.$year;
            if (! isset($dates[$key]) || $d2) {
                $dates[$key] = ['from' => $from, 'to' => $d2 ? date('Y-m-d', strtotime("$month $d2 $year")) : $from];
            }
        };
        preg_match_all('/\b(?:Spring|Fall)\s+HORSE\s+SALE\s+('.self::MONTHS.')\s+(\d{1,2})\s*[-–]\s*(\d{1,2}),?\s+(20\d\d)/iu', $text, $r, PREG_SET_ORDER);
        foreach ($r as $m) {
            $add($m[1], $m[2], $m[3], $m[4]);
        }
        preg_match_all('/Next Auction Begins On\s+('.self::MONTHS.')\s+(\d{1,2}),?\s+(20\d\d)/iu', $text, $r, PREG_SET_ORDER);
        foreach ($r as $m) {
            $add($m[1], $m[2], null, $m[3]);
        }

        return ['pdfs' => array_values(array_unique($p[0])), 'dates' => $dates];
    }

    /**
     * Season, year and first sale day, from the file name and the cover. A cover with no year ("Thursday, October 8",
     * 2015) takes the year, at or before $hintYear (when the Archive saw the file), whose calendar puts that date on
     * that weekday.
     */
    public static function sale(string $file, string $text, ?int $hintYear = null): array
    {
        if ($hintYear && ! preg_match('/\b(Spring|Fall)\s+20\d\d\b|\b20\d\d\s+(Spring|Fall)\b/i', substr($text, 0, 15000))
            && ! preg_match('/(?:^|[_-])(?:WAV|WHS|WMHS)?[-_]?[SF](?:\d{2}|20\d\d)(?:[-_.]|$)/i', basename($file, '.pdf'))
            && preg_match_all('/(?<![&,] )\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday),\s+('.self::MONTHS.')\s+(\d{1,2})\b(?!\s*&)/', substr($text, 0, 15000), $ws, PREG_SET_ORDER)) {
            // The year (the Archive's year or up to two before) in which the most printed weekday + date pairs fall right.
            $best = [null, 0];
            foreach ([$hintYear, $hintYear - 1, $hintYear - 2] as $y) {
                $hits = count(array_filter($ws, fn ($w) => date('l', strtotime("$w[2] $w[3] $y")) === $w[1]));
                if ($hits > $best[1]) {
                    $best = [$y, $hits];
                }
            }
            if ($best[0] && $best[1] * 2 > count($ws)) {
                $year = $best[0];
                $first = min(array_map(fn ($w) => date('Y-m-d', strtotime("$w[2] $w[3] $year")), $ws));
                $season = (int) substr($first, 5, 2) <= 6 ? 'Spring' : 'Fall';

                return ['key' => strtolower($season).'-'.$year, 'name' => "Waverly Midwest Horse Sale, $season $year", 'season' => $season, 'year' => $year, 'date' => $first];
            }
        }
        $year = null;
        $head = substr($text, 0, 15000);
        $season = null;
        $year = null;
        if (preg_match('/(?:^|[_-])(?:WAV|WHS|WMHS)?[-_]?([SF])(\d{2}|20\d\d)(?:[-_.]|$)/i', basename($file, '.pdf'), $m)) {
            $season = strtoupper($m[1]) === 'S' ? 'Spring' : 'Fall';
            $year = strlen($m[2]) === 2 ? 2000 + (int) $m[2] : (int) $m[2];
        } elseif (preg_match('/\b(Spring|Fall)\s+(20\d\d)\b|\b(20\d\d)\s+(Spring|Fall)\b/i', $head, $m)) {
            $season = ucfirst(strtolower($m[1] ?: $m[4]));
            $year = (int) ($m[2] ?: $m[3]);
        }
        $date = null;
        if ($year && preg_match('/\b('.self::MONTHS.')\s+(\d{1,2})(?:st|nd|rd|th)?\s*(?:,|-|–|to|&|\s)/', $head, $d)) {
            $date = date('Y-m-d', strtotime("$d[1] $d[2] $year"));
            $season ??= in_array($d[1], ['March', 'April', 'May'], true) ? 'Spring' : 'Fall';
        }
        $key = $season && $year ? strtolower($season).'-'.$year : null;

        return ['key' => $key, 'name' => 'Waverly Midwest Horse Sale'.($season && $year ? ", $season $year" : ''), 'season' => $season, 'year' => $year, 'date' => $date];
    }

    /** Catalog lots, whichever layout the text is in. */
    public static function catalog(string $text, ?string $flowText = null): array
    {
        $lots = self::catalogLot($text);
        if (count($lots) < 5) {
            $lots = self::catalog2026($text) ?: $lots;
        }
        if (count($lots) < 5 && $flowText !== null) {
            $lots = self::catalogFlyer($flowText) ?: $lots;
        }

        return $lots;
    }

    /** The 2013–2025 layout. */
    public static function catalogLot(string $text): array
    {
        $lots = [];
        foreach (self::blocks($text, '/^\s*LOT\s+(\d+[A-Z]?)\s+[-–—]\s+(.*)$/u') as [$no, $head, $block]) {
            if (preg_match('/^(.*?\S)\s{2,}('.self::BREEDS.')(?:\s+(?:Stallion\s+)?('.self::SEX.')s?)?(?:\s+Colt)?\s*$/iu', $head, $m)) {
                // A registered horse: name (and number), breed and sex at the right.
                [$name, $reg] = self::nameAndNumber($m[1]);
                if (! $name) {
                    continue;
                }
                $all = implode("\n", $block);
                $breed = self::breed($m[2]);
                [$foalYear, $birth] = self::foaled($all);
                $lots[] = ['lot' => $no, 'name' => $name, 'registered' => true, 'registration_number' => $reg, 'registry' => self::registry($breed, $reg),
                    'breed' => $breed, 'sex' => AuctioneerSoftwareParser::sex($head) ?? null, 'foal_year' => $foalYear, 'birth_date' => $birth, 'age' => null,
                    'color' => self::colorLine($block), 'pedigree' => self::byOutOf($block)];

                continue;
            }
            if ($lot = self::prose($no, $head.' '.implode(' ', array_slice($block, 0, 2)))) {
                $lots[] = $lot;
            }
        }

        return self::unique($lots);
    }

    /** The 2026 layout. */
    public static function catalog2026(string $text): array
    {
        $lots = [];
        foreach (self::blocks($text, '/^\s*Lot\s+(\d+[A-Z]?)\s+[-–—]\s+(.*)$/u') as [$no, $head, $block]) {
            // "Osage Char of King      Registered Clydesdale Mare"; some print no status ("Marriott's Linda      Belgian Filly").
            if (! preg_match('/^(.*?\S)\s{2,}(?:(Registered|Grade)\s+)?(.+?)\s*$/u', $head, $m)
                || (! $m[2] && ! preg_match('/\b(?:'.self::BREEDS.')\b/i', $m[3]))) {
                continue; // no name ("Lot 44 -      Grade Belgian Stallion") or not a horse
            }
            $name = self::cleanName($m[1]);
            if (! $name) {
                continue;
            }
            $kind = $m[2] ?: null;
            $rest = $m[3];
            // Sex and color sit at the right of the next lines when the heading wraps ("Registered Clydesdale" / "Mare" / "Black").
            $right = [];
            foreach (array_slice($block, 0, 3) as $line) {
                if (preg_match('/\s{3,}(\S(?:.*\S)?)\s*$/', $line, $r) && strlen($line) - strlen(ltrim($line)) < 40) {
                    $right[] = trim($r[1]);
                }
            }
            $sex = AuctioneerSoftwareParser::sex($rest) ?? AuctioneerSoftwareParser::sex($right[0] ?? '');
            $breed = self::breed(trim(preg_replace('/\b(?:'.self::SEX.')s?\b/i', '', $rest)));
            $color = null;
            foreach ($right as $r) {
                if (! AuctioneerSoftwareParser::sex($r) && preg_match('/^[A-Z][A-Za-z ]{2,25}$/', $r) && ! preg_match('/Ranch|Farm/', $r)) {
                    $color = ucfirst(strtolower($r));
                    break;
                }
            }
            $all = preg_replace('/\s+/', ' ', implode(' ', $block));
            $reg = preg_match('/Reg\.?\s*#\s*('.self::REG.')\b/', $all, $g) ? $g[1] : null;
            $foalYear = null;
            $birth = null;
            if (preg_match('#Foaled:?\s*(\d{1,2})[/-](\d{1,2})[/-]\s?((?:19|20)\d\d)#', $all, $f)) {
                $birth = sprintf('%04d-%02d-%02d', $f[3], $f[1], $f[2]);
                $foalYear = (int) $f[3];
            } else {
                [$foalYear, $birth] = self::foaled($all);
            }
            $age = preg_match('/\ba (\d{1,2}) (?:year|yr)s?[ -]old\b/i', $all, $a) || preg_match('/\bAge:\s*(\d{1,2})\s*(?:Years?|Yrs?)\b/i', $all, $a) ? (int) $a[1] : null;
            // The fall 2026 layout: the color alone on the line after "Age: … Height: …".
            foreach (array_slice($block, 0, 6) as $i => $line) {
                if (! $color && preg_match('/^\s*Age:.*Height:/', $line) && preg_match('/^\s*([A-Z][a-z]+(?: [A-Z][a-z]+)?)\s*$/', $block[$i + 1] ?? '', $c)
                    && ! AuctioneerSoftwareParser::sex($c[1])) {
                    $color = $c[1];
                }
            }
            $lots[] = ['lot' => $no, 'name' => $name, 'registered' => $kind === null ? ($reg ? true : null) : $kind === 'Registered', 'registration_number' => $reg,
                'registry' => self::registry($breed, $reg), 'breed' => $breed, 'sex' => $sex, 'foal_year' => $foalYear, 'birth_date' => $birth,
                'age' => $foalYear ? null : $age, 'color' => $color, 'pedigree' => $kind === 'Grade' ? [] : self::chart($block)];
        }

        return self::unique($lots);
    }

    /**
     * The 2003–04 flyers, in reading order: only registered horses carry a name, foal date and parents; the grade
     * horses and teams in between ("- Brad and Buster, a team of grade ...") are skipped. No lot numbers: lot is null.
     */
    public static function catalogFlyer(string $flowText): array
    {
        $t = preg_replace('/\s+/u', ' ', $flowText);
        preg_match_all('/(?:^|\s)[-–] (?:\((?:team )?pictured\) [-–] )?([A-Z][^.]{2,70}?) (?:Registered|Reg\.) ((?:[A-Z][a-z]+ ){0,2}?[A-Z][a-z]+) ('.self::SEX.')s?\b(.{0,500}?)Sire: ([^.]{2,60}?)\.\s*Dam: ([^.]{2,60}?)\./u', $t, $all, PREG_SET_ORDER);
        $lots = [];
        foreach ($all as $m) {
            $name = self::nameAndNumber(preg_replace('/^.*\)\s*[-–]\s*/u', '', $m[1]))[0];
            if (! $name || str_word_count($name) < 2 || preg_match('/\b(team|pair)\b/i', $name)) {
                continue;
            }
            [$foalYear, $birth] = self::foaled($m[4]);
            $color = preg_match('/Foaled:?[^.]*\.\s*([A-Z][a-z]+(?: [a-z]+)?)[.,;]/', $m[4], $c) ? ucfirst(strtolower($c[1])) : null;
            $lots[] = ['lot' => null, 'name' => $name, 'registered' => true, 'registration_number' => null, 'registry' => null, 'breed' => self::breed($m[2]),
                'sex' => strtolower($m[3] === 'Stud' ? 'stallion' : $m[3]), 'foal_year' => $foalYear, 'birth_date' => $birth, 'age' => null, 'color' => $color,
                'pedigree' => array_filter(['s' => self::nameAndNumber($m[5])[0], 'd' => self::nameAndNumber($m[6])[0]])];
        }

        return self::unique($lots);
    }

    /**
     * The .bid lot (waverlysales.bid, AuctioneerSoftware) of the same horse: same sale season, same lot number, and
     * names that agree (one may drop a prefix: "ConnRoyal" for "Riverglen's ConnRoyal").
     */
    public static function bidLot(int $bidSourceId, array $sale, array $lot): ?SaleLot
    {
        if (! $lot['lot'] || ! $sale['year'] || ! $sale['season']) {
            return null;
        }
        $months = $sale['season'] === 'Spring' ? [2, 3, 4, 5] : [8, 9, 10, 11];
        $candidates = SaleLot::where('data_source_id', $bidSourceId)->where('sale_year', $sale['year'])->where('lot', $lot['lot'])
            ->whereNotNull('sale_date')->get()->filter(fn ($l) => in_array((int) $l->sale_date->format('n'), $months, true));
        $want = ShowRecord::key($lot['name']);
        $hits = $candidates->filter(function ($l) use ($want) {
            $k = ShowRecord::key($l->horse_name);
            if ($k === '' || $want === '') {
                return false;
            }

            return $k === $want || (min(strlen($k), strlen($want)) >= 5 && (str_contains($want, $k) || str_contains($k, $want)));
        });

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /** [lot number, heading text, the lines up to the next lot or separator]. */
    private static function blocks(string $text, string $headRe): array
    {
        $lines = preg_split('/\R/', str_replace("\f", "\n", $text));
        $out = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (! preg_match($headRe, $lines[$i], $m)) {
                continue;
            }
            $block = [];
            for ($j = $i + 1; $j < $n && $j < $i + 45; $j++) {
                if (preg_match($headRe, $lines[$j]) || preg_match('/^\s*(?:_{10,}|[–—-]{10,})/u', $lines[$j])) {
                    break;
                }
                $block[] = $lines[$j];
            }
            $out[] = [$m[1], rtrim($m[2]), $block];
        }

        return $out;
    }

    /** A grade (or unnamed-registered) lot written as prose: "Pete, a grade 2 year old black Percheron gelding." */
    private static function prose(string $no, string $text): ?array
    {
        $text = preg_replace('/\s+/', ' ', $text);
        if (! preg_match('/^([^,]{2,40}?),\s+an?\s+(.{5,160}?)(?:\.\s|\.$|$)/u', $text, $m) || preg_match('/\b(team|pair|teams|span) of\b|\band\b.*\bteam\b/i', $m[2])
            || preg_match('/\s(?:and|&)\s/', $m[1])) {
            return null; // teams and pairs are two horses
        }
        $desc = $m[2];
        $breed = preg_match('/\b('.self::BREEDS.')(?:\/[A-Z][a-z]+)?\b/i', $desc, $b) ? self::breed($b[1]) : null;
        if (preg_match('/\b(mule|donkey|jenny|jack|molly|john)\b/i', $desc)) {
            return null;
        }
        $sex = AuctioneerSoftwareParser::sex($desc);
        if (! $sex && ! $breed) {
            return null;
        }
        $grade = (bool) preg_match('/\bgrade\b|cross(?:bred)?\b|\//i', $desc);
        $age = preg_match('/\b(\d{1,2}) (?:year|yr)s?[ -]old\b/i', $desc, $a) ? (int) $a[1] : (preg_match('/\b(weanling|yearling)\b/i', $desc, $w) ? ($w[1] === 'weanling' ? 0 : 1) : null);
        [$foalYear, $birth] = self::foaled($text);
        $color = preg_match('/\b(black|bay|brown|sorrel|red sorrel|chestnut|gr[ae]y|white|roan|blue roan|red roan|strawberry roan|bay roan|palomino|buckskin|dun|grulla|blonde|cream|pinto|paint|black and white|bay and white|sorrel and white|dapple gr[ae]y)\b/i', $desc, $c) ? ucfirst(strtolower($c[1])) : null;

        [$name, $reg] = self::nameAndNumber($m[1]); // "Oak Knoll Skye 3770-S, a 2 year old ..."
        if (! $name) {
            return null;
        }

        return ['lot' => $no, 'name' => $name, 'registered' => $reg ? true : ($grade ? false : null), 'registration_number' => $reg, 'registry' => null, 'breed' => $breed,
            'sex' => $sex, 'foal_year' => $foalYear, 'birth_date' => $birth, 'age' => $foalYear ? null : $age, 'color' => $color, 'pedigree' => []];
    }

    /** "Walnut View Buttercup M140348" -> [name, number]. */
    private static function nameAndNumber(string $s): array
    {
        $s = self::tidy(preg_replace('/(\d)\s*\((CN|CAN)\)/i', '$1 CN', $s)); // "M33478(CN)"
        // Numbers after the name, peeled off from the right: registration numbers ("M140348", "#290737"), Canadian
        // ones ("70957 CN", "26050 CAN"), a second registry's between dashes ("-1039184-", "Troll -492-") and the
        // Haflinger book's name-number-year ("Molly-2113-88"). The horse's own number is the leftmost one that isn't
        // Canadian.
        $numbers = [];
        while (preg_match('/^(.*?\S)(?:\s+(#?'.self::REG.'|CN|CAN|Can|Canada|-\d{2,8}-)|-(\d{3,5}-\d{2}))$/u', $s, $m)) {
            $tok = ltrim($m[2] ?? '', '#');
            if ($tok !== '' && preg_match('/^'.self::REG.'$/', $tok) && preg_match('/\d/', $tok)) {
                $numbers[] = [$tok, ($numbers && end($numbers)[0] === 'CN')];
            } elseif (preg_match('/^(CN|CAN|Can|Canada)$/', $tok)) {
                $numbers[] = ['CN', false];
            }
            $s = $m[1];
        }
        // Registries printed in other shapes (Fjords: "MVF Froya N-14917 N-Z-444-M HOF", "King Oscar I 07-5634-S"): the
        // name ends before the first word holding two or more digits.
        if (preg_match('/^(.*?\S)\s+\S*\d{2,}/u', $s, $cut) && preg_match('/[A-Za-z]{2}/', $cut[1])) {
            $s = trim(preg_replace('/(?:\s+\S+-)+$/u', '', $cut[1])); // "Olof's Dafinn C- 343": drop the dangling "C-"
        }
        $reg = null;
        foreach ($numbers as [$n, $canadian]) {
            if ($n !== 'CN' && ! $canadian) {
                $reg = $n; // the last found is the leftmost
            }
        }

        return [self::cleanName($s), $reg];
    }

    private static function cleanName(string $s): ?string
    {
        $s = trim(preg_split('/\s+(?:AKA|A\.K\.A\.)\s+|\s*[“"(]/iu', $s)[0]);
        $s = preg_replace('/\s+(?:Reg(?:istration)?\.?\s*(?:pending|applied for)?|Pending)$/i', '', $s);

        return $s !== '' && preg_match('/[A-Za-z]{2}/', $s) ? NrchaHarvester::display(self::tidy($s)) : null;
    }

    /** The color line under "Foaled:" (the first short line that isn't a label). */
    private static function colorLine(array $block): ?string
    {
        $seen = false;
        foreach (array_slice($block, 0, 4) as $line) {
            $t = trim($line);
            if (preg_match('/^Foaled/i', $t)) {
                $seen = true;
                continue;
            }
            if ($seen && $t !== '' && ! preg_match('/^(Sire|Dam)\b/', $t)) {
                $c = trim(preg_split('/[,;(]/', $t)[0]);

                return preg_match('/^[A-Za-z][A-Za-z \/-]{2,30}$/', $c) ? ucfirst(strtolower($c)) : null;
            }
        }

        return null;
    }

    /**
     * The "Sire: X, by A, out of B" lines. Each entry starts on its own line (a line holding ", by "), wrapped
     * continuations are joined to it. Sire entries give s / ss / sd and the next generation from the sire's sire and dam
     * lines; the same for the dam. 2013's "2nd Dam:" / "3rd Dam:" give the female line.
     */
    public static function byOutOf(array $block): array
    {
        $sections = ['Sire' => [], 'Dam' => [], '2nd Dam' => [], '3rd Dam' => []];
        $cur = null;
        foreach ($block as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^(Sire|Dam|2nd Dam|3rd Dam):\s*(.*)$/', $t, $m)) {
                $cur = $m[1];
                $sections[$cur][] = $m[2];
                continue;
            }
            if ($cur === null) {
                continue;
            }
            if (! preg_match('/^\s{4,}/', $line) || preg_match('/^(Stands|Open|Bred|In foal|Sells|Has |Is |She |He |They |This |Exposed|Broke|Registration)/', $t)) {
                $cur = null; // the chart has ended: description, notes, the next consignor
                continue;
            }
            $last = array_key_last($sections[$cur]);
            if (preg_match('/^by\s/', $t) && ! str_contains($sections[$cur][$last], ', by ')) {
                $sections[$cur][$last] = rtrim($sections[$cur][$last], ' ,').', '.$t; // "Sire: X 2287-S," / "by Fox Valley Equalizer"
            } elseif (str_contains($t, ', by ')) {
                $sections[$cur][] = $t; // the next ancestor's own line
            } else {
                $sections[$cur][$last] .= ' '.$t; // a wrapped line
            }
        }
        $entry = function (?string $e): array {
            if ($e === null) {
                return [null, null, null];
            }
            $e = preg_replace('/\s+/', ' ', preg_replace('/-\s+(?=[a-z])/', '', $e)); // "Jan- ice" -> "Janice"
            $parts = preg_split('/,\s*by\s+|,?\s+out of\s+/', rtrim(trim($e), '.,'));

            return array_map(fn ($p) => $p === null ? null : self::nameAndNumber(rtrim(trim($p), '.,'))[0], [$parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null]);
        };
        $p = [];
        [$p['s'], $p['ss'], $p['sd']] = $entry($sections['Sire'][0] ?? null);
        [$p['d'], $p['ds'], $p['dd']] = $entry($sections['Dam'][0] ?? null);
        if (count($sections['Sire']) >= 3) {
            [, $p['sss'], $p['ssd']] = $entry($sections['Sire'][1]);
            [, $p['sds'], $p['sdd']] = $entry($sections['Sire'][2]);
        }
        if (count($sections['Dam']) >= 3) {
            [, $p['dss'], $p['dsd']] = $entry($sections['Dam'][1]);
            [, $p['dds'], $p['ddd']] = $entry($sections['Dam'][2]);
        }
        if ($sections['2nd Dam']) {
            [$p['dd'], $p['dds']] = $entry($sections['2nd Dam'][0]);
        }
        if ($sections['3rd Dam']) {
            [$p['ddd']] = $entry($sections['3rd Dam'][0]);
        }
        $p = array_filter($p, fn ($v) => $v !== null && $v !== '' && mb_strlen($v) <= 60 && ! str_contains($v, ',')); // a comma: a misprint ("King, our of Delight")
        // A wrapped line read into the wrong entry runs two names together ("Blue Ribbon Farms Jennifer Blue Ribbon
        // Farms Hercules"): a name that is another ancestor's whole name (2+ words) with 2+ more words before or after
        // it is dropped. "Belter Mc Gee Mitch" beside "Mc Gee" is a real name and stays.
        $words = fn ($s) => count(preg_split('/\s+/', trim($s)));
        foreach ($p as $k => $v) {
            foreach ($p as $k2 => $v2) {
                $rest = str_starts_with($v, $v2.' ') ? substr($v, strlen($v2) + 1) : (str_ends_with($v, ' '.$v2) ? substr($v, 0, -strlen($v2) - 1) : null);
                if ($k !== $k2 && $rest !== null && $words($v2) >= 2 && $words($rest) >= 2) {
                    unset($p[$k]);
                    continue 2;
                }
            }
        }

        return isset($p['s']) || isset($p['d']) ? $p : [];
    }

    /**
     * The 2026 chart: in the spring catalog the horse's own name, breed and number print at the far left; drop them, then
     * read the columns. The fall catalog (an "Age: … Height: …" line under the heading) prints the sire and dam at the
     * left edge instead, so nothing is dropped there.
     */
    private static function chart(array $block): array
    {
        $lines = [];
        $started = false;
        $parentsAtLeft = (bool) preg_grep('/^\s*Age:.*Height:/', array_slice($block, 0, 6));
        foreach ($block as $line) {
            if (! $started && (trim($line) === '' || strlen($line) - strlen(ltrim($line)) < 15)) {
                continue; // consignor and "Fitted by" lines
            }
            $started = true;
            if (preg_match('/^\s{0,25}\S.*\ba \d{1,2} (?:year|yr)/', $line) || preg_match('/^\s{0,3}\S+(?: \S+){5,}/u', $line)) {
                break; // the description begins (six or more words in a run from the left edge)
            }
            // The horse's own name, breed and "Reg. #" print from the left edge, sometimes past the sire/dam column's start.
            $lines[] = $parentsAtLeft ? $line : preg_replace_callback('/^\s{0,3}\S.*?(?=\s{2,}|$)/u', fn ($m) => str_repeat(' ', mb_strlen($m[0])), $line);
        }

        return TopekaParser::chart($lines);
    }

    /** "Foaled: March 18, 2019" / "Foaled April 24, 2015" / "Foaled: 2001" -> [year, date]. */
    private static function foaled(string $text): array
    {
        $t = preg_replace('/\s+/', ' ', $text);
        if (preg_match('/Foaled:?\s*('.self::MONTHS.')\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+((?:19|20)\d\d)/', $t, $d)) {
            return [(int) $d[3], date('Y-m-d', strtotime("$d[1] $d[2] $d[3]"))];
        }
        if (preg_match('/Foaled:?\s*(?:(?:in\s+)?(?:Spring|Summer|Fall|early|late)\s+(?:of\s+)?)?((?:19|20)\d\d)\b/i', $t, $d)) {
            return [(int) $d[1], null];
        }

        return [null, null];
    }

    private static function breed(?string $b): ?string
    {
        $b = trim((string) $b);
        if ($b === '') {
            return null;
        }
        $b = preg_replace('/^Halflinger$/i', 'Haflinger', $b);
        $b = preg_replace('/^Norwegian Fjord$/i', 'Fjord', $b);

        return ucwords(strtolower($b));
    }

    /** A registry only where the number says so: a 7-digit Quarter Horse number is AQHA (as in Heritage's catalogs). */
    private static function registry(?string $breed, ?string $reg): ?string
    {
        return $reg && $breed === 'Quarter Horse' && preg_match('/^\d{7}$/', $reg) ? 'AQHA' : null;
    }

    /** One row per lot number (a lot printed twice keeps the first), or per name for the flyers. */
    private static function unique(array $lots): array
    {
        $seen = [];
        foreach ($lots as $l) {
            $seen[$l['lot'] ?? 'n:'.ShowRecord::key($l['name'])] ??= $l;
        }

        return array_values($seen);
    }

    private static function tidy(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['’', '‘'], "'", $s)));
    }
}
