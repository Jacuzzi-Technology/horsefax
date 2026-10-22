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
        if (preg_match('/\b('.self::MONTHS.')\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(20\d\d)\b/', $head, $d)) {
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

        return array_values($lots);
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
