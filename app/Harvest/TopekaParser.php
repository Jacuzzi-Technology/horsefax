<?php

namespace App\Harvest;

/**
 * Topeka Livestock Auction PDFs, read from `pdftotext -layout` text. Catalog lots: name, registration number,
 * breed, sex, foaling date or age, color and a 3-generation pedigree. Market reports: lot, name and price only;
 * consignor, buyer and their towns are never returned.
 */
class TopekaParser
{
    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

    /** Which sale a PDF belongs to: key, name, date (from the file name and the cover or the report's season line). */
    public static function sale(string $file, string $text): array
    {
        $f = urldecode($file);
        $head = substr($text, 0, 20000);
        $season = preg_match('/\b(SPRING|FALL)(?: DRAFT)? ?(20\d\d)\b/', $head, $m) ? [ucfirst(strtolower($m[1])), (int) $m[2]] : null;
        if (preg_match('/(20\d\d)\s*_?(Spring|Fall)|(Spring|Fall)\s*_?(20\d\d)/i', $f, $m)) {
            $season = [ucfirst(strtolower($m[2] ?: $m[3])), (int) ($m[1] ?: $m[4])];
        } elseif (! $season && preg_match('/(Spring|Fall)[\s_]+(\d\d)(?!\d)/i', $f, $m)) {
            $season = [ucfirst(strtolower($m[1])), 2000 + (int) $m[2]]; // "Final Market Report Fall 22"
        }
        $year = $season[1] ?? (preg_match('/(20\d\d)/', $f, $y) ? (int) $y[1] : null);
        $date = null;
        if (preg_match('/\b('.self::MONTHS.')\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*[-–&]\s*\d{1,2}(?:st|nd|rd|th)?)?,?\s+(20\d\d)\b/', $head, $d)) { // "November 9th-10th, 2026"
            $date = date('Y-m-d', strtotime("$d[1] $d[2] $d[3]"));
            $year ??= (int) $d[3];
        }
        $type = match (true) {
            // The title line only: draft reports advertise the next Seymour sale.
            (bool) preg_match('/seymour/i', $f.' '.strtok(ltrim($head), "\n")) => ['seymour', 'Seymour Draft Horse Sale'],
            (bool) preg_match('/speed/i', $f) || (bool) preg_match('/Yearling Speed Sale/i', substr($head, 0, 3000)) => ['speed', 'Northern Indiana Yearling Speed Sale'],
            (bool) preg_match('/friesian/i', $f) => ['friesian', 'Friesian Sale'],
            (bool) preg_match('/clydesdale/i', $f) => ['clydesdale', 'Clydesdale Sale'],
            (bool) preg_match('/berkshire/i', $f) => ['berkshire', 'Berkshire Dispersal'],
            (bool) preg_match('/special/i', $f) => ['special-draft', 'Special Draft Horse Sale'],
            default => ['draft', 'Draft Horse Sale'],
        };
        $key = $type[0].'-'.($type[0] === 'draft' && $season ? strtolower($season[0]).'-' : '').$year;
        $name = 'Topeka Livestock Auction '.($type[0] === 'draft' && $season ? $season[0].' ' : '').($year ? $year.' ' : '').$type[1];

        return ['key' => $key, 'name' => $name, 'date' => $date, 'year' => $year, 'type' => $type[0], 'season' => $season[0] ?? null];
    }

