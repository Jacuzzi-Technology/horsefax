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
    public static function isClassPage(string $html): bool
    {
        return (bool) preg_match('/Results for Class\s+\S+\s+go\s+\S+/i', $html);
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
        if (preg_match('/Results for Class\s+([A-Za-z0-9]+)\s+go\s+([A-Za-z0-9]+)/i', $html, $m)) {
            [$out['class_code'], $out['round']] = [$m[1], $m[2]];
        }
        if (preg_match('#(\d{2}/\d{2}/\d{4})\s*-\s*(\d{2}/\d{2}/\d{4})#', $html, $m)) {
            $out['dates'] = $m[1].' – '.$m[2];
        }

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $text = preg_replace('/\s+/', ' ', $dom->textContent ?? '');
        // The class heading is the last "Class <code> <name>" before the results table's header.
        $end = preg_match('/Place\s*BackNo/i', $text, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : false;
        if ($out['class_code'] && $end !== false) {
            $before = substr($text, 0, $end);
            $marker = 'Class '.$out['class_code'].' ';
            $pos = strripos($before, $marker);
            if ($pos !== false) {
                $out['class_name'] = trim(substr($before, $pos + strlen($marker)));
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
                $placing = isset($cols['place']) ? ($cells[$cols['place']] ?? null) : null;
                $money = isset($cols['earnings']) ? ($cells[$cols['earnings']] ?? '') : '';
                $money = preg_replace('/[^0-9.]/', '', $money);
                $out['rows'][] = [
                    'placing' => $placing !== '' ? $placing : null,
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
