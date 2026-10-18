<?php

namespace App\Harvest;

use Illuminate\Support\Carbon;

/**
 * Live Ag (bid.live-ag.com, Auctic platform) pages carry their data as Inertia JSON in the root element's data-page
 * attribute. An event page lists every lot with its status and price; a lot page adds the published identity
 * (Hip, Name, Reg#, Year, Sex, Color, Breed, Sire, Dam, Dam Sire). Only the horse and the sale result are read here:
 * winner, buyer, bidder, consignor, seller and agent fields are never taken.
 */
class LiveAgParser
{
    /** Horse sales share the platform with cattle sales: decided from the event's title. */
    public static function isHorseSale(string $title): bool
    {
        return (bool) preg_match('/horse|gelding|super[\s-]*sale|cowgirl|remuda|quarter|paint|filly|fillies|colt|stallion|mare\b|equine|best[\s-]of[\s-]the[\s-]west/i', $title)
            && ! preg_match('/\b(bulls?|angus|hereford|heifers?|cattle|charolais|females?|real[\s-]estate)\b/i', $title);
    }

    /** @return list<array{id:int, slug:string, url:string}> events in the sitemap */
    public static function sitemapEvents(string $xml): array
    {
        preg_match_all('#<loc>\s*(https://bid\.live-ag\.com/events/event/(\d+)/([^<\s]+))\s*</loc>#', $xml, $m, PREG_SET_ORDER);

        return array_values(array_map(fn ($x) => ['id' => (int) $x[2], 'slug' => $x[3], 'url' => $x[1]], $m));
    }

