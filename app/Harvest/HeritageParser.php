<?php

namespace App\Harvest;

use App\Models\ShowRecord;

/**
 * Heritage Livestock (heritagelivestock.com) sale catalogs and results pages.
 *
 * Catalog PDFs are made by Robin Glenn's QData service ("QData-CB 10/2026" stamp), one block per hip, read from word
 * positions (PremierParser::words). A block starts at "Hip No." (printed on the left, the right or both sides; mirrored
 * pages put the hip on the right) and reads: "Consigned by <name>" on the same line, then the horse's name ("Rango
 * (Grade)" for unregistered horses), "2012 Chestnut Gelding", and for registered horses a three-generation pedigree
 * whose columns sit at fixed offsets from the block's left edge: the horse's name and its 7-digit registration number
 * at the edge, sire/dam ~81pt in, the second generation ~150pt, the third ~222pt; each column top to bottom is the
 * sire's side then the dam's. "{" bracket glyphs are drawing, not text. The NOTES paragraph is never kept.
 *
 * Results pages: free text, one section per sale headed "April 13, 2024 | Parsons Livestock Auction | Parsons, KS",
 * with "Lot #45 Zip Of Driftwood $17,000 Consigned by ..." for the top sellers.
 */
class HeritageParser
{
    public const SEXES = 'Gelding|Mare|Stallion|Filly|Colt|Horse|Mule|Molly|John|Jenny|Jack|Ridgling|Pony|Donkey';
    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

    /** @return list<array{hip:string, consignor:?string, name:string, grade:bool, foal_year:?int, color:?string, sex:?string, reg:?string, pedigree:array<string,string>, page:int}> */
    public static function catalog(array $pages): array
    {
        $out = [];
        foreach ($pages as $p => $words) {
            // Some catalogs draw every word twice; drop exact repeats and the bracket glyphs.
            $seen = [];
            $words = array_values(array_filter($words, function ($w) use (&$seen) {
                $k = round($w[0]).':'.round($w[1]).':'.$w[3];
                if (isset($seen[$k]) || trim($w[3], '{} ') === '') {
                    return false;
                }

                return $seen[$k] = true;
            }));
            $words = array_map(fn ($w) => [$w[0], $w[1], $w[2], trim($w[3], '{}')], $words);
            // Anchors: "Hip" + "No." at the same y; a block runs to the next anchor down the page.
            // (Not always next in the PDF's text order: overlaid copies interleave.)
            $anchors = [];
            $nos = array_filter($words, fn ($w) => $w[3] === 'No.');
            foreach ($words as $w) {
                if ($w[3] === 'Hip' && array_filter($nos, fn ($n) => abs($n[1] - $w[1]) < 2 && $n[0] > $w[0] && $n[0] - $w[2] < 8)) {
                    $y = $w[1];
                    $dupe = array_filter($anchors, fn ($a) => abs($a['y'] - $y) < 6);
                    if ($dupe) {
                        $anchors[array_key_first($dupe)]['xs'][] = $w[0];
                    } else {
                        $anchors[] = ['y' => $y, 'xs' => [$w[0]]];
                    }
                }
            }
            usort($anchors, fn ($a, $b) => $a['y'] <=> $b['y']);
            [$words, $anchors] = self::dropOverlays($words, $anchors);
            foreach ($anchors as $k => $a) {
                $bottom = $anchors[$k + 1]['y'] ?? INF;
                $block = array_values(array_filter($words, fn ($w) => $w[1] >= $a['y'] - 2 && $w[1] < $bottom - 2));
                if ($h = self::block($a, $block)) {
                    $out[] = $h + ['page' => $p + 1];
                }
            }
        }

        return $out;
    }

    /** The hip number under an anchor's "Hip No." label: [hip, word index] or [null, null]. */
    private static function hipOf(array $a, array $words): array
    {
        foreach ($words as $i => $w) {
            if (preg_match('/^\d{1,4}[A-Z]?$/', $w[3]) && $w[1] > $a['y'] + 4 && $w[1] < $a['y'] + 24) {
                foreach ($a['xs'] as $x) {
                    if (abs($w[0] - ($x + 16)) < 22) {
                        return [$w[3], $i];
                    }
                }
            }
        }

        return [null, null];
    }

