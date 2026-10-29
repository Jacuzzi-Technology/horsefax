<?php

namespace App\Harvest;

/**
 * Billings Livestock Commission horse sales (billingslivestock.com), from word positions (PremierParser::words).
 *
 * Catalog PDFs: each horse is a block that starts with "HIP 14" and reads, top to bottom: registered name,
 * registration number (when registered), "2024 AQHA Bay Gelding", the consignor, then a three-generation pedigree
 * tree (15 names, sire's side first, the horse itself in the middle) and the seller's description. Blocks sit in
 * columns of different widths, so each word belongs to the nearest HIP marker above and to its left.
 *
 * Top 20/40 lists: Rank, Price, Hip#, "Name" + "2017 AQHA Bay Gelding x Sire x Dam's sire", Consignor, Purchaser.
 * Consignor, purchaser, seller phone numbers and email addresses are never kept.
 */
class BillingsParser
{
    public const SEXES = 'Gelding|Mare|Stallion|Filly|Colt|Mule|Molly|John|Jenny|Jack|Gelding Mule|Molly Mule|Horse|Pony';

    /** @return list<array{hip:string, name:string, reg:?string, foal_year:?int, registry:?string, color:?string, sex:?string, pedigree:array, description:?string, video:?string}> */
    public static function catalog(array $pages): array
    {
        $out = [];
        foreach ($pages as $words) {
            // Some catalogs (2021) pad words with (non-breaking) spaces and print runs of blanks as words.
            $words = array_values(array_filter(array_map(fn ($w) => [$w[0], $w[1], $w[2], trim(str_replace("\u{00A0}", ' ', $w[3]))], $words), fn ($w) => $w[3] !== ''));
            $anchors = [];
            foreach ($words as $i => $w) {
                if ($w[3] === 'HIP' && isset($words[$i + 1]) && preg_match('/^\d{1,4}[A-Z]?$/', $words[$i + 1][3])) {
                    $anchors[] = ['x' => $w[0], 'y' => $w[1], 'hip' => $words[$i + 1][3], 'words' => []];
                }
            }
            if (! $anchors) {
                continue;
            }
            foreach ($words as $w) {
                $best = null;
                foreach ($anchors as $k => $a) {
                    if ($a['x'] <= $w[0] + 10 && $a['y'] <= $w[1] + 3
                        && ($best === null || $a['x'] > $anchors[$best]['x'] + 5 || (abs($a['x'] - $anchors[$best]['x']) <= 5 && $a['y'] > $anchors[$best]['y']))) {
                        $best = $k;
                    }
                }
                if ($best !== null) {
                    $anchors[$best]['words'][] = $w;
                }
            }
            foreach ($anchors as $a) {
                if ($h = self::block($a['hip'], self::segments($a['words']))) {
                    $out[] = $h;
                }
            }
        }
        // "HIP 86" also turns up inside ads and descriptions ("She sells! Hip 86"). A real block has a foal year or sex
        // line under its name; when a hip still appears twice, keep the fuller block.
        $out = array_filter($out, fn ($h) => ($h['foal_year'] || $h['sex']) && ! self::garbled($h));
        $best = [];
        foreach ($out as $h) {
            $score = fn ($x) => ($x['reg'] ? 4 : 0) + ($x['pedigree'] ? 2 : 0) + ($x['foal_year'] && $x['sex'] ? 1 : 0);
            if (! isset($best[$h['hip']]) || $score($h) > $score($best[$h['hip']])) {
                $best[$h['hip']] = $h;
            }
        }

        return array_values($best);
    }

    /** Words -> text runs: same line (3pt) and no wide gap between words, top to bottom then left to right. */
    private static function segments(array $words): array
    {
        usort($words, fn ($a, $b) => abs($a[1] - $b[1]) > 3 ? $a[1] <=> $b[1] : $a[0] <=> $b[0]);
        $segs = [];
        foreach ($words as $w) {
            $n = count($segs);
            if ($n && abs($segs[$n - 1]['y'] - $w[1]) <= 3 && $w[0] - $segs[$n - 1]['x1'] < 12) {
                $segs[$n - 1]['text'] .= ' '.$w[3];
                $segs[$n - 1]['x1'] = $w[2];
            } else {
                $segs[] = ['x' => $w[0], 'y' => $w[1], 'x1' => $w[2], 'text' => $w[3]];
            }
        }
        usort($segs, fn ($a, $b) => abs($a['y'] - $b['y']) > 3 ? $a['y'] <=> $b['y'] : $a['x'] <=> $b['x']);

        return $segs;
    }

