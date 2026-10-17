<?php

namespace App\Harvest;

/**
 * Reads the All American Quarter Horse Congress results pages (the same show-office layout 1996–2025):
 * index pages link onward; class pages ("Results for Class 1341 go 1") hold a table of
 * Place · BackNo · Earnings · Horse Name · Owner(s) · Exhibitor. Only the horse's name and its result are returned:
 * owner and exhibitor columns are deliberately ignored.
 */
final class CongressParser
{
    /** "Results for Class 1341 go 1" (2001 on) or "Results for Class1053 1st Go" (1990s). */
    private const TITLE = '/Results for Class\s*([A-Za-z0-9]+)\s+(?:go\s+([A-Za-z0-9]+)|([A-Za-z0-9]+)\s+Go\b)/i';

    public static function isClassPage(string $html): bool
    {
        return (bool) preg_match(self::TITLE, $html);
    }

    /** Links on an index page, absolute. */
    public static function links(string $html, string $baseUrl): array
    {
        preg_match_all('/(?:href|src)\s*=\s*"([^"#]+)"/i', $html, $m);
        $out = [];
        foreach ($m[1] as $href) {
            $abs = self::absolute(trim($href), $baseUrl);
            if ($abs) {
                $out[] = $abs;
            }
        }

        return array_values(array_unique($out));
    }

    public static function absolute(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
            return null;
        }
        $p = parse_url($base);
        $root = $p['scheme'].'://'.$p['host'];
        if (str_starts_with($href, '/')) {
            return $root.$href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
        $path = $dir.$href;
        // Resolve ./ and ../
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.') {
                $parts[] = $seg;
            }
        }

        return $root.implode('/', $parts);
    }

    /**
     * @return array{class_code: ?string, round: ?string, class_name: ?string, dates: ?string, rows: list<array{placing: ?string, horse: string, earnings_cents: ?int}>}
     */
    public static function classPage(string $html): array
    {
        $out = ['class_code' => null, 'round' => null, 'class_name' => null, 'dates' => null, 'rows' => []];
        if (preg_match(self::TITLE, $html, $m)) {
            $out['class_code'] = $m[1];
            $round = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? null);
            // "1st" / "2nd" -> "1" / "2"; "Final"/"F" stay as they are.
            $out['round'] = $round !== null ? preg_replace('/^(\d+)(st|nd|rd|th)$/i', '$1', $round) : null;
        }
        if (preg_match('#(\d{2}/\d{2}/\d{4})\s*-\s*(\d{2}/\d{2}/\d{4})#', $html, $m)) {
            $out['dates'] = $m[1].' – '.$m[2];
        }

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $text = preg_replace('/\s+/', ' ', $dom->textContent ?? '');
        // The class heading is the last "Class <code> <name>" before the results table's header.
        $end = preg_match('/Place\s*Back\s*No/i', $text, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : false;
        if ($out['class_code'] && $end !== false) {
            $before = substr($text, 0, $end);
            if (preg_match_all('/Class:?\s*'.preg_quote($out['class_code'], '/').'\s+/i', $before, $all, PREG_OFFSET_CAPTURE)) {
                $last = end($all[0]);
                $name = trim(substr($before, $last[1] + strlen($last[0])));
                // 1990s headings end with "Shown: 25" and repeat the go ("1st Go").
                $name = trim(preg_replace(['/\s*Shown:\s*\d+.*$/i', '/\s+\d+(st|nd|rd|th)\s+Go$/i'], '', $name));
                $out['class_name'] = $name !== '' ? $name : null;
            }
        }

        foreach ($dom->getElementsByTagName('table') as $table) {
            $cols = null;
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if (in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                        $cells[] = trim(preg_replace('/\s+/', ' ', html_entity_decode($td->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    }
                }
                if ($cols === null) {
                    $lower = array_map('strtolower', $cells);
                    if (in_array('horse name', $lower, true)) {
                        $cols = array_flip($lower);
                    }
                    continue;
                }
                $horse = $cells[$cols['horse name']] ?? '';
                if ($horse === '' || strlen($horse) > 60) {
                    continue;
                }
                $placing = isset($cols['place']) ? trim((string) ($cells[$cols['place']] ?? ''), " \u{00A0}") : '';
                // Every result row has a placing. Rows without one are continuation rows (the 1990s layout puts the
                // rider's name under the horse-name column on a second row): never read them as horses.
                if ($placing === '' || ! preg_match('/^\d+[A-Za-z]?$/', $placing)) {
                    continue;
                }
                $money = isset($cols['earnings']) ? ($cells[$cols['earnings']] ?? '') : '';
                $money = preg_replace('/[^0-9.]/', '', $money);
                $out['rows'][] = [
                    'placing' => $placing,
                    'horse' => $horse,
                    'earnings_cents' => $money !== '' ? (int) round((float) $money * 100) : null,
                ];
            }
            if ($cols !== null) {
                break; // the results table
            }
        }

        return $out;
    }

    /**
     * Who sanctioned the class, and the registry we may infer from it. Conservative: only standard AQHA classes (or
     * classes named AQHA) imply AQHA registration. NRHA and NSBA are competition associations, not breed registries.
     *
     * @return array{0: string, 1: ?string} [organization, registry_inferred]
     */
    public static function sanction(string $className): array
    {
        $c = strtolower($className);

        return match (true) {
            str_contains($c, 'nrha') => ['NRHA', null],
            str_contains($c, 'nsba') => ['NSBA', null],
            str_contains($c, 'aqha') => ['AQHA', 'AQHA'],
            (bool) preg_match('/small fry|nyatt|heroes on horses|disabilit|ipra|side pot|jackpot|ranch sorting|team penning|cutting/', $c) => ['Congress special event', null],
            (bool) preg_match('/stakes|futurity|derby|maturity|sweepstake|all star|super |incentive|select|non[- ]?pro|classic|freestyle/', $c) => ['Congress stakes or futurity', null],
            default => ['AQHA', 'AQHA'],
        };
    }
}
