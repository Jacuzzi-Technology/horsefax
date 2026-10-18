<?php

namespace App\Harvest;

use Illuminate\Support\Carbon;

/**
 * Platinum Equine Auction's expired-auctions listing (WooCommerce + Ultimate WordPress Auction): 20 cards a page, each
 * with the listing title, breed, registered yes/no, foal date, sex, color, the end time and either "Winning Bid: $x"
 * or "Reserve price Not met!". The seller's name, phone and location are on the card too and are not read.
 */
class PlatinumParser
{
    /** @return list<array{slug:string, url:string, title:string, name:?string, breed:?string, registered:?bool, foal_year:?int, sex:?string, color:?string, ended:?string, status:string, price_cents:?int}> */
    public static function cards(string $html): array
    {
        $out = [];
        foreach (array_slice(preg_split('/<li class="product\b/', $html), 1) as $card) {
            $card = explode('</li>', $card, 2)[0];
            if (! preg_match('#href="(https://platinumequineauction\.com/product/([^/"]+)/?)"#', $card, $u)) {
                continue;
            }
            $title = preg_match('/woocommerce-loop-product__title">([^<]*)</', $card, $t) ? self::text($t[1]) : '';
            $attr = [];
            preg_match_all('/<th[^>]*>([^<]*)<\/th>\s*<td[^>]*>(.*?)<\/td>/s', $card, $rows, PREG_SET_ORDER);
            foreach ($rows as $r) {
                $attr[strtolower(self::text($r[1]))] = self::text($r[2]);
            }
            $ended = preg_match('/Ending On:\s*<\/strong>\s*([A-Za-z]+ \d{1,2}, \d{4})/', $card, $e) ? Carbon::parse($e[1])->toDateString() : null;
            $price = null;
            if (preg_match('/winning_bid">.*?<bdi>.*?([\d,]+(?:\.\d\d)?)\s*<\/bdi>/s', $card, $p)) {
                $status = 'high_bid';
                $price = (int) round((float) str_replace(',', '', $p[1]) * 100);
            } elseif (str_contains($card, 'reserve_not_met')) {
                $status = 'no_sale';
            } else {
                $status = 'catalog';
            }
            $reg = $attr['registered'] ?? null;
            $out[] = [
                'slug' => $u[2], 'url' => $u[1], 'title' => $title, 'name' => self::name($title),
                'breed' => $attr['breed'] ?? null, 'registered' => $reg === null ? null : strcasecmp($reg, 'yes') === 0,
                'foal_year' => preg_match('/\b((?:19|20)\d\d)\b/', $attr['foal date'] ?? '', $y) ? (int) $y[1] : null,
                'sex' => $attr['sex'] ?? null, 'color' => $attr['color'] ?? null, 'ended' => $ended, 'status' => $status, 'price_cents' => $price,
            ];
        }

        return $out;
    }

    /**
     * The horse's name from a listing title: "SR Sheiks Classic (Chief)" -> "SR Sheiks Classic". Null for listings
     * that aren't one horse ("**2 In 1 Deal**", "pair", "package").
     */
    public static function name(string $title): ?string
    {
        if (preg_match('/2\s*in\s*1|\bpair\b|\bpackage\b|\bbundle\b|\s&\s|\band\b.*\bfoal\b/i', $title)) {
            return null;
        }
        $title = str_replace(['’', '‘'], "'", $title);
        $n = trim(preg_replace(['/\*\*.*?\*\*/u', '/\([^)]*\)/u', '/[“"][^”"]*[”"]/u', '/["“”]/u', '/\s+/u'], ['', '', '', '', ' '], $title), " -*");

        return $n === '' ? null : $n;
    }

    public static function lastPage(string $html): int
    {
        preg_match_all('#/expired-auctions/page/(\d+)/#', $html, $m);

        return $m[1] ? max(array_map('intval', $m[1])) : 1;
    }

    private static function text(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['<br />', '<br>'], ' ', $s)), ENT_QUOTES | ENT_HTML5)));
    }
}
