<?php

namespace App\Harvest;

/**
 * HorseWeb.com pages. Listing pages (/horses/N/): one row per ad with name, headline, status tag, color, breed,
 * gender, age, state and asking price. Ad pages (/horse-xxxxxx/): the labelled fields (Name, Breed, Gender, Color,
 * Registry, Reg Number, Height, Foal Date, Ad Status, Price). Facts only: the description, seller, phone and photos
 * are never returned.
 */
class HorseWebParser
{
    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

    /** @return list<array{ad_id:string, name:?string, title:string, tag:?string, color:?string, breed:?string, sex:?string, age:?int, state:?string, price_cents:?int}> */
    public static function listing(string $html): array
    {
        $out = [];
        foreach (preg_split('/(?=<div class="li" )/', $html) as $p) {
            if (! preg_match('#href="/horse-([0-9a-z]{6})/"#', $p, $id) || ! str_starts_with($p, '<div class="li"')) {
                continue;
            }
            $cell = fn (string $class) => preg_match('#<div class="[^"]*\b'.$class.'\b[^"]*">(.*?)</div>#s', $p, $m) ? self::clean($m[1]) : null;
            $age = $cell('age');
            $out[] = [
                'ad_id' => $id[1], 'name' => self::value($cell('name')), 'title' => (string) (preg_match('#<div class="title"><a[^>]*>(.*?)</a>#s', $p, $t) ? self::clean($t[1]) : ''),
                'tag' => preg_match('#data-status="([^"]+)"#', $p, $s) ? $s[1] : null,
                'color' => self::value($cell('color')), 'breed' => self::value($cell('breed')), 'sex' => self::value($cell('gender')),
                'age' => $age !== null && ctype_digit($age) ? (int) $age : null,
                'state' => preg_match('#<div class="tablet location c">([A-Z]{2})</div>#', $p, $st) ? $st[1] : null,
                'price_cents' => self::price($cell('price')),
            ];
        }

        return $out;
    }

    /** The labelled fields of an ad page. */
    public static function ad(string $html): array
    {
        $text = self::clean(preg_replace('#<(br|/div|/span|/li|/dt|/dd|/td|/tr|/p|/h[1-6])\b[^>]*>#i', "\n", $html), true);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        $f = [];
        foreach (['Name', 'Breed', 'Gender', 'Color', 'Registry', 'Reg Number', 'Height', 'Foal Date', 'Ad Status', 'Price'] as $label) {
            $i = array_search($label, $lines, true);
            $v = $i !== false ? ($lines[$i + 1] ?? null) : null;
            $f[$label] = in_array($v, ['Breed', 'Gender', 'Color', 'Registry', 'Reg Number', 'Height', 'Foal Date', 'Location Map', 'Ad Status', 'Price', 'Temperament'], true) ? null : self::value($v);
        }
        $foal = $f['Foal Date'] && preg_match('/(?:('.self::MONTHS.'),?\s+)?((?:19|20)\d\d)/', $f['Foal Date'], $m) ? $m : null;

        return [
            'name' => $f['Name'], 'breed' => $f['Breed'], 'sex' => $f['Gender'], 'color' => $f['Color'],
            'registry' => $f['Registry'], 'registration_number' => $f['Reg Number'] && preg_match('/\d/', $f['Reg Number']) ? $f['Reg Number'] : null,
            'foal_year' => $foal ? (int) $foal[2] : null, 'foal_month' => $foal && $foal[1] ? (int) date('n', strtotime($foal[1].' 1')) : null,
            'ad_status' => $f['Ad Status'], 'price_cents' => self::price($f['Price']),
        ];
    }

    public static function price(?string $v): ?int
    {
        return $v && preg_match('/\$\s*([\d,]+(?:\.\d\d)?)/', $v, $m) ? (int) round((float) str_replace(',', '', $m[1]) * 100) : null;
    }

    /** "NA", "—", "-" and empty are no value. */
    public static function value(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' || preg_match('/^(NA|N\/A|—|-+|none|unknown)$/i', $v) ? null : $v;
    }

    public static function clean(string $html, bool $keepLines = false): string
    {
        $s = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5);

        return trim($keepLines ? preg_replace('/[ \t]+/', ' ', $s) : preg_replace('/\s+/u', ' ', $s));
    }
}
