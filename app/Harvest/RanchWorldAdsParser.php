<?php

namespace App\Harvest;

/**
 * Reads RanchWorldAds.com (BosClassifieds 2) horse pages: the "Horses For Sale" index (cat_id=1) and single ads
 * (classified.php?listing=ID). Pages are ISO-8859-1. FACTS ONLY: the description is read just far enough to find a
 * 7-digit registration number (the number is kept, never the text); photos, video, phone numbers and the
 * description itself are never returned. RanchWorldAds' Site Rules take an exclusive license to ad text and images.
 */
class RanchWorldAdsParser
{
    /** The 14 cells of the ad's 3-generation pedigree table, in page order. */
    public const PEDIGREE = ['s', 'ss', 'sss', 'ssd', 'sd', 'sds', 'sdd', 'd', 'ds', 'dss', 'dsd', 'dd', 'dds', 'ddd'];

    /** Ad labels kept, mapped to field names. Anything else (Phone#, Description, ...) is ignored. */
    private const FIELDS = [
        'posted by' => 'poster', 'price' => 'price_text', 'city' => 'city', 'state' => 'state', 'sale name' => 'sale_name',
        'date foaled' => 'foaled', 'age' => 'age_text', 'breed' => 'breed', 'gender' => 'gender', 'height' => 'height_text',
        'weight' => 'weight_text', 'color' => 'color', 'registered with' => 'registry_text', 'disciplines' => 'disciplines',
    ];

    public static function utf8(string $html): string
    {
        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }

    /** A deleted or never-used listing id answers 200 with a tiny meta refresh to the home page. */
    public static function gone(string $html): bool
    {
        return strlen($html) < 1000 && stripos($html, 'refresh') !== false && ! str_contains($html, 'adBox');
    }

    /**
     * One index page: its rows (id, kind, title, sold, price line) and the last page number in the pagination.
     *
     * @return array{rows: list<array>, last_page: ?int}
     */
    public static function listPage(string $html): array
    {
        $html = self::utf8($html);
        // The list itself: "<td>[<div>]Kind: <a class=ad_link>Title</a> [<b>SOLD</b>]<br>snippet<br>price&nbsp;Video".
        preg_match_all('#<td>(?:<div style="text-decoration:none;">)?\s*([^<:]+):\s*<a href="[^"]*classified\.php\?listing=(\d+)" class="ad_link">(.*?)</a>(\s*<b>SOLD</b>)?\s*<br>.*?<br>(.*?)</(?:div|td)>#s', $html, $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as $r) {
            $id = (int) $r[2];
            $rows[$id] ??= [
                'id' => $id,
                'kind' => self::text($r[1]),
                'title' => self::text($r[3]),
                'sold' => $r[4] !== '',
                'price_text' => self::text(explode('&nbsp;', $r[5])[0]),
                'featured' => false,
            ];
        }
        // The featured strips repeated on every page: "<span class=ad_text>Kind<br><a class=ad_link>Title</a>".
        preg_match_all('#<span class="ad_text">([^<]+)<br><a href="[^"]*classified\.php\?listing=(\d+)" class="ad_link">(.*?)</a>#s', $html, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $rows[(int) $r[2]] ??= ['id' => (int) $r[2], 'kind' => self::text($r[1]), 'title' => self::text($r[3]), 'sold' => false, 'price_text' => null, 'featured' => true];
        }
        preg_match_all('#index\.php\?cat_id=1&(?:amp;)?pg=(\d+)#', $html, $p);

        return ['rows' => array_values($rows), 'last_page' => $p[1] ? max(array_map('intval', $p[1])) : null];
    }

