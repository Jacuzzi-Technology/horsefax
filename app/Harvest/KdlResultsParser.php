<?php

namespace App\Harvest;

/**
 * KDL Equestrian Service "Results by Class Report" (WSQHA's results PDFs), text from `pdftotext -layout`:
 *   Show #917 NW Emerald #1 / Monroe WA / 9/17/2026
 *   Showbill: 339 Class: AQHA 412001 RK Youth Showmanship at Halter          6 shown
 *                    Go: 1
 *   1           420      Leaguers Big Finale                 EXHIBITOR                   OWNER
 *                           AQHA - 5605881       2014      AQHA - 4650325 (exhibitor's number)   ...
 * Reads the horse's name, its registration number and foal year, and the placing. Exhibitor and owner names, their
 * association numbers and youth birthdates are on the same lines and are dropped here.
 */
class KdlResultsParser
{
    /** @return list<array{show_no:string, event:string, dates:?string, year:?int, organization:string, class_code:string, class_name:string, round:?string, placing:string, horse:string, registry:?string, registration_number:?string, foal_year:?int, earnings_cents:?int}> */
    public static function parse(string $text): array
    {
        $rows = [];
        $show = null;
        $class = null;
        $pending = null; // a placing still collecting its registration number, foal year and prize money
        $flush = function () use (&$pending, &$rows) {
            if ($pending) {
                $rows[] = $pending;
                $pending = null;
            }
        };
        $lines = preg_split('/\R/', $text);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*Show #\s*(\d+)\s+(.+?)\s*$/', $line, $m)) {
                $date = null;
                for ($j = $i + 1; $j <= $i + 3 && isset($lines[$j]); $j++) {
                    if (preg_match('/^\s*(\d{1,2}\/\d{1,2}\/(\d{4}))\s*$/', $lines[$j], $d)) {
                        $date = $d;
                        break;
                    }
                }
                if (! $show || $show['show_no'] !== $m[1]) {
                    $flush();
                    $show = ['show_no' => $m[1], 'event' => trim($m[2]), 'dates' => $date[1] ?? null, 'year' => isset($date[2]) ? (int) $date[2] : null];
                }
                continue;
            }
            if (preg_match('/^\s*Showbill:\s*\S+\s+Class:\s*([A-Z]{2,6})\s+([A-Z0-9-]+)\s+(.+?)(?:\s{2,}\d+\s+shown)?\s*$/', $line, $m)) {
                $flush();
                $class = ['organization' => $m[1], 'class_code' => $m[2], 'class_name' => trim($m[3]), 'round' => null];
                continue;
            }
            if ($class && preg_match('/^\s*Go:\s*(\S+)/', $line, $m)) {
                $class['round'] = $m[1] === '1' ? null : 'go '.$m[1];
                continue;
            }
            if (preg_match('/^\s*(1st Judge|Judge\(s\)|Place\s)/', $line)) {
                $flush();
                continue;
            }
            if ($show && $class && preg_match('/^\s*(\d{1,3})\s+\d{1,5}\s{2,}(\S.*)$/', $line, $m)) {
                $horse = preg_split('/\s{2,}/', trim($m[2]))[0];
                if (! preg_match('/[A-Za-z]/', $horse)) {
                    $flush();
                    continue; // judges' placing grid: numbers only
                }
                $flush();
                $pending = $show + $class + ['placing' => $m[1], 'horse' => trim($horse), 'registry' => null, 'registration_number' => null, 'foal_year' => null, 'earnings_cents' => null];
                continue;
            }
            if (! $pending) {
                continue;
            }
            // Lines under a placing: "AQHA - 5605881   2014   AQHA - 4650325 (the exhibitor's)", "$560.00   2019", "$280.00".
            if (preg_match('/^\s*\$([\d,]+(?:\.\d\d)?)/', $line, $m) && $pending['earnings_cents'] === null) {
                $pending['earnings_cents'] = (int) round((float) str_replace(',', '', $m[1]) * 100);
                $line = substr($line, strlen($m[0]));
            }
            if ($pending['registration_number'] === null && preg_match('/^\s*([A-Za-z]{2,6})\s*-\s*([A-Z0-9]+)\s+((?:19|20)\d\d)\b/', $line, $m)) {
                [$pending['registry'], $pending['registration_number'], $pending['foal_year']] = [strtoupper($m[1]), $m[2], (int) $m[3]];
            } elseif ($pending['foal_year'] === null && preg_match('/^\s*((?:19|20)\d\d)(\s|$)/', $line, $m)) {
                $pending['foal_year'] = (int) $m[1];
            }
        }
        $flush();

        return $rows;
    }
}
