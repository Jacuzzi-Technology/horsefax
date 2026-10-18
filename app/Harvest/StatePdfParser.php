<?php

namespace App\Harvest;

/**
 * Readers for the two PDF layouts state Quarter Horse associations post (text from `pdftotext -layout`).
 * Both keep only the horse's name and its result: exhibitor, owner, member and judge names are dropped here.
 */
class StatePdfParser
{
    /**
     * Show-office "Publication Report" (Horse Show for Windows style; Nebraska QHA, Arizona QHA, Arkansas QHA):
     *   Show #84157 (JUDGE NAME): HALL OF FAME SHOW (5/14/2026-5/17/2026)
     *   18. AQHA 110949: L1 Ranch Trail Go: 1 - Shown: 15
     *   PlaceBack# Horse's Name   Exhibitor's Name   Owner's Name
     *      1   824   LOVIN HEART   EXHIBITOR   OWNER
     *
     * @return list<array{show_no:string, event:string, dates:?string, year:?int, organization:string, class_code:string, class_name:string, round:?string, placing:string, horse:string}>
     */
    public static function publicationReport(string $text): array
    {
        $rows = [];
        $show = null;
        $class = null;
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/Show #\s*(\d+)(?:\s*\([^)]*\))?:\s*(.+?)\s*\((\d{1,2}\/\d{1,2}\/(\d{4}))\s*-\s*(\d{1,2}\/\d{1,2}\/\d{4})\)/', $line, $m)) {
                $show = ['show_no' => $m[1], 'event' => trim($m[2]), 'dates' => $m[3].' – '.$m[5], 'year' => (int) $m[4]];
                continue;
            }
            if (preg_match('/^\s*\d+\.\s+([A-Z]{2,6})\s+([A-Z0-9-]+):\s+(.+?)\s+Go:\s*(\S+)\s+-\s+Shown:/', $line, $m)) {
                $class = ['organization' => $m[1], 'class_code' => $m[2], 'class_name' => trim($m[3]), 'round' => $m[4] === '1' ? null : 'go '.$m[4]];
                continue;
            }
            // Some offices print a Money column first: "$1750.00   1   606   HORSE".
            if (! $show || ! $class || ! preg_match('/^(\s*(?:\$([\d,]+\.\d\d))?\s*(\d{1,3})\s+\d+\s+)(\S.*)$/', $line, $m)) {
                continue;
            }
            $money = $m[2];
            $m = [$m[0], $m[1], $m[3], $m[4]];
            $horse = preg_split('/\s{2,}/', trim($m[3]))[0];
            // The name column is 20 characters wide: a 20-character name runs straight into the exhibitor's name.
            if (mb_strlen($horse) > 22) {
                $horse = rtrim(mb_substr($horse, 0, 20));
            }
            if ($horse === '') {
                continue;
            }
            $rows[] = $show + $class + ['placing' => (string) (int) $m[2], 'horse' => $horse]
                + ($money !== '' ? ['earnings_cents' => (int) round((float) str_replace(',', '', $money) * 100)] : []);
        }

        return $rows;
    }

    /**
     * AQHA affiliate "Member Standings" (Microsoft Access report; Missouri QHA): class header, then each horse with
     * the member's name, then one line per show with the AQHA show number, number shown, placing and points.
     *   105400   YEARLING MARES                                #Judges   Pts
     *        IMA SECRET DIVA            MEMBER NAME              13       16
     *             90745   OZQHA Spring Horse Show - Saturday    1    1   Yes   No    4
     *
     * @return list<array{show_no:string, event:string, dates:null, year:?int, organization:string, class_code:string, class_name:string, round:null, placing:string, horse:string}>
     */
    public static function memberStandings(string $text): array
    {
        $year = preg_match('/[A-Z][a-z]+day, [A-Z][a-z]+ \d{1,2}, (\d{4})\s+Page/', $text, $y) ? (int) $y[1] : null;
        $rows = [];
        $class = null;
        $horse = null;
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^\s*(\d{1,6})\s+(\S.*?)\s{2,}#Judges/', $line, $m)) {
                $class = ['class_code' => $m[1], 'class_name' => ucwords(strtolower(trim($m[2])))];
                $horse = null;
                continue;
            }
            if (preg_match('/^\s+(\d{3,6})\s+(\S.*?)\s{2,}(\d+)\s+(\d+)\s+(?:Yes|No)\s+(?:Yes|No)\s+\d+\s*$/', $line, $m)) {
                if ($class && $horse && (int) $m[4] > 0) {
                    $rows[] = ['show_no' => $m[1], 'event' => trim($m[2]), 'dates' => null, 'year' => $year, 'organization' => 'AQHA',
                        'round' => null, 'placing' => (string) (int) $m[4], 'horse' => $horse] + $class;
                }
                continue;
            }
            if ($class && preg_match('/^\s{2,10}(\S.*?)\s{2,}(?:\S.*?\s+)?\d+\s+\d+\s*$/', $line, $m)) {
                $horse = trim($m[1]);
            }
        }

        return $rows;
    }
}