    /** Facts from one ad page, or null when it isn't a horse ad (another category, or no ad box). */
    public static function ad(string $html, int $id): ?array
    {
        $html = self::utf8($html);
        if (! str_contains($html, 'id="adBox"')) {
            return null;
        }
        // The breadcrumb names the category: horses are cat_id=1.
        if (! preg_match('#class="menu_bar_link" href="[^"]*index\.php\?cat_id=1"#', $html)) {
            return ['ad_id' => $id, 'horse' => false, 'category' => preg_match('#cat_id=\d+">([^<]+)</a>#', $html, $c) ? self::text($c[1]) : null];
        }
        preg_match('#<td class="headtd">(.*?)</td>#s', $html, $h);
        $head = self::text($h[1] ?? '');
        $sold = (bool) preg_match('#<td class="headtd">.*?<b>\s*SOLD\s*</b>#s', $html) || preg_match('/\bSOLD\s*$/', $head);
        $head = trim(preg_replace('/\s*\bSOLD\s*$/', '', $head));
        [$kind, $title] = str_contains($head, ':') ? array_map('trim', explode(':', $head, 2)) : [null, $head];

        // The two fact columns: <b>Label:</b> value<br>, up to the photo block.
        $box = substr($html, strpos($html, 'id="adBox"'));
        $facts = substr($box, 0, ($p = stripos($box, 'id="imageViewer"')) !== false ? $p : (stripos($box, '<b>Description:') ?: 6000));
        $f = [];
        preg_match_all('#<b>([^<:]+):</b>(.*?)(?=<br|<b>|</td>)#s', $facts, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $label = strtolower(trim($r[1]));
            if (isset(self::FIELDS[$label])) {
                $v = self::text(preg_replace('#<a [^>]*class="small".*?</a>#s', '', $r[2]));
                $f[self::FIELDS[$label]] = $v === '' ? null : $v;
            }
        }

        $desc = '';
        if (($d = stripos($box, '<b>Description:</b>')) !== false) {
            $end = stripos($box, 'PEDIGREE', $d) ?: (stripos($box, 'visitors have viewed', $d) ?: $d + 20000);
            $desc = self::text(substr($box, $d, $end - $d));
        }
        $regs = array_values(array_unique(array_merge(self::regNumbers($title), self::regNumbers($desc))));
        $desc = null; // the text itself is never kept

        $pedigree = self::pedigree($box);
        $pedFrom = $pedigree ? 'chart' : null;
        if (! $pedigree && ($t = self::titlePedigree($title))) {
            [$pedigree, $pedFrom] = [$t, 'title'];
        }
        $poster = $f['poster'] ?? null;
        $year = self::year($f['foaled'] ?? null) ?? self::titleYear($title);
        $registry = self::registry($f['registry_text'] ?? null) ?? self::titleRegistry($title);
        $grade = (bool) preg_match('/\bGRADE\b/', $title) || preg_match('/^(grade|none|no|not registered|unregistered)$/i', (string) ($f['registry_text'] ?? ''));

        return [
            'ad_id' => $id,
            'horse' => true,
            'url' => RanchWorldAdsHarvester::adUrl($id),
            'kind' => $kind,
            'title' => $title,
            'name' => self::name($title),
            'sold' => $sold,
            'auction' => (bool) preg_match('/bid at auction/i', ($kind ?? '').' '.($f['price_text'] ?? '')),
            'price_text' => $f['price_text'] ?? null,
            'price_cents' => self::price($f['price_text'] ?? null),
            'sale_name' => preg_match('#^(https?://|www\.)#i', (string) ($f['sale_name'] ?? '')) ? null : ($f['sale_name'] ?? null), // some sellers paste a link
            'city' => $f['city'] ?? null,
            'state' => $f['state'] ?? null,
            'foal_year' => $year,
            'foaled_text' => $f['foaled'] ?? ($f['age_text'] ?? null),
            'breed' => $f['breed'] ?? null,
            'sex' => self::sex($f['gender'] ?? null),
            'gender_text' => $f['gender'] ?? null,
            'height_hands' => self::height($f['height_text'] ?? null),
            'height_text' => $f['height_text'] ?? null,
            'weight_lbs' => self::weight($f['weight_text'] ?? null),
            'color' => $f['color'] ?? null,
            'registry_text' => $f['registry_text'] ?? null,
            'registry' => $registry,
            'registered' => $registry ? true : ($grade ? false : null),
            'disciplines' => array_values(array_filter(array_map('trim', explode(',', (string) ($f['disciplines'] ?? ''))))),
            'poster' => $poster,
            'horsebid' => (bool) preg_match('/horsebid/i', (string) $poster),
            'posted' => preg_match('#Posted (\d{1,2})-(\d{1,2})-(\d{2,4})#', $box, $pd) ? self::date($pd) : null,
            'registration_number' => $regs[0] ?? null,
            'reg_numbers' => $regs,
            'pedigree' => $pedigree ?: null,
            'pedigree_from' => $pedFrom,
        ];
    }

