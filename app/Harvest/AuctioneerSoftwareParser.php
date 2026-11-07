<?php

namespace App\Harvest;

/**
 * Pages of auction sites run on AuctioneerSoftware (auctioneersoftware.com): the server-rendered lot list
 * (/auctions/{id}-{slug}?pageSize=125&page=N), the per-auction sitemap (/sitemap/auction/{id}) and a lot page.
 * Reads facts only. Consignor, trainer, fitter, phone, email, location, bidder and the "Story"/description text are
 * never returned.
 */
class AuctioneerSoftwareParser
{
    /** Dynamic fields that are never kept (people, contact details, prose). */
    public const DROP = '/^(consign|trainer|fitted|phone|email|e-mail|location|story|description|for more info|contact|owner|seller|breeder|teams with|drives with|ela soundness|soundness|video|buyer|bidder)/i';

    /** @return list<array{lot_id:string, lot:string, title:string, url:?string, fields:array<string,string>, state:?string, bidder_label:?string, amount_cents:?int, bids:?int, sold_tag:bool, reserve_not_met:bool}> */
    public static function lots(string $html): array
    {
        $out = [];
        $parts = preg_split('/(?=<div class="lot [^"]*"\s+data-lotid=")/', $html);
        foreach ($parts as $p) {
            if (! preg_match('/^<div class="lot [^"]*"\s+data-lotid="(\d+)"\s+data-lotnumber="([^"]*)"/', $p, $m)) {
                continue;
            }
            $title = preg_match('#<span class="title">(.*?)</span>#s', $p, $t) ? self::clean($t[1]) : '';
            $fields = [];
            preg_match_all('#<span class="field-label">(.*?)</span>\s*<span class="field-separator">.*?</span>\s*<span class="field-value">(.*?)</span>#s', $p, $f, PREG_SET_ORDER);
            foreach ($f as [, $k, $v]) {
                $k = rtrim(self::clean($k), ': ');
                $v = self::clean($v);
                if ($k !== '' && $v !== '' && ! preg_match(self::DROP, $k)) {
                    $fields[$k] = $v;
                }
            }
            $amount = preg_match('#<div class="winning-bid-amount">\s*\$?([\d,]+(?:\.\d\d)?)#', $p, $a) ? (int) round(((float) str_replace(',', '', $a[1])) * 100) : null;
            $out[] = [
                'lot_id' => $m[1], 'lot' => trim(html_entity_decode($m[2])), 'title' => $title,
                'url' => preg_match('#<a class="enterLot" href="([^"]+)"#', $p, $u) ? html_entity_decode($u[1]) : null,
                'fields' => $fields,
                // Some finished lots carry no countdown, only "Bidding complete".
                'state' => preg_match('#class="countdown (\w+)"#', $p, $s) ? $s[1] : (str_contains($p, '>Bidding complete<') ? 'completed' : null),
                'bidder_label' => preg_match('#<strong class="winning-bidder-label">(.*?)</strong>#s', $p, $b) ? rtrim(self::clean($b[1]), ': ') : null,
                'amount_cents' => $amount,
                'bids' => preg_match('#<strong>Bids:</strong>\s*<span>(\d+)</span>#', $p, $n) ? (int) $n[1] : null,
                'sold_tag' => (bool) preg_match('#class="imageTag lotImageTag">\s*SOLD#i', $p),
                'reserve_not_met' => str_contains($p, 'class="notMet"') || ($s[1] ?? '') === 'completedReserveNotMet',
            ];
        }

        return $out;
    }

    /** Lot ids listed in /sitemap/auction/{id} (the full list; the lot-list page only renders what pageSize allows). */
    public static function sitemapLots(string $text): array
    {
        preg_match_all('#/auctions/\d+/lot/(\d+)#', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** "Start Time: 10/8/2026 2:00:00 PM" on a lot page -> 2026-10-08. */
    public static function startDate(string $html, bool $dayFirst = false): ?string
    {
        $t = self::clean($html);
        if (preg_match('#(?:Start|End|Closing) Time:\s*(\d{1,2})/(\d{1,2})/(\d{4})#', $t, $m)) {
            [$month, $day] = $dayFirst ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]]; // Irish sites print 20/10/2026
            if (checkdate($month, $day, (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $month, $day);
            }
        }

        return null;
    }

    public static function pageTitle(string $html): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#s', $html, $m) ? self::clean($m[1]) : null;
    }