    /**
     * Some catalogs (2022-2024) print a block a second time a few points lower, on top of the first. Two anchors with
     * the same hip less than 35pt apart are one block: the copy's words (same text, shifted by the anchors' offset) go.
     */
    private static function dropOverlays(array $words, array $anchors): array
    {
        $keep = [];
        foreach ($anchors as $k => $a) {
            // A copy removed by an earlier pair on this page (the whole page is usually shifted by the same offset).
            if (! array_filter($words, fn ($w) => $w[3] === 'Hip' && abs($w[1] - $a['y']) < 0.5 && abs($w[0] - $a['xs'][0]) < 0.5)) {
                continue;
            }
            $prev = $keep ? $keep[count($keep) - 1] : null;
            $hip = self::hipOf($a, $words)[0];
            if ($prev && $hip !== null && $a['y'] - $prev['y'] < 35 && self::hipOf($prev, $words)[0] === $hip) {
                $dy = $a['y'] - $prev['y'];
                $dx = min($a['xs']) - min($prev['xs']);
                $index = [];
                foreach ($words as $i => $w) {
                    $index[$w[3]][] = $i;
                }
                $drop = [];
                foreach ($words as $i => $w) {
                    foreach ($index[$w[3]] as $j) {
                        $o = $words[$j];
                        if ($j !== $i && abs($w[1] - $o[1] - $dy) <= 1.5 && abs($w[0] - $o[0] - $dx) <= 2 && ! isset($drop[$j])) {
                            $drop[$i] = true;
                            break;
                        }
                    }
                }
                $words = array_values(array_diff_key($words, $drop));
                continue;
            }
            $keep[] = $a;
        }

        return [$words, $keep];
    }

    private static function block(array $a, array $words): ?array
    {
        [$hip, $hipWord] = self::hipOf($a, $words);
        if ($hip === null) {
            return null;
        }
        unset($words[$hipWord]);
        $lines = self::lines(array_filter($words, fn ($w) => ! in_array($w[3], ['Hip', 'No.'], true) || abs($w[1] - $a['y']) > 3));
        $consignor = null;
        $name = $desc = null;
        $descY = null;
        foreach ($lines as $l) {
            if ($consignor === null && preg_match('/^Consigned by\s+(.+)$/i', $l['text'], $m)) {
                $consignor = trim($m[1]);
            } elseif ($name === null && $l['y'] < $a['y'] + 26 && ! preg_match('/^Consigned by|^QData-CB|^NOTES:/i', $l['text'])) {
                $name = $l['text'];
            } elseif ($name !== null && $desc === null && preg_match('/^((19|20)\d\d|'.self::MONTHS.')\b.*\b('.self::SEXES.')\b/i', $l['text'])) {
                $desc = $l['text'];
                $descY = $l['y'];
                break;
            }
        }
        if ($name === null) {
            return null;
        }
        $grade = (bool) preg_match('/\(\s*Grade\s*\)/i', $name);
        $name = trim(preg_replace('/\s*\(\s*Grade\s*\)\s*/i', ' ', $name));
        if ($name === '') {
            return null;
        }
        // "2012 Chestnut Gelding", or for young stock "March 19, 2021 Bay Filly".
        $birth = null;
        $desc = (string) $desc;
        if (preg_match('/^('.self::MONTHS.')\s+(\d{1,2}),\s*((?:19|20)\d\d)\s+/i', $desc, $d)) {
            $birth = date('Y-m-d', strtotime($d[1].' '.$d[2].' '.$d[3]));
            $desc = $d[3].' '.substr($desc, strlen($d[0]));
        }
        $id = preg_match('/^(19|20)\d\d\s/', $desc) ? BillingsParser::descriptor($desc) : ['foal_year' => null, 'color' => null, 'sex' => null];
        [$reg, $pedigree] = $descY !== null && ! $grade ? self::pedigree($words, $descY, $name) : [null, []];

        return ['hip' => $hip, 'consignor' => $consignor, 'name' => $name, 'grade' => $grade, 'reg' => $reg, 'pedigree' => $pedigree,
            'foal_year' => $id['foal_year'], 'birth_date' => $birth, 'color' => $id['color'], 'sex' => $id['sex']];
    }

