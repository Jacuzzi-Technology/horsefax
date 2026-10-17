<?php

namespace App\Harvest;

/**
 * Reads NRHA's public show results (nrha.com, no login, no terms of use posted; robots.txt allows admin-ajax):
 *  - year list: admin-ajax.php?action=rh_fetch_show_results&year=Y -> rows of dates · show id · name · city · state · country
 *  - show page: /show-results-detail/?id=SHOW -> links to /show-results-class-detail/?id=SHOW&class-code=CODE
 *  - class page: <h2>show</h2><div>1100 - Open</div><div>dates</div> + table Horse ("1 Dual Reality") · Rider · Owner · Earnings
 * Only the horse's name, placing and earnings are returned: rider and owner columns are deliberately ignored.
 */
final class NrhaParser
{
    /** @return list<array{show_id: string, name: string, dates: string, place: string}> */
    public static function yearList(string $html): array
    {
        $out = [];
        preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $rows);
        foreach ($rows[1] as $row) {
            preg_match_all('#<td[^>]*>(.*?)</td>#is', $row, $c);
            $cells = array_map(fn ($v) => trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $c[1]);
            if (count($cells) >= 3 && preg_match('/^\d{6,}$/', $cells[1])) {
                $out[] = ['show_id' => $cells[1], 'name' => $cells[2], 'dates' => $cells[0], 'place' => trim(implode(', ', array_filter(array_slice($cells, 3))))];
            }
        }

        return $out;
    }

    /** @return list<string> class codes on a show page */
    public static function classCodes(string $html): array
    {
        preg_match_all('#show-results-class-detail/\?id=\d+&(?:amp;)?class-code=([A-Za-z0-9]+)#', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return array{show: ?string, class_code: ?string, class_name: ?string, dates: ?string, rows: list<array{placing: string, horse: string, earnings_cents: ?int}>} */
    public static function classPage(string $html): array
    {
        $out = ['show' => null, 'class_code' => null, 'class_name' => null, 'dates' => null, 'rows' => []];
        $start = strpos($html, 'rh_show_results_detail_table');
        if ($start === false) {
            return $out;
        }
        $head = substr($html, max(0, $start - 1500), 1500);
        if (preg_match_all('#<h2>(.*?)</h2>#is', $head, $h)) {
            $out['show'] = trim(html_entity_decode(strip_tags(end($h[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('#<div>\s*([A-Za-z0-9]+)\s*-\s*(.*?)\s*</div>#is', $head, $m)) {
            [$out['class_code'], $out['class_name']] = [$m[1], trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
        }
        if (preg_match('#<div class="mb-4">(.*?)</div>#is', $head, $m)) {
            $out['dates'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $end = stripos($html, '</table>', $start);
        $table = substr($html, $start, $end === false ? 200000 : $end - $start);
        if (preg_match('#<tbody[^>]*>(.*)$#is', $table, $b)) {
            preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $b[1], $rows);
            foreach ($rows[1] as $row) {
                preg_match_all('#<td[^>]*>(.*?)</td>#is', $row, $c);
                if (count($c[1]) < 4) {
                    continue;
                }
                $first = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($c[1][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if (! preg_match('/^(\d+)\s+(.+)$/', $first, $pm)) {
                    continue; // unplaced or not a result row
                }
                $money = preg_replace('/[^0-9.]/', '', strip_tags(end($c[1])));
                $out['rows'][] = ['placing' => $pm[1], 'horse' => trim($pm[2]), 'earnings_cents' => $money !== '' ? (int) round((float) $money * 100) : null];
            }
        }

        return $out;
    }

    /** The part of a page worth keeping as evidence: the results section, not the site's navigation. */
    public static function evidence(string $html): string
    {
        $s = strpos($html, '<div id="post-');
        $e = strrpos($html, '</table>');
        if ($s !== false && $e !== false && $e > $s) {
            return substr($html, $s, $e - $s + 8);
        }

        return $html;
    }
}