    /**
     * sold / high_bid / offered / catalog for a lot, and the price that goes with it. A high bid is never
     * upgraded to a sale: a sale needs the site's SOLD tag or its "Winning Bidder" label (the precedent Richard set for
     * Platinum's winning bids, Oct 18 2026), with bids placed. A price shown with no bids is a starting or buy-now price
     * and is never kept, and no bids online never becomes "no sale" (the horse may have sold in the ring).
     *
     * @return array{0:string, 1:?int, 2:?string} [status, price_cents, note]
     */
    public static function result(array $lot): array
    {
        if (! str_starts_with((string) $lot['state'], 'completed')) {
            return ['catalog', null, null];
        }
        $bid = ($lot['bids'] ?? 0) > 0 && $lot['amount_cents'] > 0 ? $lot['amount_cents'] : null;
        if ($bid && $lot['reserve_not_met']) {
            return ['high_bid', $bid, 'Reserve not met'];
        }
        if ($bid && ($lot['sold_tag'] || strcasecmp((string) $lot['bidder_label'], 'Winning Bidder') === 0)) {
            return ['sold', $bid, $lot['sold_tag'] ? 'Marked SOLD by the sale' : 'Winning bid as reported by the sale'];
        }
        if ($bid) {
            return ['high_bid', $bid, 'High bid; the sale did not mark it sold'];
        }
        if ($lot['sold_tag']) {
            return ['offered', null, 'Marked SOLD by the sale; no price published'];
        }

        // No bids online is not "no sale": many of these sales sell in the ring, and the online page shows no ring result.
        return ['offered', null, 'Offered; no bids recorded online and no result published'];
    }