    /** Words between the descriptor line and NOTES: [registration number, pedigree by position]. */
    private static function pedigree(array $words, float $descY, string $name): array
    {
        $notesY = INF;
        foreach ($words as $w) {
            if ($w[1] > $descY + 3 && preg_match('/^\.?NOTES:/', $w[3])) {
                $notesY = min($notesY, $w[1]);
            }
        }
        $area = array_values(array_filter($words, fn ($w) => $w[1] > $descY + 6 && $w[1] < $notesY - 1));
        if (! $area) {
            return [null, []];
        }
        // The left edge: the registration number, else the leftmost word.
        $reg = null;
        $left = min(array_column($area, 0));
        foreach ($area as $w) {
            if (preg_match('/^\d{6,8}$/', $w[3]) && abs($w[0] - $left) < 4) {
                $reg = $w[3];
            }
        }
        $cols = [1 => [], 2 => [], 3 => []];
        foreach ($area as $w) {
            $dx = $w[0] - $left;
            $c = match (true) {
                $dx < 60 => null, $dx < 135 => 1, $dx < 212 => 2, $dx < 300 => 3, default => null,
            };
            if ($c) {
                $cols[$c][] = $w;
            }
        }
        $names = array_map(fn ($ws) => array_map(fn ($l) => $l['text'], self::lines($ws, 4)), $cols);
        $order = [1 => ['s', 'd'], 2 => ['ss', 'sd', 'ds', 'dd'], 3 => ['sss', 'ssd', 'sds', 'sdd', 'dss', 'dsd', 'dds', 'ddd']];
        $ped = [];
        foreach ($order as $c => $positions) {
            // A column is only trusted when it holds exactly the expected number of names.
            if (count($names[$c]) === count($positions)) {
                foreach ($positions as $i => $pos) {
                    $ped[$pos] = NrchaHarvester::display($names[$c][$i]);
                }
            }
        }
        // No sire/dam column, or prose caught in the columns: nothing is placed reliably.
        if (! isset($ped['s']) || array_filter($ped, fn ($n) => mb_strlen($n) > 40 || preg_match('/[–:;!?]|\b(you|your|must|the barn|receipt)\b/i', $n))) {
            $ped = [];
        }

        return [$reg, $ped];
    }

    /** Words -> lines: same y (within 2pt), sorted left to right; a gap over $gap pt starts a new run. */
    private static function lines(array $words, float $gap = 14): array
    {
        $words = array_values($words);
        usort($words, fn ($a, $b) => abs($a[1] - $b[1]) > 2 ? $a[1] <=> $b[1] : $a[0] <=> $b[0]);
        $lines = [];
        foreach ($words as $w) {
            $n = count($lines);
            if ($n && abs($lines[$n - 1]['y'] - $w[1]) <= 2 && $w[0] - $lines[$n - 1]['x1'] < $gap) {
                $lines[$n - 1]['text'] .= ' '.$w[3];
                $lines[$n - 1]['x1'] = $w[2];
            } else {
                $lines[] = ['x' => $w[0], 'y' => $w[1], 'x1' => $w[2], 'text' => $w[3]];
            }
        }
        foreach ($lines as &$l) {
            $l['text'] = trim(preg_replace('/\s+/', ' ', $l['text']));
        }

        return array_values(array_filter($lines, fn ($l) => $l['text'] !== ''));
    }

    /** The sale day printed on a catalog's cover ("Sale: June 17, 2023", "NOVEMBER 19, 2022"), or null. */
    public static function coverDate(string $coverText): ?string
    {
        $t = preg_replace('/\s+/', ' ', $coverText);
        if (preg_match('/Sale:\s*('.self::MONTHS.')\s+(\d{1,2}),?\s*((?:19|20)\d\d)/i', $t, $m)
            || preg_match('/(?<!PREVIEW: )(?<!FRIDAY, )\b('.self::MONTHS.')\s+(\d{1,2}),\s*((?:19|20)\d\d)/i', $t, $m)) {
            return date('Y-m-d', strtotime($m[1].' '.$m[2].' '.$m[3]));
        }

        return null;
    }

