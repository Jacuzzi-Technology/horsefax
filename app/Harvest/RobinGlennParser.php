<?php

namespace App\Harvest;

/**
 * One Robin Glenn Pedigrees / QData online catalog page (catalogs.robinglenn.com/account/SalePreview?catID=N).
 *
 * The header block ("headerStuff") gives the sale name, LOCATION, SALE DATE (m/d/Y through m/d/Y), CONTACT and a
 * website. The table has one <tr class="odd|even"> per lot, cells in this order: Hip, Horse (linked to RG's own
 * pedigree page, whose hid= is Robin Glenn's horse id), Foal Date, Color, Sex, Sire, Dam, Dam's Sire, Consignor, id,
 * Notes, Photo, Video, Session, Results, Price, Buyer Name. Hidden cells are still read. Notes, Photo and Video are not
 * kept at all, and Consignor and Buyer Name only as yes/no (house rule: those names are never stored).
 */
class RobinGlennParser
{
    public const SEXES = ['G' => 'gelding', 'M' => 'mare', 'S' => 'stallion', 'H' => 'stallion', 'C' => 'colt', 'F' => 'filly', 'R' => 'ridgling'];

    /**
     * @return array{sale: array{name:?string, location:?string, date_from:?string, date_to:?string, contact:?string, website:?string},
     *               rows: list<array<string, mixed>>}
     */
    public static function parse(string $html): array
    {
        return ['sale' => self::header($html), 'rows' => self::rows($html)];
    }

    public static function header(string $html): array
    {
        $out = ['name' => null, 'location' => null, 'date_from' => null, 'date_to' => null, 'contact' => null, 'website' => null];
        if (! preg_match('#<table id="headerStuff">(.*?)</table>#s', $html, $m)) {
            return $out;
        }
        if (preg_match('#href="(?!mailto:)([^"]*\.[^"]+)"#', $m[1], $w)) {
            $out['website'] = self::clean($w[1]);
        }
        $lines = array_values(array_filter(array_map(fn ($l) => self::clean(strip_tags($l)), preg_split('#<br\s*/?>#i', $m[1])), fn ($l) => $l !== null));
        foreach ($lines as $l) {
            if (preg_match('/^LOCATION:\s*(.*)$/i', $l, $x)) {
                $out['location'] = self::clean($x[1]);
            } elseif (preg_match('/^SALE DATE:\s*(\d{1,2}\/\d{1,2}\/\d{4})(?:\s*through\s*(\d{1,2}\/\d{1,2}\/\d{4}))?/i', $l, $x)) {
                $out['date_from'] = self::date($x[1]);
                $out['date_to'] = isset($x[2]) ? self::date($x[2]) : $out['date_from'];
            } elseif (preg_match('/^CONTACT:\s*(.*)$/i', $l, $x)) {
                $out['contact'] = self::clean($x[1]);
            } elseif ($out['name'] === null && ! preg_match('/^(https?:|www\.)/i', $l) && ! str_contains($l, '.com')) {
                $out['name'] = $l;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $html): array
    {
        $rows = [];
        preg_match_all('#<tr class="(?:odd|even)">(.*?)</tr>#s', $html, $trs);
        foreach ($trs[1] as $tr) {
            preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $tr, $tds);
            $c = $tds[1];
            if (count($c) < 9) {
                continue;
            }
            $cell = fn (int $i) => isset($c[$i]) ? self::clean(strip_tags($c[$i])) : null;
            // The Horse cell holds the name twice (link + hidden copy); the first link carries Robin Glenn's horse id.
            $name = preg_match('#<a\b[^>]*>(.*?)</a>#s', $c[1], $a) ? self::clean(strip_tags($a[1])) : $cell(1);
            $hid = preg_match('#[?&]hid=(\d+)#', $c[1], $h) ? $h[1] : null;
            $foal = $cell(2);
            $results = $cell(14);
            $priceText = $cell(15);
            $rows[] = [
                'hip' => $cell(0), 'name' => $name, 'rg_horse_id' => $hid,
                'foal_date_text' => $foal, 'foal_year' => self::foalYear($foal), 'birth_date' => self::birthDate($foal),
                'color' => $cell(3), 'sex_code' => $cell(4), 'sex' => self::SEXES[strtoupper((string) $cell(4))] ?? null,
                'sire' => $cell(5), 'dam' => $cell(6), 'dam_sire' => $cell(7),
                'has_consignor' => $cell(8) !== null, 'session' => $cell(13), 'results' => $results,
                'price_text' => $priceText, 'price_cents' => self::cents($priceText), 'has_buyer' => $cell(16) !== null, // house rule: buyer and consignor names are never stored
            ];
        }

        return $rows;
    }

    /** "3/14/2019", "2019", "" -> 2019 / null. */
    public static function foalYear(?string $s): ?int
    {
        if ($s && preg_match('/\b((?:19|20)\d\d)\b/', $s, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public static function birthDate(?string $s): ?string
    {
        return $s && preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', trim($s)) ? self::date(trim($s)) : null;
    }

    public static function cents(?string $s): ?int
    {
        if (! $s || ! preg_match('/^\$?\s*([\d,]+(?:\.\d\d)?)$/', trim($s), $m)) {
            return null;
        }
        $v = (int) round((float) str_replace(',', '', $m[1]) * 100);

        return $v > 0 ? $v : null;
    }

    private static function date(string $mdy): ?string
    {
        [$m, $d, $y] = array_map('intval', explode('/', $mdy));

        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private static function clean(?string $s): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5)));

        return $s === '' ? null : $s;
    }
}