    /** Catalog lots. */
    public static function catalog(string $text): array
    {
        $lines = preg_split('/\R/', str_replace("\f", "\n", $text));
        $lots = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (! preg_match('/^\s*Lot\s+(\d+[A-Z]?)\s+[—–-]\s*(.*?)\s{2,}([A-Z][a-z]+(?:\s[A-Z][a-z]+)*\s+(?:Mare|Gelding|Stallion|Filly|Colt|Mares|Geldings|Stud))\s*$/u', $lines[$i], $m)) {
                continue;
            }
            [$lot, $head, $breedSex] = [$m[1], trim($m[2]), $m[3]];
            $name = preg_match('/[“"]([^”"]+)[”"]/u', $head, $q) ? trim($q[1]) : null;
            if (! $name) {
                continue; // no name yet ("Pending", blank): nothing to identify the horse by
            }
            // A barn name is not the registered name: in brackets ("(AKA Trigger)"), after AKA, or nested in quotes (“CAPTAIN “ACE””).
            $name = trim(preg_replace('/\s*\((?:AKA|A\.K\.A\.)[^)]*\)\s*/i', ' ', $name));
            $name = trim(preg_split('/[“"]|\s+(?:AKA|A\.K\.A\.)\s+/iu', $name)[0]);
            $name = trim(preg_replace('/\s*\([^)]*\)$/', '', $name)); // a trailing nickname: “CEDARLANE ANTICIPATION (ANNIE)”
            $reg = preg_match('/[”"]\s+([A-Z]{0,3}\d{3,8}[A-Z]?)\b/u', $head, $r) ? $r[1] : null;
            $sex = AuctioneerSoftwareParser::sex($breedSex);
            $breed = trim(preg_replace('/\s+(Mares?|Geldings?|Stallion|Filly|Colt|Stud)$/', '', $breedSex));
            // Block: the lines up to the next lot or the consignor line.
            $block = [];
            for ($j = $i + 1; $j < $n && $j < $i + 40; $j++) {
                if (preg_match('/^\s*Lot\s+\d+/', $lines[$j]) || preg_match('/^\s*Consigned by/i', $lines[$j])) {
                    break;
                }
                $block[] = $lines[$j];
            }
            [$foalYear, $birth, $color] = self::foaled(implode("\n", array_slice($block, 0, 4)));
            $lots[$lot] = ['lot' => $lot, 'name' => NrchaHarvester::display(self::tidy($name)), 'registration_number' => $reg, 'breed' => $breed ?: null, 'sex' => $sex,
                'foal_year' => $foalYear, 'birth_date' => $birth, 'color' => $color, 'pedigree' => self::pedigree($block)];
        }

        $lots = array_values($lots);
        if (count($lots) < 5) {
            $lots = self::hashCatalog($text) ?: $lots;
        }
        if (count($lots) < 5) {
            $lots = self::speedCatalog($text) ?: $lots;
        }

