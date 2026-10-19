<?php

namespace App\Harvest;

use Illuminate\Support\Str;

/**
 * A&M Show Services (showmypony.com) class results, from `pdftotext -layout`. The show office's software prints:
 *
 *   OVRHA AUG 2025 Results
 *   Results for ROOKIE (5300) on 8-23-2025
 *   Place Entry Score Run Off Payout   Horse/Owner(s)              Rider/Affil
 *     1    326  70.5          43.13    SURPRIZE N SPARKLE (604001) RIDER NAME (502924)
 *                                      1) OWNER NAME (502924)
 *
 * Only the horse is kept: its name, NRHA horse number, placing, score and payout. Owner lines ("1) ...") and the
 * rider column are never read into the result.
 */
class AmShowsParser
{
    /** @return list<array{event:?string, class_name:string, class_code:?string, date:?string, rows:list<array{placing:string, horse:string, number:?string, score:?string, payout_cents:?int}>}> */
    public static function results(string $text): array
    {
        $event = preg_match('/^\s*(.+?)\s+Results\s*$/mi', $text, $m) && ! str_starts_with(strtolower(trim($m[1])), 'results for') ? trim($m[1]) : null;
        $out = [];
        $current = null;
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/Results for\s+(.+?)(?:\s+\((\w+)\))?\s+on\s+(\d{1,2})-(\d{1,2})-(\d{4})/i', $line, $h)) {
                if ($current) {
                    $out[] = $current;
                }
                $current = ['event' => $event, 'class_name' => self::title(trim($h[1])), 'class_code' => $h[2] ?: null,
                    'date' => sprintf('%04d-%02d-%02d', $h[5], $h[3], $h[4]), 'rows' => []];
                continue;
            }
            if (! $current) {
                continue;
            }
            // place, entry, score, [run-off], payout, horse (number)
            if (preg_match('/^\s*(\d{1,3}T?)\s+(\d{1,5})\s+(\d{1,3}(?:\.\d+)?)\s+(?:(\d{1,3}(?:\.\d+)?)\s+)?([\d,]+\.\d{2})\s+(\S.*?)\s+\((\d{3,8})\)/', $line, $r)) {
                $horse = trim(preg_replace('/\s+/', ' ', $r[6]));
                if ($horse === '' || preg_match('/^\d+\)/', $horse)) {
                    continue;
                }
                $current['rows'][] = [
                    'placing' => $r[1], 'horse' => self::title($horse), 'number' => $r[7],
                    'score' => (float) $r[3] > 0 ? $r[3] : null,
                    'payout_cents' => (int) round((float) str_replace(',', '', $r[5]) * 100) ?: null,
                ];
            }
        }
        if ($current) {
            $out[] = $current;
        }

        return $out;
    }

    /** "OVRHA AUG 2025" -> "OVRHA Aug 2025": association initials stay capitals. */
    public static function eventName(string $s): string
    {
        return preg_replace_callback('/\b(\w*rha|nrbc|nrha|\d+x)\b/i', fn ($m) => strtoupper($m[1]), self::title(trim($s)));
    }

    /** "SURPRIZE N SPARKLE" -> "Surprize N Sparkle"; mixed case is left as printed. */
    public static function title(string $s): string
    {
        return $s === mb_strtoupper($s) ? Str::title(mb_strtolower($s)) : $s;
    }
}
