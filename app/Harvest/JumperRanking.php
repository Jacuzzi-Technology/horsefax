<?php

namespace App\Harvest;

/**
 * EquestrianLive's jumper feed has no placings: its page ranks entries in the browser (includes/js_classjumper.js,
 * read Oct 17 2026). This is a line-for-line port of that ranking: time faults, the four stable sorts, the
 * "awaiting jump-off" rule and the tie rules, so a stored placing matches what the site shows.
 */
class JumperRanking
{
    private const FAULTS_CONVERTED = 9;
    private const SINGLE_ROUND_TABLE_II = 18;

    /** @param array $class the feed's type=class record; @param list<array> $entries its type=j records */
    public static function rank(array $class, array $entries): array
    {
        $type = (int) ($class['t'] ?? 0);
        $timeAllowed = self::num($class['ta'] ?? 0);
        $joTimeAllowed = self::num($class['jta'] ?? 0);
        $secondsPerRail = self::num($class['jspr'] ?? 0) ?: 4;

        $comps = [];
        foreach ($entries as $e) {
            $c = [
                'back' => $e['B'] ?? '', 'horse' => $e['H'] ?? '', 'stamp' => self::num($e['st'] ?? 0),
                'time' => $e['t'] ?? '', 'timeInt' => self::num($e['ti'] ?? 0), 'faults' => self::num($e['f'] ?? 0),
                'joTime' => $e['jt'] ?? '', 'joTimeInt' => self::num($e['jti'] ?? 0), 'joFaults' => self::num($e['jf'] ?? 0),
                'timeFaults' => 0, 'joTimeFaults' => 0, 'totalFaults' => 0, 'joTotalFaults' => 0, 'rank' => 0,
            ];
            if ($c['time'] === '' && $c['faults'] == 0 || $c['time'] !== '' && $c['timeInt'] == 0) {
                $c['faults'] = 99900;
            }
            if ($c['joTime'] === '' && $c['joFaults'] == 0 || $c['joTime'] !== '' && $c['joTimeInt'] == 0) {
                $c['joFaults'] = 99900;
            }
            $comps[$c['back']] = $c; // a later line for the same back number replaces the earlier one
        }
        $comps = array_values($comps);

        if ($type !== self::FAULTS_CONVERTED) {
            foreach ($comps as &$c) {
                if ($timeAllowed > 0 && $c['timeInt'] < 9000000 && ($x = floor($c['timeInt']) - $timeAllowed) > 0) {
                    $c['timeFaults'] = floor(($x + 999) / 1000) * 100;
                }
                if ($joTimeAllowed > 0 && $c['joTimeInt'] < 9000000 && ($x = floor($c['joTimeInt']) - $joTimeAllowed) > 0) {
                    $c['joTimeFaults'] = floor(($x + 999) / 1000) * 100;
                }
                $c['totalFaults'] = floor($c['faults']) + floor($c['timeFaults']);
                $c['joTotalFaults'] = floor($c['joFaults']) + floor($c['joTimeFaults']) + (in_array($type, [14, 16], true) ? $c['totalFaults'] : 0);
            }
            unset($c);
        }

        // Same order of stable sorts as the page.
        usort($comps, fn ($a, $b) => $type === self::FAULTS_CONVERTED
            ? (10 * ($a['faults'] / 4 * $secondsPerRail) + $a['timeInt']) <=> (10 * ($b['faults'] / 4 * $secondsPerRail) + $b['timeInt'])
            : $a['timeInt'] <=> $b['timeInt']);
        if ($type !== self::FAULTS_CONVERTED) {
            usort($comps, fn ($a, $b) => $a['totalFaults'] <=> $b['totalFaults']);
        }
        usort($comps, fn ($a, $b) => $type === self::FAULTS_CONVERTED
            ? (10 * $a['joFaults'] + $a['joTimeInt']) <=> (10 * $b['joFaults'] + $b['joTimeInt'])
            : $a['joTimeInt'] <=> $b['joTimeInt']);
        if ($type !== self::FAULTS_CONVERTED) {
            usort($comps, fn ($a, $b) => $a['joTotalFaults'] <=> $b['joTotalFaults']);
        }
        $awaiting = fn ($c) => $type === 1 && $c['timeInt'] > 0 && $c['timeInt'] < 9000000 && $c['totalFaults'] == 0
            && ! ($c['joTimeInt'] > 0 && $c['joTimeInt'] < 9000000);
        if ($type === 1) {
            $wait = array_values(array_filter($comps, $awaiting));
            usort($wait, fn ($a, $b) => $a['stamp'] <=> $b['stamp']);
            $comps = array_merge($wait, array_values(array_filter($comps, fn ($c) => ! $awaiting($c))));
        }

        $rank = 1;
        foreach ($comps as $i => &$c) {
            $p = $comps[$i - 1] ?? null;
            if ($type === self::FAULTS_CONVERTED) {
                $new = ! $p || self::num($c['time'], NAN) + $c['faults'] / 100 != self::num($p['time'], NAN) + $p['faults'] / 100
                    || self::num($c['joTime'], NAN) + $c['joFaults'] / 100 != self::num($p['joTime'], NAN) + $p['joFaults'] / 100;
            } elseif ($type === self::SINGLE_ROUND_TABLE_II) {
                $new = ! $p || $c['totalFaults'] != $p['totalFaults'];
            } else {
                $new = ! $p || $c['time'] !== $p['time'] || $c['faults'] != $p['faults'] || $c['joTime'] !== $p['joTime'] || $c['joFaults'] != $p['joFaults'];
            }
            if ($new) {
                $rank = $i + 1;
            }
            $ran = $c['timeInt'] > 0 && $c['timeInt'] < 9000000;
            $joRan = $c['joTimeInt'] > 0 && $c['joTimeInt'] < 9000000;
            $c['rank'] = $awaiting($c) || ! $ran || (in_array($type, [14, 16], true) && ! $joRan) ? 0 : $rank;
        }
        unset($c);

        return $comps;
    }

    /** "4+1 faults, 80.69 · jump-off 0 faults, 32.24" */
    public static function describe(array $c): string
    {
        $round = function ($faults, $timeFaults, $time) {
            $f = $faults == 99900 ? null : ($faults / 100).($timeFaults > 0 ? '+'.($timeFaults / 100) : '');
            return trim(($f !== null ? $f.' faults, ' : '').$time, ', ');
        };
        $out = $round($c['faults'], $c['timeFaults'], $c['time']);
        if ($c['joTimeInt'] > 0 && $c['joTimeInt'] < 9000000) {
            $out .= ' · jump-off '.$round($c['joFaults'], $c['joTimeFaults'], $c['joTime']);
        }

        return $out;
    }

    private static function num(mixed $v, float $blank = 0.0): float
    {
        return is_numeric($v) ? (float) $v : ($v === '' || $v === null ? 0.0 : $blank);
    }
}