    /** The newest "QData-CB 10/2026" stamp in a catalog: [year, month] or null. */
    public static function stampMonth(string $text): ?array
    {
        preg_match_all('#QData-CB\s+(\d{1,2})/((?:19|20)\d\d)#', $text, $m, PREG_SET_ORDER);
        $best = null;
        foreach ($m as $s) {
            $v = [(int) $s[2], (int) $s[1]];
            $best = $best === null || $v > $best ? $v : $best;
        }

        return $best;
    }

    /**
     * A results page's sales and top-seller prices.
     *
     * @return list<array{date:string, title:?string, venue:?string, city:?string, lots:list<array{hip:string, name:?string, price_cents:int}>}>
     */
    public static function results(string $html): array
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#s', '', $html);
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h\d|/div|/li|/span)\b[^>]*>#i', "\n$0", $html)), ENT_QUOTES | ENT_HTML5);
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/[\x{200B}\s]+/u', ' ', $l)), explode("\n", $text)), fn ($l) => $l !== ''));
        // Wix splits words across spans ("April 13" / ", 2024 | ..."): glue a line that starts with "," or a lone
        // lowercase fragment to the one before.
        $glued = [];
        foreach ($lines as $l) {
            if ($glued && (preg_match('/^[,.]/', $l) || preg_match('/^[a-z]{1,3}\b/', $l) && ! preg_match('/^(by|and|at|of|for)\b/', $l))) {
                $glued[count($glued) - 1] .= $l;
            } else {
                $glued[] = $l;
            }
        }
        $flat = implode("\n", $glued);
        $sales = [];
        $re = '/^(?:(?<title>[^\n]*Sale[^\n]*)\n(?:at [^\n]*\n)?)?(?<month>'.self::MONTHS.')\s+(?<day>\d{1,2})(?:st|nd|rd|th)?\s*,\s*(?<year>(?:19|20)\d\d)\s*\|\s*(?<venue>[^|\n]+?)\s*\|\s*(?<city>[^\n]+)$/mi';
        preg_match_all($re, $flat, $hs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($hs as $i => $h) {
            $start = $h[0][1] + strlen($h[0][0]);
            $end = isset($hs[$i + 1]) ? $hs[$i + 1][0][1] : strlen($flat);
            $body = substr($flat, $start, $end - $start);
            $lots = [];
            $parts = preg_split('/(?=Lot\s*#\s*\d)/i', $body);
            foreach ($parts as $part) {
                if (! preg_match('/^Lot\s*#\s*(\d{1,4}[A-Z]?)\b(.*)$/is', $part, $m)) {
                    continue;
                }
                // The lot's own text, before any average line or the next section.
                $seg = preg_split('/\n\s*(Top \d+|Sale Average|\d+ head|Proven Session|Reserve|TOP|HIGH|THANK)/i', $m[2])[0];
                if (! preg_match('/\$\s*([\d,]{3,})/', $seg, $p)) {
                    continue;
                }
                $name = trim(preg_split('/\s*(?:\$|\bConsigned\b|\bconsigned\b|\bAgent\b|\bboth\b|\s-\s|\bSold for\b|\bSelling for\b|\bby\b)/', $seg, 2)[0]);
                $name = trim(preg_replace('/\s+/', ' ', $name), " -&\n");
                $lots[$m[1]] = ['hip' => $m[1], 'name' => $name !== '' ? $name : null, 'price_cents' => (int) str_replace(',', '', $p[1]) * 100];
            }
            $sales[] = [
                'date' => date('Y-m-d', strtotime($h['month'][0].' '.$h['day'][0].' '.$h['year'][0])),
                'title' => isset($h['title']) && $h['title'][0] !== '' ? trim($h['title'][0]) : null,
                'venue' => trim($h['venue'][0]), 'city' => trim($h['city'][0]), 'lots' => array_values($lots),
            ];
        }

        return $sales;
    }

    /** Does a results-page lot name agree with the catalog's horse? (Results often give a description, e.g. "Finished Rope Gelding".) */
    public static function sameHorse(?string $resultName, string $catalogName): ?bool
    {
        if (! $resultName) {
            return null;
        }
        $a = ShowRecord::key($resultName);
        $b = ShowRecord::key($catalogName);
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        return preg_match('/\b(gelding|mare|horse|pony|mule|filly|colt|team)\b/i', $resultName) ? null : false;
    }
}