    /** The 14-cell pedigree table, by position; empty when the ad has none (or it's blank). */
    public static function pedigree(string $html): array
    {
        if (($p = strpos($html, '>PEDIGREE<')) === false) {
            return [];
        }
        $block = substr($html, $p, (stripos($html, '</TABLE>', $p) ?: $p + 8000) - $p);
        preg_match_all('#<td[^>]*>(.*?)</td>#is', $block, $m);
        $cells = array_map(fn ($c) => self::horseName(preg_replace('/^(Sire|Dam):\s*/i', '', self::text($c))), $m[1]);
        if (count($cells) !== 14) {
            return [];
        }
        $out = array_filter(array_combine(self::PEDIGREE, $cells));

        return $out;
    }

    /** "NAME  2025 AQHA Chestnut Gelding x SIRE x SIRE'S SIRE out of DAM x DAM'S SIRE!" (sale-company titles). */
    public static function titlePedigree(string $title): array
    {
        if (! preg_match('/\sx\s+(.+?)\s+x\s+(.+?)\s+out of\s+(.+?)(?:\s+x\s+(.+?))?[!.]*$/i', $title, $m)) {
            return [];
        }

        return array_filter(['s' => self::horseName($m[1]), 'ss' => self::horseName($m[2]), 'd' => self::horseName($m[3]), 'ds' => self::horseName($m[4] ?? null)]);
    }

    /**
     * The horse's name from the ad title, when the title leads with one: "NAME  2025 AQHA ...", "Name - Gray Gelding",
     * "Miss N Traveler ~ Fancy Broke Ranch Gelding", or a short title that is just a name. Null when the title is a
     * description ("2021 AQHA RANCH/ROPE GELDING", "Great Moving Big 3 Year Old Gelding").
     */
    public static function name(string $title): ?string
    {
        $t = trim(preg_replace('/\s+[!.]+$/', '', $title));
        $cand = null;
        if (preg_match('/^(.+?)(?:\s{2,}|\s*-\s*)(?:19|20)\d{2}\b/u', $t, $m)) {
            $cand = $m[1];                                   // NAME  2025 ... / NAME- 2011 ...
        } elseif (preg_match('/^(.+?)\s+(?:-|–|—|~|\|)\s+/u', $t, $m)) {
            $cand = $m[1];                                   // Name - description
        } elseif (! preg_match('/[,!:]/', $t) && str_word_count($t) <= 4) {
            $cand = $t;                                      // the title is just a name
        }
        $cand = self::horseName($cand);
        if ($cand === null || mb_strlen($cand) > 40 || preg_match('/^\d/', $cand) || self::generic($cand)) {
            return null;
        }

        return $cand;
    }

    /** Words that make a title fragment a description rather than a name. */
    private static function generic(string $s): bool
    {
        return (bool) preg_match('/\b(horses?|geldings?|mares?|stallions?|colts?|fillys?|fillies|yr|yrs|years?|olds?|broke|prospects?|for sale|sale|aqha|apha|aphc|grade|registered|ranch|rope|roping|barrel|reining|cutting|trail|kids?|family|gentle|quarter horse|paint horse|pony|ponies|mule|donkey|team|pair|stud|reduced|price|wanted|lease)\b/i', $s);
    }

    /** A pedigree or title name: trimmed, barn name in quotes/parentheses dropped, blanks and "unknown" removed. */
    public static function horseName(?string $s): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        $s = trim(preg_replace('/\s*(\(.*?\)|"[^"]*"|“[^”]*”)\s*/u', ' ', $s));
        $s = trim($s, " \t-~,.*");
        if ($s === '' || preg_match('/^(unknown|unk|n\/?a|none|-+|\?+|grade|not known|tbd)$/i', $s)) {
            return null;
        }