        return $lots;
    }

    /**
     * The Seymour sale's layout: "#1 – Empire's Jordan   $___", then breed and sex, "Reg # 320521", "Foaled: April 27,
     * 2023", "Markings: Black, …" and a 2- or 3-generation chart. Consignor, breeder and remarks are never kept.
     */
    public static function hashCatalog(string $text): array
    {
        $lines = preg_split('/\R/', str_replace("\f", "\n", $text));
        $lots = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (! preg_match('/^\s*#(\d+[A-Z]?)\s+[–—-]\s+(.+?)(?:\s+\$_+)?\s*$/u', $lines[$i], $m)) {
                continue;
            }
            $block = [];
            for ($j = $i + 1; $j < $n && $j < $i + 30 && ! preg_match('/^\s*#\d+[A-Z]?\s+[–—-]\s/u', $lines[$j]); $j++) {
                $block[] = $lines[$j];
                if (preg_match('/^\s*Remarks:/', $lines[$j])) {
                    break; // the lot ends here; page footers and ads follow
                }
            }
            $head = implode("\n", array_slice($block, 0, 6));
            $breedSex = preg_match('/^\s{20,}([A-Z][a-z]+(?:\s[A-Z][a-z]+)*)\s+(Mare|Gelding|Stallion|Filly|Colt)\s*$/m', $head, $b) ? $b : null;
            $name = trim(preg_split('/[“"(]|\s+(?:AKA|A\.K\.A\.)\s+/iu', $m[2])[0]);
            if ($name === '' || str_contains($name, '$') || str_contains($name, '__') || ! $breedSex) {
                continue;
            }
            $foaled = preg_match('/Foaled:\s*(('.self::MONTHS.')\s+\d{1,2},?\s+(?:19|20)\d\d)/', $head, $f) ? $f[1] : null;
            $foalYear = $foaled ? (int) substr($foaled, -4) : (preg_match('/Foaled:\s*((?:19|20)\d\d)\b/', $head, $y) ? (int) $y[1] : null);
            $color = preg_match('/Markings:\s*([A-Za-z][A-Za-z -]{1,25}?)\s*[,.]/', $head, $c) ? ucfirst(strtolower(trim($c[1]))) : null;
            $lots[$m[1]] = ['lot' => $m[1], 'name' => NrchaHarvester::display(self::tidy($name)),
                'registration_number' => preg_match('/Reg\s*#\s*([A-Z0-9]*\d[A-Z0-9]*)\b/', $head, $r) && strlen($r[1]) >= 3 ? $r[1] : null, // not "Pending" or "Grade"
                'breed' => $breedSex[1], 'sex' => strtolower($breedSex[2]),
                'foal_year' => $foalYear, 'birth_date' => $foaled ? date('Y-m-d', strtotime($foaled)) : null,
                'color' => $color, 'pedigree' => self::chart($block)];
        }

        return array_values($lots);
    }

    /**
     * The Northern Indiana Yearling Speed Sale (Standardbreds): one page per hip — "1   SHOW IN BEST   Aisle",
     * "Black Filly - Foaled May 28, 2025 - Registration #61RA7", a 3-generation chart whose names carry race marks
     * ("Bettors Delight p, 3, 1:49.4 ...."), then race and produce records (never kept).
     */
    public static function speedCatalog(string $text): array
    {
        $lots = [];
        foreach (explode("\f", $text) as $page) {
            if (! preg_match('/^\s*(\w+)\s+(?:Filly|Colt|Gelding)\s+-\s+Foaled\s+(('.self::MONTHS.')\s+\d{1,2},?\s+(?:19|20)\d\d)\s+-\s+Registration\s*#\s*([A-Z0-9]+)/m', $page, $d, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $before = substr($page, 0, $d[0][1]);
            if (! preg_match_all('/^\s*(\d{1,3})\s{2,}([A-Z0-9][A-Z0-9 .\'’&-]+?)(?:\s{2,}Aisle)?\s*$/m', $before, $h, PREG_SET_ORDER)) {
                continue;
            }
            [$hip, $name] = [end($h)[1], trim(end($h)[2])];
            preg_match('/\s((?:Filly|Colt|Gelding))\s+-\s+Foaled/', $d[0][0], $sx);
            $chartLines = [];
            foreach (array_slice(preg_split('/\R/', substr($page, $d[0][1])), 1) as $line) {
                if (preg_match('/^\s*1st dam/i', $line)) {
                    break;
                }
                // Race marks and dot leaders are records, not names; the hip's own name printed beside the chart is not an ancestor.
                $line = preg_replace('/(\S)\s(?:[pPhH],?\s*\d|[pPhH],\s*T?\d|T\d:|\d,\s*T?\d:)[^\s]*(?:,\s*[T\d:.sQ]+)*\s*\.*/u', '$1  ', $line);
                $line = preg_replace('/\.{3,}/', '  ', $line);
                if (strcasecmp(trim($line), $name) === 0) {
                    continue;
                }
                $chartLines[] = $line;
            }
            $lots[$hip] = ['lot' => $hip, 'name' => NrchaHarvester::display(self::tidy($name)), 'registration_number' => $d[4][0], 'registry' => 'USTA',
                'breed' => 'Standardbred', 'sex' => strtolower($sx[1] ?? ''), 'foal_year' => (int) substr($d[2][0], -4),
                'birth_date' => date('Y-m-d', strtotime($d[2][0])), 'color' => ucfirst(strtolower($d[1][0])), 'pedigree' => self::chart($chartLines)];
        }

        return array_values($lots);
    }

    /**
     * A 2- or 3-generation chart from text columns: cells are grouped by where they start on the line, and a column
     * is used only with exactly the expected count (2, 4, 8). Labelled lines (Consigned by, Markings, Bred by, Reg #,
     * Foaled, Remarks) and towns are not names.
     */
    public static function chart(array $lines): array
    {
        $cells = [];
        foreach ($lines as $line) {
            // Blank (don't cut, or the columns shift) the Remarks and Bred by text, keeping a chart name printed beside it.
            $line = preg_replace_callback('/^\s*(?:Remarks|Bred by):.*?(?=\s{2,}\S|$)/u', fn ($m) => str_repeat(' ', mb_strlen($m[0])), rtrim($line));
            preg_match_all('/(?<=^|^\s|\s{2})(\S(?:.*?\S)?)(?=\s{2,}|$)/u', $line, $mm, PREG_OFFSET_CAPTURE);
            foreach ($mm[1] as [$v, $x]) {
                $v = trim($v);
                if (preg_match('/^(Consigned|Markings|Bred by|Foaled|Reg\s*#|Remarks|\$_|Aisle|\()/i', $v) || preg_match('/,\s*[A-Z]{2}\b|[a-z]{2}\.\s|^\d/', $v)
                    || preg_match('/^[A-Z][a-z]+(?:\s[A-Z][a-z]+)*\s+(?:Mare|Gelding|Stallion|Filly|Colt)$/', $v) // the lot's breed and sex
                    || ! preg_match('/^[A-Z][A-Za-z0-9.\'’&\- ]{1,44}$/u', $v)) {
                    continue;
                }
                $cells[] = [$v, $x];
            }
        }
        // Column starts: cluster the offsets, keep the (up to) three most used, left to right. A stray cell (a wrapped
        // name's second line) can't add a column; it only upsets the count of the column it lands in.
        $clusters = [];
        foreach (array_column($cells, 1) as $x) {
            foreach ($clusters as &$c) {
                if (abs($c['x'] - $x) <= 6) {
                    $c['n']++;
                    continue 2;
                }
            }
            unset($c);
            $clusters[] = ['x' => $x, 'n' => 1];
        }
        unset($c);
        usort($clusters, fn ($a, $b) => $b['n'] <=> $a['n']);
        $cols = array_column(array_filter(array_slice($clusters, 0, 3), fn ($c) => $c['n'] >= 2), 'x');
        sort($cols);
        if (count($cols) < 2) {
            return [];
        }
        $by = array_fill(0, count($cols), []);
        foreach ($cells as [$v, $x]) {
            foreach ($cols as $i => $c) {
                if (abs($c - $x) <= 6) {
                    $by[$i][] = NrchaHarvester::display(self::tidy($v));
                    break;
                }
            }
        }
        // Charts printed centred rather than aligned (the Speed sale): the rightmost column is the third generation and
        // the rest split into two generations at the widest gap between offsets. The counts must still be 2, 4 and 8.
        if (count($by[0]) !== 2) {
            $maxX = max(array_column($cells, 1));
            $third = array_values(array_filter($cells, fn ($c) => $c[1] >= $maxX - 6));
            $rest = array_values(array_filter($cells, fn ($c) => $c[1] < $maxX - 6));
            $xs = array_column($rest, 1);
            sort($xs);
            $cut = null;
            for ($i = 1, $gap = 0; $i < count($xs); $i++) {
                if ($xs[$i] - $xs[$i - 1] > $gap) {
                    [$gap, $cut] = [$xs[$i] - $xs[$i - 1], $xs[$i]];
                }
            }
            $name = fn ($c) => NrchaHarvester::display(self::tidy($c[0]));
            if ($cut !== null && count($third) === 8) {
                $by = [array_map($name, array_values(array_filter($rest, fn ($c) => $c[1] < $cut))), array_map($name, array_values(array_filter($rest, fn ($c) => $c[1] >= $cut))), array_map($name, $third)];
            }
        }
        $p = [];
        if (count($by[0]) === 2) {
            [$p['s'], $p['d']] = $by[0];
        }
        if (count($by[1]) === 4) {
            [$p['ss'], $p['sd'], $p['ds'], $p['dd']] = $by[1];
        }
        if (isset($by[2]) && count($by[2]) === 8) {
            [$p['sss'], $p['ssd'], $p['sds'], $p['sdd'], $p['dss'], $p['dsd'], $p['dds'], $p['ddd']] = $by[2];
        }

        return isset($p['s']) ? $p : [];
    }

    /** "Foaled: May 4, 2006. Blonde, stripe" / "Foaled: 6 year old. Blonde." -> [year, date, color]. Ages need the sale year: left to the caller as null. */
    public static function foaled(string $text): array
    {
        if (! preg_match('/Foaled:\s*(.+)/s', $text, $m)) {
            return [null, null, null];
        }
        $t = preg_replace('/\s+/', ' ', $m[1]);
        $year = null;
        $date = null;
        if (preg_match('/^('.self::MONTHS.')\s+(\d{1,2}),?\s+((?:19|20)\d\d)\.?/', $t, $d)) {
            $date = date('Y-m-d', strtotime("$d[1] $d[2] $d[3]"));
            $year = (int) $d[3];
            $t = substr($t, strlen($d[0]));
        } elseif (preg_match('/^(\d{1,2})-(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*-(\d{2})\b\.?/i', $t, $d)) {
            $y = (int) $d[3] + ((int) $d[3] > (int) date('y') ? 1900 : 2000); // "6-Jun-21" (2022 catalogs)
            $date = date('Y-m-d', strtotime("$d[1] $d[2] $y"));
            $year = $y;
            $t = substr($t, strlen($d[0]));
        } elseif (preg_match('/^((?:19|20)\d\d)\.?/', $t, $d)) {
            $year = (int) $d[1];
            $t = substr($t, strlen($d[0]));
        } else {
            $t = preg_replace('/^[^.]*\.\s*/', '', $t); // "6 year old." : an age, skipped
        }
        $color = preg_match('/^\s*([A-Za-z][A-Za-z -]{1,25}?)\s*[,.]/', $t, $c) ? ucfirst(strtolower(trim($c[1]))) : null;
        if ($color && preg_match('/bred by|in foal|broke|registered|eligible/i', $color)) {
            $color = null;
        }

        return [$year, $date, $color];
    }

    /**
     * The 3-generation chart printed under "Foaled": 14 names in three columns (2 + 4 + 8). Columns are found from
     * where each name starts on its line; a column is only used with exactly the expected count.
     */
    public static function pedigree(array $block): array
    {
        $cells = [];
        $started = false;
        foreach ($block as $line) {
            if (preg_match('/Foaled:|Bred by|^\s{0,2}[a-z]/i', $line) && ! $started) {
                continue;
            }
            preg_match_all('/(?<=^|\s{2})(\S(?:.*?\S)?)(?=\s{2,}|$)/u', rtrim($line), $mm, PREG_OFFSET_CAPTURE);
            $row = array_filter($mm[1], fn ($c) => preg_match('/^[A-Z0-9.\'’&-]/u', $c[0]) && mb_strlen($c[0]) <= 45);
            if (! $row) {
                if ($started && count($cells) >= 8) {
                    break;
                }
                continue;
            }
            if ($started === false && $mm[1] && (count($mm[1]) < 1 || $mm[1][0][1] < 20 && count($mm[1]) === 1 && str_word_count($mm[1][0][0]) > 6)) {
                continue; // prose
            }
            $started = true;
            foreach ($row as [$v, $x]) {
                $cells[] = [trim($v), $x];
            }
            if (count($cells) >= 14) {
                break;
            }
        }
        if (count($cells) < 6) {
            return [];
        }
        $xs = array_column($cells, 1);
        sort($xs);
        // Three column starts: cluster the x offsets.
        $cols = [];
        foreach ($xs as $x) {
            $k = null;
            foreach ($cols as $i => $c) {
                if (abs($c - $x) <= 6) {
                    $k = $i;
                }
            }
            $k === null ? $cols[] = $x : null;
        }
        if (count($cols) !== 3) {
            return [];
        }
        $by = [[], [], []];
        foreach ($cells as [$v, $x]) {
            foreach ($cols as $i => $c) {
                if (abs($c - $x) <= 6) {
                    $by[$i][] = self::tidy($v);
                    break;
                }
            }
        }
        $p = [];
        if (count($by[0]) === 2) {
            [$p['s'], $p['d']] = $by[0];
        }
        if (count($by[1]) === 4) {
            [$p['ss'], $p['sd'], $p['ds'], $p['dd']] = $by[1];
        }
        if (count($by[2]) === 8) {
            [$p['sss'], $p['ssd'], $p['sds'], $p['sdd'], $p['dss'], $p['dsd'], $p['dds'], $p['ddd']] = $by[2];
        }

        return array_map(fn ($v) => NrchaHarvester::display($v), $p);
    }

    /** Market-report rows: lot, name (when it can be told apart) and price. Never consignor, buyer or towns. */
    public static function report(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (! preg_match('/^\s*(\d{1,4}[A-Z]?)\s+(.+?)\s{2,}\$?\s*([\d,]{3,}(?:\.\d\d)?)\s*$/', $line, $m)) {
                continue;
            }
            $cols = preg_split('/\s{2,}/', trim($m[2]));
            $desc = null;
            foreach ($cols as $i => $c) {
                if (preg_match('/^(?:SUB\s+)?(?:YRLG|WEANLING|FOAL|\d{1,2}\s*YR)\b.*\b(MARE|GELD|STAL|STUD|FILLY|COLT)\b/i', $c)) {
                    $desc = $i;
                    break;
                }
            }
            $name = null;
            if ($desc !== null) {
                $before = $cols[$desc - 1] ?? null;
                $isTown = fn ($v) => $v === null || preg_match('/,\s*[A-Z]{2}\b|,\s*[A-Z][a-z]+$/', $v);
                $name = $isTown($before) ? ($cols[$desc + 1] ?? null) : $before;
            }
            $rows[] = ['lot' => $m[1], 'name' => $name ? trim($name) : null, 'desc' => $desc !== null ? $cols[$desc] : null,
                'price_cents' => (int) round((float) str_replace(',', '', $m[3]) * 100)];
        }

        return $rows;
    }

    /** lot => price from the market report(s) of the same sale (same key). */
    public static function pricesFor(array $sale, array $reports): array
    {
        $prices = [];
        foreach ($reports as $r) {
            if ($r['sale']['key'] !== $sale['key']) {
                continue;
            }
            foreach ($r['rows'] as $row) {
                if ($row['name'] && $row['price_cents'] >= 10000) {
                    $prices[$row['lot']] = $row + ['source' => $r['info']['url']];
                }
            }
        }

        return $prices;
    }

    public static function sameName(?string $a, ?string $b): bool
    {
        $k = fn ($s) => preg_replace('/[^A-Z0-9]/', '', strtoupper(str_replace(['’', '‘'], "'", (string) $s)));

        return $k($a) !== '' && $k($a) === $k($b);
    }

    private static function tidy(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['’', '‘'], "'", $s)));
    }
}