    public static function pageData(string $html): ?array
    {
        if (! preg_match('/data-page="([^"]*)"/', $html, $m)) {
            return null;
        }

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true)['props'] ?? null;
    }

    /** @return array{title:string, finished:bool, start:?string, lots:list<array>} */
    public static function event(array $props): array
    {
        $e = $props['event'] ?? [];
        $lots = [];
        foreach ($props['listings']['data'] ?? [] as $l) {
            if (($l['lot_number'] ?? null) === null || trim((string) ($l['title'] ?? '')) === '') {
                continue; // test bidding and placeholders
            }
            $lots[] = [
                'id' => (int) $l['id'], 'lot' => trim($l['lot_number'].($l['lot_number_suffix'] ?? '')),
                'title' => trim($l['title']), 'url' => 'https://bid.live-ag.com'.($l['url'] ?? '/events/listing/'.$l['id']),
                'status' => $l['status'] ?? null, 'end_time' => $l['end_time'] ?? null,
            ];
        }

        return [
            'title' => trim((string) ($e['title'] ?? 'Live Ag sale')),
            'finished' => in_array($e['status'] ?? null, ['archived', 'closed', 'completed'], true),
            'start' => $e['start_time'] ?? null,
            'lots' => $lots,
        ];
    }

    /** Published identity fields, keyed name, reg, year, sex, color, breed, sire, dam, dam_sire, hip. */
    public static function identity(array $props): array
    {
        $out = [];
        foreach (($props['dynamic_imports']['top'] ?? []) as $label => $value) {
            $k = preg_replace('/[^a-z#]/', '', strtolower((string) $label));
            $key = match ($k) {
                'name', 'horsename', 'registeredname' => 'name',
                'reg#', 'reg', 'registration', 'registration#', 'regno' => 'reg',
                'year', 'dob', 'foaled', 'foaldate', 'birthdate', 'yob' => 'year',
                'sex', 'gender' => 'sex', 'color', 'colour' => 'color', 'breed', 'registry' => 'breed',
                'sire' => 'sire', 'dam' => 'dam', 'damsire', 'damssire' => 'dam_sire', 'hipno', 'hip', 'lot' => 'hip',
                default => null, // agent, seller, consignor and anything else: not stored
            };
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($key && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** "2/9/24", "02/09/2024", "2024" -> [foal year, birth date or null]. */
    public static function birth(?string $v): array
    {
        $v = trim((string) $v);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/', $v, $m)) {
            $y = (int) $m[3];
            if ($y < 100) {
                $y += $y <= (int) now()->format('y') ? 2000 : 1900;
            }

            return checkdate((int) $m[1], (int) $m[2], $y) ? [$y, sprintf('%04d-%02d-%02d', $y, $m[1], $m[2])] : [$y, null];
        }

        return preg_match('/^(19|20)\d\d$/', $v) ? [(int) $v, null] : [null, null];
    }

    /**
     * What the sale reported for a lot. Kate's rule: only a lot the sale marks sold is a reported sale; a passed or
     * bought-back lot with a bid is a recorded high bid; a lot with no bid is no sale.
     *
     * @return array{status:string, price_cents:?int, note:?string, currency:string}
     */
    public static function result(array $props): array
    {
        $l = $props['listing'] ?? [];
        $meta = $l['meta'] ?? [];
        $hidePrice = ! empty($props['exclude_sale_prices_from_results']) || ! empty($l['exclude_sale_prices_from_results']) || ! empty($meta['exclude_sale_prices_from_results']);
        $cents = fn ($v) => $hidePrice || (float) $v <= 0 ? null : (int) round((float) $v * 100);
        $high = $cents($l['highest_bid_amount'] ?? $l['passed_price'] ?? 0);
        $currency = strtoupper((string) ($l['currency'] ?? 'USD')) ?: 'USD';
        $status = $l['status'] ?? '';

        if (in_array($status, ['withdrawn', 'canceled'], true)) {
            return ['status' => 'withdrawn', 'price_cents' => null, 'note' => null, 'currency' => $currency];
        }
        if (! empty($meta['is_buyback'])) {
            return ['status' => $high ? 'high_bid' : 'no_sale', 'price_cents' => $high, 'note' => 'Marked by the sale as bought back', 'currency' => $currency];
        }
        if (! empty($l['is_sold']) && empty($meta['is_nosale']) && in_array($status, ['sold', 'soldpostsale'], true)) {
            $sold = $cents($l['sold_price'] ?? 0);

            return ['status' => 'sold', 'price_cents' => $sold, 'note' => $status === 'soldpostsale' ? 'Sold after the sale' : null, 'currency' => $currency];
        }
        if ($status === 'passed' || ! empty($meta['is_nosale'])) {
            return ['status' => $high ? 'high_bid' : 'no_sale', 'price_cents' => $high, 'note' => $high ? 'Passed: no sale was confirmed' : null, 'currency' => $currency];
        }

        return ['status' => 'catalog', 'price_cents' => null, 'note' => null, 'currency' => $currency];
    }

    public static function saleDate(?string $endTime, ?string $fallback): ?string
    {
        $t = $endTime ?: $fallback;

        return $t ? Carbon::parse($t)->setTimezone('America/Denver')->toDateString() : null;
    }

    /** The registry a lot's Breed names, when it's a horse registry; null for anything else (cattle, unknown). */
    public static function registry(?string $breed): ?string
    {
        $b = strtoupper(trim((string) $breed));

        return match (true) {
            $b === '' => null,
            (bool) preg_match('/^(AQHA|QUARTER HORSE|QH)$/', $b) => 'AQHA',
            (bool) preg_match('/^(APHA|PAINT)/', $b) => 'APHA',
            (bool) preg_match('/^(APHC|APPALOOSA)/', $b) => 'ApHC',
            (bool) preg_match('/^(ABRA|BUCKSKIN)/', $b) => 'ABRA',
            (bool) preg_match('/^(PHBA|PALOMINO)/', $b) => 'PHBA',
            (bool) preg_match('/^(PTHA|PINTO)/', $b) => 'PtHA',
            (bool) preg_match('/^(ARHA|RANCH HORSE)/', $b) => 'ARHA',
            (bool) preg_match('/(GYPSY|GVHS)/', $b) => 'GVHS',
            default => null, // grade, mule, other breeds: the breed is kept as published, no registry assumed
        };
    }
}