    /**
     * Identity facts from the title and fields: name, foal_year, sex, color, breed, registry, registered, sire, dam.
     * Titles vary by sale: "NAME | 2018 AQHA Mare | Ranch Horse", "Turbo - 1yr - 34 Inch - Colt",
     * "Renegade, a team of 6 & 8 y/o Percheron geldings".
     */
    public static function horse(array $lot, ?int $saleYear): array
    {
        $f = array_change_key_case($lot['fields']);
        $get = function (string ...$keys) use ($f) {
            foreach ($f as $k => $v) {
                foreach ($keys as $want) {
                    if (rtrim($k, ': ?') === $want) {
                        return $v;
                    }
                }
            }

            return null;
        };
        $title = $lot['title'];
        // "NAME | 2018 AQHA Mare", "NAME- 2015 APHA Gelding- Ranch Horse", "Crystal – 15 year old Mare", "Renegade, a team of…"
        $segments = array_map('trim', preg_split('/\s+\|\s+|\s*[–—]\s*|\s*-\s+|\s*-\s*(?=\d)|,\s+|\s+&\s+(?=(?:filly|colt|foal)\b)/iu', $title));
        $field = $get('horse name', 'name', 'registered name');
        $name = self::nameOnly($field ?? $segments[0]);
        if ($field === null && self::describes($name)) {
            $name = ''; // the title describes the horse ("2022 Filly by …", "Appaloosa Mare 12 year old Roan"): no name to keep
        }
        $desc = count($segments) > 1 ? implode(' ', array_slice($segments, 1)) : $title;
        $regText = trim(($get('breed', 'registry', 'registration') ?? '').' '.$desc);

        $registry = self::registry($get('registry', 'breed', 'registered with') ?? '') ?? self::registry($desc);
        $registered = null;
        if (($r = $get('registered')) !== null) {
            $registered = (bool) preg_match('/^(y|yes|true)/i', $r);
        }
        if (preg_match('/\bgrade\b|not registered|unreg/i', $regText)) {
            $registered = false;
            $registry = null;
        } elseif ($registry || preg_match('/\breg(\.|istered)\s/i', $title)) {
            $registered = $registered ?? true;
        }

        $year = null;
        if (($y = $get('year', 'foal year', 'year foaled', 'birth year')) && preg_match('/\b(19[6-9]\d|20[0-3]\d)\b/', $y, $m)) {
            $year = (int) $m[1];
        } elseif (($d = $get('foal date', 'foaled', 'dob', 'date of birth')) && preg_match('#\b\d{1,2}/\d{1,2}/(\d{2}|\d{4})\b#', $d, $m)) {
            $year = strlen($m[1]) === 2 ? (int) $m[1] + ((int) $m[1] > (int) date('y') + 1 ? 1900 : 2000) : (int) $m[1];
        } elseif (preg_match('/\b(19[6-9]\d|20[0-3]\d)\b/', $desc, $m)) {
            $year = (int) $m[1];
        } elseif ($saleYear) {
            // An age (horses turn a year older on Jan 1): "8yr", "6 y/o", Age: 12. A team's two ages ("6 & 8") are skipped.
            $age = $get('age');
            if ($age !== null && preg_match('/^\s*(\d{1,2})\s*(yrs?|y\/o|years?( old)?)?\s*$/i', $age, $m)) {
                $year = $saleYear - (int) $m[1];
            } elseif ($age === null && preg_match('/(?<![\d&] )\b(\d{1,2})\s*(?:-\s*)?(?:yr|y\/o|year)s?\b(?!\s*&)/i', $title, $m) && ! preg_match('/\d\s*&\s*\d/', $title)) {
                $year = $saleYear - (int) $m[1];
            } elseif (preg_match('/\b(yearling|yrlg)\b/i', $age.' '.$title)) {
                $year = $saleYear - 1;
            }
        }

        $sex = self::sex($get('sex', 'gender') ?? $desc) ?? self::sex($title);
        $breed = $get('breed');
        if ($breed && (self::registry($breed) || preg_match('/grade|not registered/i', $breed))) {
            $breed = self::registry($breed) ? (self::BREEDS[self::registry($breed)] ?? null) : null;
        }
        $breed ??= $registry ? (self::BREEDS[$registry] ?? null) : self::breedFrom($desc);

        return [
            'name' => $name, 'foal_year' => $year && $year > 1960 && $year <= ($saleYear ?? (int) date('Y')) ? $year : null, 'sex' => $sex,
            'color' => self::cap(preg_split('/\s+with\s+|[,.;(]/i', (string) $get('color', 'colour'))[0]), // markings are description, not color
            'breed' => $breed ? self::cap(preg_replace('/\s*-\s*not registered/i', '', $breed)) : null,
            'registry' => $registry, 'registered' => $registered,
            'sire' => self::cap($get('sire', 'sired by')), 'dam' => self::cap($get('dam', 'out of')),
        ];
    }

    private const REGISTRIES = ['AQHA' => 'AQHA', 'APHA' => 'APHA', 'APHC' => 'ApHC', 'PTHA' => 'PtHA', 'PHBA' => 'PHBA', 'ABRA' => 'ABRA', 'IBHA' => 'IBHA',
        'AHA' => 'AHA', 'AMHA' => 'AMHA', 'TWHBEA' => 'TWHBEA', 'MFTHBA' => 'MFTHBA', 'RMHA' => 'RMHA', 'KMSHA' => 'KMSHA', 'NSH' => 'NSH', 'FPZV' => 'FPZV', 'KFPS' => 'KFPS', 'GVHS' => 'GVHS', 'JC' => 'JC', 'USTA' => 'USTA'];

    private const BREEDS = ['AQHA' => 'Quarter Horse', 'APHA' => 'Paint Horse', 'ApHC' => 'Appaloosa', 'PtHA' => 'Pinto', 'PHBA' => 'Palomino', 'ABRA' => 'Buckskin', 'IBHA' => 'Buckskin',
        'AHA' => 'Arabian', 'AMHA' => 'Morgan', 'TWHBEA' => 'Tennessee Walking Horse', 'MFTHBA' => 'Missouri Fox Trotter', 'RMHA' => 'Rocky Mountain Horse', 'KFPS' => 'Friesian', 'GVHS' => 'Gypsy Vanner', 'JC' => 'Thoroughbred', 'USTA' => 'Standardbred'];

