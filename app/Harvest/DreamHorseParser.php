<?php

namespace App\Harvest;

use Illuminate\Support\Carbon;

/**
 * DreamHorse.com pages. A list page (/s/, /gs/, /cgs/, /bcgs/ ... facet pages) says how many ads match in an HTML
 * comment ("total_rows: 81") and links the first 40. An ad page (/ad/<id>.html) prints its facts as horseLabel /
 * horseData table pairs, twice (phone and desktop layouts), plus a 4-generation pedigree table.
 *
 * Facts only (Oct 22 2026): the seller's description, temperament score, skills list, photos, videos, phone and
 * email are never read. The seller's display name is read for the raw file only; it never goes into sale_lots.
 */
class DreamHorseParser
{
    public const NOT_FOUND = 'Ad Not Found';

    /** @return array{total:?int, ids:list<int>} */
    public static function listPage(string $html): array
    {
        preg_match_all('#href="(?:https://www\.dreamhorse\.com)?/ad/(\d+)[./]#', $html, $m);

        return [
            'total' => preg_match('/total_rows: (\d+)/', $html, $t) ? (int) $t[1] : null,
            'ids' => array_values(array_unique(array_map('intval', $m[1]))),
        ];
    }

    public static function gone(string $html): bool
    {
        return strlen($html) < 3000 && str_contains($html, self::NOT_FOUND);
    }