    private static function block(string $hip, array $segs): ?array
    {
        $texts = array_values(array_filter(array_map(fn ($s) => trim(preg_replace('/^HIP\s+\S+\s*(Online)?\s*/i', '', $s['text'])), $segs), fn ($t) => $t !== '' && ! preg_match('/^(Online|www\.billingslivestock\.com|\d{1,3})$/i', $t)));
        if (count($texts) < 2) {
            return null;
        }
        // 2013-2016 catalogs may print a section label ("Rope Horse") above the name: start at the name, which is the
        // run just before the "07 AQHA Dun Gelding" line (or before the registration number above it).
        foreach (array_slice($texts, 0, 5) as $k => $t) {
            if ($k >= 2 && preg_match('/^(?:\d\d|(?:19|20)\d\d)\s*(?:(?:AQHA|APHA|ApHC|PtHA|Grade|Registered)\b|.*\b(?:'.self::SEXES.')\b)/i', $t)) {
                $start = preg_match('/^([A-Z]?\d{5,8}|PENDING|Pending)$/', $texts[$k - 1]) ? $k - 2 : $k - 1;
                $texts = array_slice($texts, $start);
                break;
            }
        }
        $name = trim(preg_replace('/\s*\((pending|papers pending)\)$/i', '', array_shift($texts))); // registration not back yet
        $reg = null;
        if (isset($texts[0]) && preg_match('/^([A-Z]?\d{5,8}|PENDING|Pending)$/', $texts[0])) {
            $reg = strtoupper(array_shift($texts));
            $reg = $reg === 'PENDING' ? null : $reg;
        }
        // "2024 AQHA Bay Gelding" (may wrap onto the next run); then the consignor and town.
        $desc = array_shift($texts) ?? '';
        if (! preg_match('/\b('.self::SEXES.')\b/i', $desc) && isset($texts[0]) && preg_match('/^('.self::SEXES.')\b/i', $texts[0])) {
            $desc .= ' '.array_shift($texts);
        }
        $id = self::descriptor($desc);
        if (isset($texts[0]) && ! self::isNameLike($texts[0], $name)) {
            array_shift($texts); // consignor
        }
        // The pedigree tree: 15 short name runs in tree order, the horse's own name in the middle.
        $tree = [];
        while ($texts && count($tree) < 15 && self::isNameLike($texts[0], $name)) {
            $tree[] = array_shift($texts);
        }
        $pedigree = [];
        if (count($tree) === 15 && \App\Models\ShowRecord::key($tree[7]) === \App\Models\ShowRecord::key($name)) {
            $order = ['sss', 'ss', 'ssd', 's', 'sds', 'sd', 'sdd', null, 'dss', 'ds', 'dsd', 'd', 'dds', 'dd', 'ddd'];
            foreach ($order as $i => $pos) {
                if ($pos) {
                    $pedigree[$pos] = NrchaHarvester::display($tree[$i]);
                }
            }
        } else {
            $texts = array_merge($tree, $texts); // not a full tree: leave it as text
        }
        $description = trim(preg_replace('/\s+/', ' ', implode(' ', $texts)));
        $video = preg_match('#VIDEO:\s*(https?://\S+(?:\s+\S+)?)#i', $description, $v) ? preg_replace('/\s+/', '', preg_replace('/\s+FMI:.*$/i', '', $v[1])) : null;
        // No phone numbers or email addresses are kept.
        $description = trim(preg_replace(['/FMI:\s*[\d\s().-]+\.?/i', '/\b[\w.+-]+@[\w-]+\.[\w.]+\b/', '/\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}/'], '', $description));

        return [
            'hip' => $hip, 'name' => NrchaHarvester::display($name), 'reg' => $reg,
            'pedigree' => $pedigree, 'description' => $description ?: null, 'video' => $video,
        ] + $id;
    }

    /**
     * A block where two columns ran together (seen in 2013-2016 catalogs): the name holds a registration number, a HIP
     * marker, a section label, a sale note or the consignor's town, or the color picked up a number or a registry.
     * Better left out than loaded under a wrong name.
     */
    private static function garbled(array $h): bool
    {
        return (bool) preg_match('/\d{5,}|\bHIP\b|^(Rope|Ranch|Barrel|Cutting|Ranch Riding|Rope Horse|Saddle)\s+Horses?$|^Sells\b|,\s*[A-Z][A-Za-z]$|^\d/i', $h['name'])
            || ! preg_match('/\pL/u', $h['name']) || self::sentence($h['name'])
            || preg_match('/\d|\b(AQHA|APHA|ApHC|Grade|PENDING|APPLICATION|HIP)\b/i', (string) $h['color']) || str_word_count((string) $h['color']) > 4;
    }

    /** Ad or terms copy beside a HIP marker ("leaves the ring. We want you to buy"), or bold text drawn twice. */
    private static function sentence(string $name): bool
    {
        $w = preg_split('/\s+/', trim($name));

        return (bool) preg_match('/^[\p{Ll}(]|[;:!,"“”]|-$|\b(Session|Policy|Hotel)\b/u', $name) || count($w) > 6 || mb_strlen($name) > 40
            || (count($w) >= 4 && count(array_unique(array_map('strtolower', $w))) <= intdiv(count($w) + 1, 2) + 1);
    }