        return mb_substr($s, 0, 120);
    }

    /** 7-digit numbers standing alone (AQHA-style registration numbers); never part of a phone number or price. */
    public static function regNumbers(string $text): array
    {
        preg_match_all('/(?<![\d\-.,$\/])\d{7}(?![\d\-.,\/]\d)/', $text, $m);

        return array_values(array_unique($m[0]));
    }

    public static function year(?string $s): ?int
    {
        if (preg_match('/\b(19[5-9]\d|20[0-3]\d)\b/', (string) $s, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#^\s*\d{1,2}[/-]\d{1,2}[/-](\d{2})\s*$#', (string) $s, $m)) {
            return 2000 + (int) $m[1] > (int) date('Y') ? 1900 + (int) $m[1] : 2000 + (int) $m[1];
        }

        return null;
    }

    private static function titleYear(string $title): ?int
    {
        return preg_match('/(?:^|\s)((?:19[89]|20[0-3])\d)\s+(?:AQHA|APHA|ApHC|APHC|GRADE|PtHA|ABRA|PHBA|NFQHA|ARHA)\b/', $title, $m) ? (int) $m[1] : null;
    }

    private static function titleRegistry(string $title): ?string
    {
        return preg_match('/\b(AQHA|APHA|ApHC|APHC|PtHA|ABRA|PHBA)\b/', $title, $m) ? LiveAgParser::registry($m[1]) : null;
    }

    public static function registry(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '' || preg_match('/^(n\/?a|none|no|other|unknown|grade|not registered|unregistered|-+)$/i', $s)) {
            return null;
        }

        return LiveAgParser::registry($s) ?? mb_substr($s, 0, 60);
    }

    public static function price(?string $s): ?int
    {
        if (! preg_match('/^\s*\$?\s*([\d,]+(?:\.\d{1,2})?)\s*$/', (string) $s, $m)) {
            return null; // "For Bid at Auction", "Call", "OBO" and the like
        }
        $v = (float) str_replace(',', '', $m[1]);

        return $v >= 100 && $v < 10_000_000 ? (int) round($v * 100) : null;
    }

    public static function sex(?string $s): ?string
    {
        $s = strtolower(trim((string) $s));

        return match (true) {
            str_contains($s, 'gelding') => 'gelding',
            str_contains($s, 'mare') => 'mare',
            str_contains($s, 'stallion') || str_contains($s, 'stud') => 'stallion',
            str_contains($s, 'colt') => 'colt',
            str_contains($s, 'filly') => 'filly',
            default => null,
        };
    }

    /** "15.2", "15.2H", "15'2", "15 hands": hands.inches as a decimal string. */
    public static function height(?string $s): ?string
    {
        if (preg_match('/\b(1[0-9]|[6-9])\s*(?:[.\'’,]\s*([0-3]))?\s*(?:h|hh|hands?)?\b/i', (string) $s, $m)) {
            return $m[1].'.'.($m[2] ?? '0');
        }

        return null;
    }

    public static function weight(?string $s): ?int
    {
        return preg_match('/(\d[\d,]{2,5})/', (string) $s, $m) && ($w = (int) str_replace(',', '', $m[1])) >= 200 && $w <= 3000 ? $w : null;
    }

    private static function date(array $m): ?string
    {
        // The site's own stamp at the end of the ad ("Posted 10-20-26"). Some older pages print years since 1900
        // ("07-07-118"); any date before the ads begin (2014) or after today is a misprint and is dropped.
        $y = (int) $m[3];
        $y = $y < 100 ? 2000 + $y : ($y < 1000 ? 1900 + $y : $y);
        if (! checkdate((int) $m[1], (int) $m[2], $y)) {
            return null;
        }
        $d = sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);

        return $d >= '2014-01-01' && $d <= date('Y-m-d') ? $d : null;
    }

    private static function text(string $html): string
    {
        $t = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
    }
}