    /** The facts on one ad page, or null when the page isn't an ad. */
    public static function ad(string $html, int $id): ?array
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html); // DreamHorse leaves old layouts in comments
        if (! preg_match('#<td class="titlebar">(.*?)</td>#s', $html, $t)) {
            return null;
        }
        $title = self::text($t[1]);
        [$name, $barn] = self::names($title);

        $f = [];
        preg_match_all('#<td class="horseLabel"[^>]*>(.*?)</td>\s*<td class="horseData"[^>]*>(.*?)</td>#s', $html, $rows, PREG_SET_ORDER);
        foreach ($rows as $r) {
            $label = strtolower(self::text($r[1]));
            $f[$label] ??= preg_replace('#<p class="x-small">.*?</p>#s', '', $r[2]); // first layout wins; the second repeats it. Drop "Related Searches" links.
        }
        $v = fn (string $label) => isset($f[$label]) && ($s = self::text($f[$label])) !== '' ? $s : null;
        $yes = fn (string $label) => ($s = $v($label)) === null ? null : strcasecmp($s, 'yes') === 0;

        $loc = self::location($f['location'] ?? '');
        $foaled = $v('date foaled');
        $foalYear = $foaled && preg_match('/\b((?:19|20)\d\d)\b/', $foaled, $y) ? (int) $y[1] : null;
        $foalMonth = $foaled && preg_match('/\b(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\b/i', $foaled, $mo)
            ? (int) Carbon::parse('1 '.$mo[1].' 2000')->format('n') : null;
        [$price, $currency, $priceText] = self::price($f['asking price'] ?? null);
        $height = $v('height');
        $weight = $v('weight');

        $date = fn (string $re) => preg_match($re, $html, $d) ? Carbon::createFromFormat('!j-M-Y', $d[1])->toDateString() : null;
        $seller = preg_match('#<span class="nameFont">(.*?)</span>#s', $html, $s) ? self::text($s[1]) : null;

        return [
            'ad_id' => $id,
            'url' => 'https://www.dreamhorse.com/ad/'.$id.'.html',
            'title' => $title,
            'name' => $name,
            'barn_name' => $barn,
            'breed' => self::breeds($f['breed'] ?? $f['breeds'] ?? null), // "Breeds" when the seller gives two
            'registered' => $yes('registered'),
            'registry' => $v('reg. assn'),
            'registration_number' => self::regNumber($v('reg. number')),
            'sex' => $v('gender'),
            'color' => $v('color'),
            'markings' => $v('other color or markings'),
            'foal_year' => $foalYear,
            'foal_month' => $foalMonth,
            'height_hands' => $height && preg_match('/(\d{1,2}(?:\.[0-3])?)\s*hh/i', $height, $h) ? (float) $h[1] : null,
            'height_text' => $height,
            'weight_lbs' => $weight && preg_match('/([\d,]+)\s*pounds/i', $weight, $w) ? (int) str_replace(',', '', $w[1]) : null,
            'gaited' => $yes('gaited'),
            'warmblood' => $yes('warmblood'),
            'for_sale' => $yes('for sale'),
            'for_lease' => $yes('for lease'),
            'may_trade' => $yes('may trade'),
            'price_cents' => $price,
            'currency' => $currency,
            'price_text' => $priceText,
            'sold' => str_contains($html, 'This horse is SOLD.'),
            'city' => $loc['city'],
            'state' => $loc['state'],
            'zip' => $loc['zip'],
            'country' => $loc['country'],
            'seller_name' => $seller,
            'ad_created' => $date('/Ad Created:\s*(\d{1,2}-[A-Za-z]{3}-\d{4})/'),
            'ad_renewed' => $date('/Photo Added\/Renewed:\s*(\d{1,2}-[A-Za-z]{3}-\d{4})/'),
            'ad_expires' => $date('/Photos Expire\s*(\d{1,2}-[A-Za-z]{3}-\d{4})/'), // photo ads; free text ads print no expiry
            'pedigree' => self::pedigree($html),
        ];
    }

    /**
     * A pedigree name as a seller typed it, cut back to the horse's name. Sellers add achievements, registration
     * numbers and notes to the chart cells: "Kickoff Time LTE 50k and 200 AQHA Points" -> "Kickoff Time",
     * "Instagrand by Into Mischief" -> "Instagrand", "Frenchy Baby AQHA #5609162" -> "Frenchy Baby". A lowercase " by "
     * is a seller's note; "Zipped By Chocolate" and "IMPRESSED BY MY HEART" are names and stay. Null when nothing
     * name-like is left. The raw file keeps the cell as published; this is applied when lots are loaded.
     */
    public static function pedigreeName(?string $s): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        $s = preg_replace('/^(sire|dam|by)\s*:?\s+/i', '', $s);
        $s = preg_split('/\s+by\s+(?=[A-Z])|[(),;:!•|\/]|\s[-–—]\s|\$|#/u', $s)[0]; // case-sensitive "by": see above
        // Cut at the first word where a note starts: a registry or title followed by more, earnings, a year, a
        // registration number, or a color followed by test results. A single trailing word ("Miss Winner",
        // "Black Gold", "A Grand In Cash") is part of the name.
        $note = '/^(?:(?:AQHA|APHA|ApHC|AHR|AHA|NRHA|NCHA|NRCHA|NSHA|NSBA|PtHA|ABRA|PHBA|AMHR|AMHA|TWHBEA|KWPN|PE|LTE|ROM|HOF|FEI)\b'
            .'|SI\s*\d|CCI\d|(?:19|20)\d\d\b|[A-Z]{0,4}\d{4,}|\d+(?:\.\d+)?\s*k\b|\d+(?:,\d{3})+|6\s*Panel|N\/N'
            .'|(?:World|Champion\S*|Champ|Reserve|Winner|Earner|Earnings?|Money|Points?|pts|Producer|Offspring|Sire|Son|Daughter|Stakes|Multi\S*|Futurity|Derby|Superior|Pending|Hall|Proven|Stb|Ster|Pref\S*)\s+\S'
            .'|(?:Black|Grey|Gray|Bay|Sorrel|Chestnut|Palomino|Buckskin|Dun|Roan|Overo|Tobiano|Pinto)\b.*(?:\d|N\/N|Panel|\bpref|\bster))/iu';
        $words = explode(' ', $s);
        for ($k = 1; $k < count($words); $k++) {
            if (preg_match($note, implode(' ', array_slice($words, $k)))) {
                $s = implode(' ', array_slice($words, 0, $k));
                break;
            }
        }
        $s = trim($s, " .-*'\"");
        if ($s === '' || preg_match('/\d{4,}|^\W+$/u', $s) || mb_strlen($s) > 40 || count(explode(' ', $s)) > 6) {
            return null;
        }

        return $s;
    }

    /** "BELL'S MILLIE OF THE RIDGE (Lady)" -> ["BELL'S MILLIE OF THE RIDGE", "Lady"]. */
    public static function names(string $title): array
    {
        $title = str_replace(['’', '‘'], "'", $title);
        if (preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $title, $m) && trim($m[1]) !== '') {
            return [trim($m[1]), trim($m[2]) ?: null];
        }

        return [trim($title), null];
    }

    /** "Friesian Blood Horse<br>Percheron" -> "Friesian Blood Horse / Percheron". */
    public static function breeds(?string $cell): ?string
    {
        $parts = array_filter(array_map(fn ($b) => self::text($b), preg_split('#<br\s*/?>#i', (string) $cell)));

        return $parts ? implode(' / ', $parts) : null;
    }

    /** "Pending", "N/A", "none" and the like aren't numbers. */
    public static function regNumber(?string $s): ?string
    {
        $s = trim((string) $s);

        return $s !== '' && preg_match('/\d/', $s) && strlen($s) <= 40 ? $s : null;
    }

    /** @return array{0:?int, 1:?string, 2:?string} cents, currency, the text as shown */
    public static function price(?string $cell): array
    {
        if ($cell === null) {
            return [null, null, null];
        }
        $text = self::text($cell);
        $currency = preg_match('/\b(USD|CAD|EUR|GBP|AUD|MXN)\b/', $text, $c) ? $c[1] : null;
        if (preg_match('/\$\s*([\d,]+(?:\.\d\d)?)/', $text, $p)) {
            $cents = (int) round((float) str_replace(',', '', $p[1]) * 100);

            return [$cents > 0 ? $cents : null, $currency ?? 'USD', $text];
        }

        return [null, $currency, $text ?: null];
    }

    /** @return array{city:?string, state:?string, zip:?string, country:?string} */
    public static function location(string $cell): array
    {
        $out = ['city' => null, 'state' => null, 'zip' => null, 'country' => null];
        if (preg_match('#<small>(.*?)</small>#s', $cell, $c)) {
            $out['country'] = self::text($c[1]) ?: null;
        }
        if (! preg_match('#<a href="[^"]*/zip/([^/"]+)/horses-for-sale-in-[^"]*">(.*?)</a>#s', $cell, $a)) {
            $first = trim(self::text(explode('<br', $cell)[0]));
            $out['city'] = $first !== '' ? $first : null;

            return $out;
        }
        $out['zip'] = $a[1];
        $text = trim(preg_replace('/\s*'.preg_quote($a[1], '/').'\s*$/', '', self::text($a[2])));
        foreach (self::regions() as $region) {
            if (preg_match('/^(.*?)\s*\b'.preg_quote($region, '/').'$/i', $text, $m)) {
                $out['state'] = $region;
                $out['city'] = trim($m[1]) ?: null;

                return $out;
            }
        }
        $out['city'] = $text ?: null;

        return $out;
    }

    /**
     * The pedigree table, by position (s, d, ss, sd, ds, dd, sss ...). Cells come in pre-order, and the rowspan
     * gives the generation (8 = parents, 4 = grandparents, 2 and 1 for the 3rd and 4th).
     *
     * @return array<string, string>
     */
    public static function pedigree(string $html): array
    {
        $start = strpos($html, 'Pedigree for');
        if ($start === false) {
            return [];
        }
        $table = substr($html, $start, (strpos($html, '</table>', $start) ?: strlen($html)) - $start);
        preg_match_all('#<td rowspan="(\d+)"[^>]*class="(sire|dam)"[^>]*>(.*?)</td>#s', $table, $cells, PREG_SET_ORDER);
        $gen = ['8' => 1, '4' => 2, '2' => 3, '1' => 4];
        $path = '';
        $out = [];
        foreach ($cells as $cell) {
            $g = $gen[$cell[1]] ?? null;
            if ($g === null) {
                continue;
            }
            $path = substr($path, 0, $g - 1).($cell[2] === 'sire' ? 's' : 'd');
            $name = trim(preg_replace('/^(Sire|Dam):\s*/i', '', self::text($cell[3])));
            if ($name !== '' && strlen($path) === $g) {
                $out[$path] = $name;
            }
        }

        return $out;
    }

    /** US states, DC and the Canadian provinces, longest first so "West Virginia" wins over "Virginia". */
    public static function regions(): array
    {
        static $list = null;
        if ($list === null) {
            $list = ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'District of Columbia',
                'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland',
                'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey',
                'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island',
                'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin',
                'Wyoming', 'Alberta', 'British Columbia', 'Manitoba', 'New Brunswick', 'Newfoundland', 'Nova Scotia', 'Ontario',
                'Prince Edward Island', 'Quebec', 'Saskatchewan', 'Yukon', 'Northwest Territories', 'Nunavut', 'Puerto Rico'];
            usort($list, fn ($a, $b) => strlen($b) <=> strlen($a));
        }

        return $list;
    }

    public static function text(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br />', '<br>'], ' ', $s)), ENT_QUOTES | ENT_HTML5)), " \u{a0}");
    }
}