    /** A name as printed, without quotes, a barn name ("Sandee Aka “Shorty”") or a sale note "(Sold)". */
    public static function nameOnly(string $name): string
    {
        $name = trim(preg_replace('/^["“”\']+|["“”\']+$/u', '', trim($name)));
        $name = trim(preg_split('/\s+(?:aka|a\.k\.a\.)\s+|\s+[“"]/iu', $name)[0]);
        $name = trim(preg_replace('/\s*\([^)]*\)/', '', $name)); // "(Sold)", "(AKA …)", "(Sire x Dam by …)"
        $name = trim(preg_replace('/\s+\d+\s*(?:yrs?|y\/o|years?(?: old)?)\.?$/i', '', $name)); // "Miss Skip N Ladd 23yr"
        $name = trim(preg_replace('/(?:\s+(?:AQHA|APHA|ApHC|PtHA|grade|reg\.?))*\s+(?:stud|stallion|mare|gelding|colt|filly)$/i', '', $name)); // "Kings Oakie Star Stud"

        return trim($name, " -–—:,");
    }

    /** Text that describes a horse rather than naming it: a year first, an age, or a sex word. */
    public static function describes(string $name): bool
    {
        return (bool) preg_match('/^(19|20)\d\d\b|\b\d+\s*(?:yrs?|years?|y\/o)\b|\b(?:mares?|geldings?|stallions?|stud|fill(?:y|ies)|colts?)\b|\bteam of\b/i', $name);
    }

    public static function registry(string $text): ?string
    {
        if (preg_match('/\b('.implode('|', array_keys(self::REGISTRIES)).')\b/i', $text, $m)) {
            return self::REGISTRIES[strtoupper($m[1])];
        }

        return null;
    }

    private static function breedFrom(string $desc): ?string
    {
        return preg_match('/\b(Quarter Horse|Paint|Appaloosa|Percheron|Belgian|Clydesdale|Shire|Haflinger|Halflinger|Fjord|Friesian|Morgan|Standardbred|Thoroughbred|Arabian|Gypsy Vanner|Gypsy|Draft Cross|Spotted Draft|Welsh|Shetland|Miniature|Hackney|Saddlebred|Tennessee Walk\w*|Fox ?Trotter|Mustang)\b/i', $desc, $m) ? ucwords(strtolower($m[1])) : null;
    }

    public static function sex(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/\bgeld(ing)?s?\b|\bgld\b/i', $text) => 'gelding',
            (bool) preg_match('/\bmares?\b/i', $text) => 'mare',
            (bool) preg_match('/\b(stallion|stud)\b/i', $text) => 'stallion',
            (bool) preg_match('/\bfill(y|ies)\b/i', $text) => 'filly',
            (bool) preg_match('/\bcolts?\b/i', $text) => 'colt',
            default => null,
        };
    }

    /** A lot that isn't a horse for sale: practice bidding, sponsor tiles (lot 0, 0A…), tack and equipment, mules and donkeys. */
    public static function notAHorse(array $lot, array $horse): bool
    {
        $t = $lot['title'].' '.implode(' ', $lot['fields']);

        return $horse['name'] === '' || preg_match('/^(n\/?a|tbd|tba|none|unnamed|no name|-+)$/i', $horse['name'])
            || preg_match('/^0/', $lot['lot']) || preg_match('/practice|sponsor|donation|stallion service|breeding to|semen|\bsaddle\b|harness|\bcart\b|wagon|buggy|carriage|trailer|\btack\b|sleigh|equipment/i', $lot['title'])
            || preg_match('/\b(mule|donkey|burro|jenny|jack)s?\b/i', $t);
    }

    private static function cap(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' || preg_match('/^(n\/?a|unknown|-+)$/i', $v) ? null : NrchaHarvester::display($v);
    }

    public static function clean(string $html): string
    {
        $s = preg_replace('/<!--.*?-->/s', '', $html);
        $s = preg_replace('/<[^>]+>/', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5)));
    }
}
