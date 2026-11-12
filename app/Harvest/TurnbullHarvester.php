<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Great American Ranch & Trail Horse Sale (greatamericantrailhorsesale.com; Turnbull Equine Productions, Lexington VA),
 * found by Richard Oct 28 2026. robots.txt allows everything with Crawl-delay 60 (obeyed: one request a minute); no
 * terms of use (a copyright line, a privacy policy and the conditions of sale only); no bot wall.
 *
 * The catalog page embeds the year's catalog: a PDF on the site (2016-2024) or a Heyzine flipbook with download turned
 * on (2025-2026, the PDF on Heyzine's file server). Earlier years' links come from the Internet Archive's copies of the
 * catalog page. The 2018-2024 PDFs are scanned pages with no text and aren't read (OCR not built); 2022's is damaged.
 *
 * Each hip prints the consignor, the hip number, the registered name in large type, "2022 AQHA Grey Roan Gelding  14.3H"
 * and a three-generation tree laid out like Lolli's (LolliParser::pedigree). The "High Sellers" section of the sale page
 * gives the year's top prices by hip; a price is attached only when hip and name agree with the catalog.
 * Facts only: consignors, descriptions and contact details are never stored.
 */
class TurnbullHarvester
{
    public const BASE = 'https://greatamericantrailhorsesale.com';
    public const DELAY = 61.0;

    /** Catalog PDFs by sale year, as published (from the catalog page and its Internet Archive copies). */
    public const CATALOGS = [
        2016 => self::BASE.'/wp/wp-content/themes/docs/2016Catalog.pdf',
        2018 => self::BASE.'/wp/wp-content/themes/docs/2018Catalog.pdf',
        2019 => self::BASE.'/wp/wp-content/uploads/2019/04/2019Catalog.pdf',
        2021 => self::BASE.'/wp/wp-content/uploads/2021/03/catalogforwebpage-Final.pdf',
        2022 => self::BASE.'/wp/wp-content/uploads/2022/03/84689-Booklet-Proof-1.pdf',
        2024 => self::BASE.'/wp/wp-content/uploads/2024/03/2024Catalog_Final.pdf',
        2025 => 'https://cdnm.heyzine.com/files/uploaded/12413d00f066eb3e145c384d635c97f605d53e01.pdf',
        2026 => 'https://cdnm.heyzine.com/files/uploaded/9793eec0978069a143a118b86df6a5b1dd62b633.pdf',
    ];

    private const SEX = 'Gelding|Mare|Stallion|Stud|Colt|Filly|John|Molly|Jenny|Jack|Pony';
    private const COLORS = 'Sorrel|Bay|Black|Brown|Chestnut|Gray|Grey|Palomino|Buckskin|Dun|Red|Blue|Grullo|Grulla|Roan|Perlino|Cremello|Champagne|Smoky|Silver|Dapple|White|Tobiano|Overo|Tovero|Pinto|Paint|Leopard|Blanket|Spotted|Chocolate|Flaxen|Liver|Strawberry|Rose|Bay Roan|Dark';

    private DataSource $source;

    private float $lastRequest = 0;

    private string $dir;

    public function __construct(private ?\Closure $log = null, ?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/turnbull');
        @mkdir($this->dir.'/pdf', 0775, true);
        $this->source = DataSource::firstOrCreate(['key' => 'turnbull'], [
            'name' => 'Great American Ranch & Trail Horse Sale (Turnbull Equine Productions)', 'url' => self::BASE.'/catalog/', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 28 2026: robots.txt allows all, Crawl-delay 60 (obeyed); no terms of use (copyright line, privacy policy and conditions of sale only); no bot protection. Catalog PDFs (2025-26 via the Heyzine flipbook with download enabled) and the High Sellers list. Facts only: consignors, descriptions and contacts never stored. Found by Richard (Oct 28 2026).',
        ]);
    }

    /** Download the catalogs not already kept, a minute apart. */
    public function collect(): array
    {
        $out = ['downloaded' => 0, 'cached' => 0, 'unavailable' => 0];
        foreach (self::CATALOGS as $year => $url) {
            $path = $this->dir.'/pdf/gatrhs-'.$year.'.pdf';
            if (is_file($path) && str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF')) {
                $out['cached']++;
                continue;
            }
            $body = $this->get($url);
            if ($body === null || ! str_starts_with($body, '%PDF')) {
                $out['unavailable']++;
                continue;
            }
            file_put_contents($path, $body);
            $out['downloaded']++;
        }
        if ($html = $this->get(self::BASE.'/consignment-sale/')) {
            file_put_contents($this->dir.'/consignment-sale.html', $html);
        }

        return $out;
    }

    /** @return list<array{hip:string, name:string, foal_year:?int, registry:?string, breed:?string, registered:?bool, color:?string, sex:?string, pedigree:array}> */
    public static function catalog(string $pdf): array
    {
        $out = [];
        foreach (LolliParser::words($pdf) as $words) {
            if (! $words) {
                continue;
            }
            $segs = LolliParser::segments($words);
            $anchors = [];
            foreach ($segs as $i => $s) {
                if (! preg_match('/^((?:19|20)\d\d)\s+(.+?)\b('.self::SEX.')\b/i', $s['t'])) {
                    continue;
                }
                $name = $hip = null;
                foreach ($segs as $o) {
                    $dy = $s['y'] - $o['y'];
                    if ($dy > 2 && $dy < 30 && $o['h'] > $s['h'] && abs($o['x'] - $s['x']) < 80 && ! preg_match('/^\d+$/', $o['t']) && ($name === null || $o['y'] > $name['y'])) {
                        $name = $o;
                    }
                }
                if (! $name) {
                    continue;
                }
                foreach ($segs as $o) {
                    if (preg_match('/^\d{1,3}$/', $o['t']) && $o['x'] < $name['x'] && abs($o['yc'] - $name['yc']) < 15) {
                        $hip = $o['t'];
                    }
                }
                if ($hip !== null) {
                    $anchors[] = ['hip' => $hip, 'name' => $name, 'desc' => $s];
                }
            }
            foreach ($anchors as $k => $a) {
                $bottom = isset($anchors[$k + 1]) ? $anchors[$k + 1]['name']['y'] - 20 : INF;
                $region = array_values(array_filter($segs, fn ($s) => $s['y'] > $a['desc']['y'] + 1 && $s['y'] < $bottom));
                $name = LolliParser::cleanName($a['name']['t']);
                if ($name === '') {
                    continue;
                }
                $out[] = ['hip' => $a['hip'], 'name' => $name, 'pedigree' => LolliParser::pedigree($region, $a['desc']['y'] + 1, $name)] + self::describe($a['desc']['t']);
            }
        }

        return $out;
    }

    /** "2022 AQHA Grey Roan Gelding", "2012 Paint Gelding", "2008 Gypsy Vanner Gelding" -> foal year, registry, breed, color, sex. */
    public static function describe(string $d): array
    {
        preg_match('/^((?:19|20)\d\d)\s+(.*?)\s*\b('.self::SEX.')\b/i', trim($d), $m);
        $words = preg_split('/\s+/', trim($m[2] ?? ''));
        $reg = ['registry' => null, 'breed' => null, 'registered' => null];
        if ($words && ($r = LolliParser::registry(explode('/', $words[0])[0])) && $r['registry']) {
            $reg = $r;
            array_shift($words);
        }
        $color = array_values(array_filter($words, fn ($w) => preg_match('/^('.self::COLORS.')$/i', $w)));
        $breed = array_values(array_filter($words, fn ($w) => ! preg_match('/^('.self::COLORS.')$/i', $w) && $w !== ''));
        if (! $reg['breed'] && $breed) {
            $b = implode(' ', $breed);
            $reg['breed'] = preg_match('/^grade$/i', $b) ? null : ucwords(strtolower($b));
            $reg['registered'] = preg_match('/^grade$/i', $b) ? false : null;
        }
        $sex = strtolower($m[3] ?? '');

        return ['foal_year' => isset($m[1]) ? (int) $m[1] : null, 'color' => $color ? ucwords(strtolower(implode(' ', $color))) : null,
            'sex' => $sex === 'stud' ? 'stallion' : ($sex ?: null)] + $reg;
    }

    /**
     * The High Sellers section: "Hip #22 RR Papas Lil Ike “Marshal” ... $55,000" for the current year, and one
     * "2025 High Selling Horse / Hip #48 “R.J.” JOES LAST TIGER / Selling for $78,000" line per earlier year.
     *
     * @return array<int, array<string, array{name:string, price_cents:int}>> year => hip => name and price
     */
    public static function highSellers(string $html, int $currentYear): array
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</(p|div|li|h\d)>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        // Earlier years: "2025 High Selling Horse" then the hip line, then "Selling for $78,000".
        if (preg_match_all('/((?:19|20)\d\d) High Selling Horse\s+(.*?)\s+Selling for \$([\d,]+)/su', $text, $past, PREG_SET_ORDER)) {
            foreach ($past as [, $y, $line, $price]) {
                if (preg_match('/Hip\s*#\s*(\d+)\s*[-–]?\s*(.*)|(.*?)\s*[-–]\s*(?:Hip|Lot)\s*#\s*(\d+)/iu', trim($line), $h)) {
                    [$hip, $name] = ! empty($h[1]) ? [$h[1], $h[2]] : [$h[4], $h[3]];
                    $out[(int) $y][$hip] = ['name' => self::nameOf($name), 'price_cents' => (int) str_replace(',', '', $price) * 100];
                }
            }
        }
        // This year: each "Hip #22 Name" followed (before the next hip) by a price.
        if (preg_match('/'.$currentYear.' Great American[^\n]*HIGH SELLERS(.*?)Past Top Selling Horses/s', $text, $cur)
            && preg_match_all('/Hip\s*#\s*(\d+)\s*([^\n$]*)\n(?:[^\n$]*\n){0,3}?\s*\$([\d,]+)/i', $cur[1], $rows, PREG_SET_ORDER)) {
            foreach ($rows as [, $hip, $name, $price]) {
                $out[$currentYear][$hip] = ['name' => self::nameOf($name), 'price_cents' => (int) str_replace(',', '', $price) * 100];
            }
        }

        return $out;
    }

    /** "RR Papas Lil Ike “Marshal”" -> "RR PAPAS LIL IKE"; "“R.J.” JOES LAST TIGER" -> "JOES LAST TIGER"; "ALMOST SOBERR- Chocolate Chip" -> "ALMOST SOBERR". */
    private static function nameOf(string $n): string
    {
        $n = preg_replace('/\s*-\s+.*$/u', '', trim($n));

        return LolliParser::cleanName($n);
    }

    public function load(): array
    {
        $out = ['lots' => 0, 'pedigrees' => 0, 'sold' => 0, 'offered' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        $html = is_file($this->dir.'/consignment-sale.html') ? file_get_contents($this->dir.'/consignment-sale.html') : '';
        $prices = $html ? self::highSellers($html, (int) max(array_keys(self::CATALOGS))) : [];
        foreach (self::CATALOGS as $year => $url) {
            $path = $this->dir.'/pdf/gatrhs-'.$year.'.pdf';
            if (! is_file($path)) {
                continue;
            }
            $hips = self::catalog(file_get_contents($path));
            $this->say($year.': '.count($hips).' hips');
            foreach ($hips as $h) {
                $p = $prices[$year][$h['hip']] ?? null;
                if ($p && ! TopekaParser::sameName($p['name'], $h['name'])) {
                    $p = null; // the list names a different horse at that hip
                }
                $lot = SaleLot::record($this->source->id, 'gatrhs:'.$year.':'.$h['hip'], [
                    'sale_name' => $year.' Great American Ranch & Trail Horse Sale', 'sale_date' => $year.'-01-01', 'date_precision' => 'year', 'lot' => $h['hip'],
                    'horse_name' => mb_substr($h['name'], 0, 255), 'registered' => $h['registered'], 'registry' => $h['registry'],
                    'foal_year' => $h['foal_year'], 'sex' => $h['sex'], 'color' => $h['color'], 'breed' => $h['breed'],
                    'sire_name' => $h['pedigree']['s'] ?? null, 'dam_name' => $h['pedigree']['d'] ?? null, 'dam_sire_name' => $h['pedigree']['ds'] ?? null, 'pedigree' => $h['pedigree'] ?: null,
                    'status' => $p ? 'sold' : 'offered', 'price_cents' => $p['price_cents'] ?? null, 'buyers_premium' => 'unknown', 'source_url' => $url,
                    'note' => $p ? 'Price from the sale\'s High Sellers list' : 'Catalogued for the sale; only the top prices are published',
                ]);
                $out['lots']++;
                $out['pedigrees'] += $h['pedigree'] ? 1 : 0;
                $out[$p ? 'sold' : 'offered']++;
                $out[RegistrationOnlyMatch::place($lot, 'Great American Ranch & Trail Horse Sale')]++;
            }
        }

        return $out;
    }

    private function get(string $url): ?string
    {
        $wait = (app()->runningUnitTests() ? 0 : self::DELAY) - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(180)->get($url);
        $this->say($r->status().' '.$url);
        if (in_array($r->status(), [403, 429], true)) {
            throw new HarvestBlocked("Blocked or rate-limited at $url (HTTP {$r->status()}): stopping. No workaround.");
        }

        return $r->successful() ? $r->body() : null;
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