    /** "2024 AQHA Bay Gelding" -> foal year, registry, color, sex. */
    public static function descriptor(string $d): array
    {
        $d = trim($d);
        // Catalogs before about 2022 print a two-digit year: "07 AQHA Bay Gelding", "98 Grade Sorrel Mare".
        if (preg_match('/^(\d\d)\s*(?=[A-Za-z])/', $d, $two)) { // "07AQHA" too
            $d = ((int) $two[1] > (int) date('y') + 1 ? '19' : '20').$two[1].' '.ltrim(substr($d, 2));
        }
        $year = preg_match('/^((?:19|20)\d\d)\b/', $d, $m) ? (int) $m[1] : null;
        $rest = trim(preg_replace('/^(19|20)\d\d\s*/', '', $d));
        $registry = preg_match('/^(AQHA|APHA|ApHC|APHC|PtHA|ABRA|PHBA|IBHA|AMHA|POA|IQPA|ARHA|RHAA|NCHA|GVHS|FHHSI|FPZV|AHA|JC|TB|Grade|GRADE|Registered)\b/i', $rest, $r) ? strtoupper($r[1]) : null;
        $rest = $registry ? trim(substr($rest, strlen($r[1]))) : $rest;
        $sex = preg_match('/\b('.self::SEXES.')\b/i', $rest, $s) ? ucfirst(strtolower($s[1])) : null;
        $color = $sex ? trim(substr($rest, 0, stripos($rest, $s[1]))) : null;

        return ['foal_year' => $year, 'registry' => $registry === 'APHC' ? 'ApHC' : ($registry === 'GRADE' ? null : $registry),
            'color' => $color ?: null, 'sex' => $sex ? strtolower($sex) : null];
    }

    private static function isNameLike(string $t, string $name): bool
    {
        return mb_strlen($t) <= 40 && str_word_count($t) <= 6 && ! preg_match('/[.!?:;]$|\b(VIDEO|FMI|Coggins)\b|,\s*[A-Z]{2}\b/', $t);
    }

    /**
     * A Top 20/40 list.
     *
     * @return array{date:?string, rows:list<array{hip:string, name:string, price_cents:int, foal_year:?int, registry:?string, color:?string, sex:?string, sire:?string, dam_sire:?string}>}
     */
    public static function topList(array $pages, string $text): array
    {
        $date = null;
        if (preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})[^\n]*?((?:19|20)\d\d)/i', $text, $m)) {
            $date = date('Y-m-d', strtotime($m[1].' '.$m[2].' '.$m[3]));
        }
        // Rebuild each line from word positions and keep only what's left of the Consignor column.
        $lines = [];
        foreach ($pages as $words) {
            $cut = null;
            foreach ($words as $w) {
                if (strcasecmp($w[3], 'Consignor') === 0) {
                    $cut = $w[0] - 2;
                    break;
                }
            }
            $rowsByY = [];
            foreach ($words as $w) {
                if ($cut === null || $w[0] < $cut) {
                    $rowsByY[(int) round($w[1] / 3)][] = $w;
                }
            }
            ksort($rowsByY);
            foreach ($rowsByY as $ws) {
                usort($ws, fn ($a, $b) => $a[0] <=> $b[0]);
                $lines[] = implode(' ', array_column($ws, 3));
            }
        }
        if (! $pages) {
            $lines = explode("\n", $text);
        }
        $rows = [];
        foreach ($lines as $line) {
            // 6-7 25,000 331 "Must Be A Boon B21" 2021 AQHA Red Roan Gelding x Boon To Be Blu x Once In A Blu Boon
            if (! preg_match('/^\s*\d+(?:-\d+)?\s+\$?([\d,]+)\s+(\d{1,4}[A-Z]?)\s+["“]([^"”]+)["”]\s*(.*)$/u', $line, $m)) {
                continue;
            }
            $rest = $pages ? trim($m[4]) : preg_split('/\s{2,}/', trim($m[4]))[0];
            [$desc, $sire, $damSire] = array_pad(array_map('trim', preg_split('/\s+x\s+/i', $rest, 3)), 3, null);
            $id = self::descriptor($desc);
            $rows[] = [
                'hip' => $m[2], 'name' => NrchaHarvester::display($m[3]), 'price_cents' => (int) str_replace(',', '', $m[1]) * 100,
                'sire' => $sire ? NrchaHarvester::display($sire) : null, 'dam_sire' => $damSire ? NrchaHarvester::display($damSire) : null,
            ] + $id;
        }

        return ['date' => $date, 'rows' => $rows];
    }
}
