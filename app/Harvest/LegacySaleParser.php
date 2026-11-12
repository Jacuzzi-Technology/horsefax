<?php

namespace App\Harvest;

use App\Models\SaleLot;
use App\Models\ShowRecord;
use App\Support\Record;

/**
 * The results tables on legacysale.com (TablePress, one or two per page). The columns change from year to year, so
 * they're read by their headings:
 *
 *   2014–2017  Hip # | Horse | Sire | Dam | Purchaser/Buyer | Final/Last Bid             (two tables: hips 1–, 201–)
 *   2018       HIP # | HORSE | SIRE | DAM | BUYER | LAST BID
 *   2019–2021  HIP # | HORSE | SIRE | DAM | CONSIGNOR | SALE AMOUNT/PRICE | BUYER       (order varies)
 *   2022       HIP NUMBER | PHOTO | VIDEO | HORSE | CONSIGNOR | Buyer | Sale Amount
 *   2023       HIP # | CONSIGNMENT | CONSIGNOR | BUYER | SALE AMT
 *   2024–2026  HIP No | Consignor | Description/Horse | Sale Amount | Buyer
 *   2026 Designer  HIP No | Horse Name | Consignor | Sex | Foaled | Color | Sire | Dam | Sale Amount | Buyer
 *
 * The horse cell up to 2023 is "NAME<br>2013 Palomino Stallion" (sometimes "*Bred to ..." or "*SELLS WITH RECIP ..."
 * after it, never kept). Unnamed horses print "PENDING", "Pending/<dam>", "Pending A/<dam>", "NAME (PENDING)",
 * "FOAL IN UTERO" or "Selling On Reg App".
 *
 * Facts only: the consignor and buyer cells are read only to tell a sale from a pass-out or a scratch and are never
 * returned (has_buyer says whether the cell was filled in, as RobinGlennHarvester does).
 */
class LegacySaleParser
{
    /** @return list<array{hip:string, name:?string, pending:bool, foal_year:?int, sex:?string, color:?string, registry:?string, sire:?string, dam:?string, status:string, price_cents:?int, last_bid_cents:?int, has_buyer:bool}> */
    public static function rows(string $html): array
    {
        $out = [];
        $seen = [];
        preg_match_all('#<table\b.*?</table>#is', $html, $tables);
        foreach ($tables[0] as $table) {
            preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $table, $trs);
            $cols = null;
            foreach ($trs[1] as $tr) {
                preg_match_all('#<t([dh])\b[^>]*>(.*?)</t\1>#is', $tr, $cells);
                $cells = array_map([self::class, 'cell'], $cells[2]);
                if ($cols === null) {
                    $cols = self::columns($cells);
                    continue;
                }
                if (! isset($cols['hip'], $cols['name'])) {
                    break; // not a results table
                }
                $row = self::row($cells, $cols);
                if ($row === null || isset($seen[$row['hip']])) {
                    continue; // totals, averages, blank lines; a hip printed twice keeps its first line
                }
                $seen[$row['hip']] = true;
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Cell text with line breaks kept as "\n". */
    private static function cell(string $html): string
    {
        $t = preg_replace('#<br\s*/?>#i', "\n", $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{00A0}", ' ', $t);
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t]+/', ' ', $l), " \t|"), preg_split('/\R/', $t)), fn ($l) => $l !== ''));

        return implode("\n", $lines);
    }

    /** Heading text → column index. */
    public static function columns(array $headings): array
    {
        $cols = [];
        foreach ($headings as $i => $h) {
            $h = strtolower(trim($h));
            $key = match (true) {
                str_starts_with($h, 'hip') => 'hip',
                in_array($h, ['horse', 'horse name', 'description', 'consignment'], true) => 'name',
                $h === 'sire' => 'sire',
                $h === 'dam' => 'dam',
                $h === 'sex' => 'sex',
                in_array($h, ['foaled', 'foal year', 'yob'], true) => 'foaled',
                $h === 'color' => 'color',
                in_array($h, ['buyer', 'purchaser'], true) => 'buyer',
                (bool) preg_match('/^(final bid|last bid|sale (amount|amt|price)|price)$/', $h) => 'price',
                default => null, // consignor, photo, video: never read
            };
            if ($key && ! isset($cols[$key])) {
                $cols[$key] = $i;
            }
        }

        return $cols;
    }

    private static function row(array $cells, array $cols): ?array
    {
        $get = fn ($k) => isset($cols[$k]) ? trim($cells[$cols[$k]] ?? '') : '';
        $hip = strtoupper(str_replace([' ', '-'], '', $get('hip')));
        $horse = $get('name');
        $buyer = $get('buyer');
        $amount = $get('price');
        if (! preg_match('/^\d{1,4}[A-Z]?$/', $hip)) {
            // "OUT" in the hip column, "Total", "Average", blank spacer lines.
            return null;
        }
        $lines = explode("\n", $horse);
        $first = trim($lines[0] ?? '');
        $passOut = (bool) preg_match('/\bPASS\s*OUT\b|^\s*-?PO\s*$/', strtoupper($buyer)) || preg_match('/^\s*-?PO\s*$/', strtoupper($amount));
        $withdrawn = ! $passOut && (($first === '' && self::cents($amount) === 0) || preg_match('/^OUT$/i', $first) || preg_match('/\s+(-\s*out|\(\s*out\s*\))$/i', $first)
            || preg_match('/^-?\s*OUT( OF SALE)?,?$/', trim(strtoupper($buyer))) || preg_match('/^OUT$/', trim(strtoupper($amount))));
        $first = preg_replace('/\s+(-\s*out|\(\s*out\s*\))$/i', '', $first);

        [$name, $pending, $dam] = self::name($first);
        $desc = self::description($lines[1] ?? '');
        $sire = self::person($get('sire'));
        $dam = self::person($get('dam')) ?? $dam;
        $cents = self::cents($amount);
        $status = match (true) {
            (bool) $withdrawn => 'withdrawn',
            $passOut => 'no_sale',
            $cents > 0 => 'sold',
            default => 'offered', // catalogued, no result printed
        };
        $foaled = $get('foaled');

        return [
            'hip' => $hip, 'name' => $name, 'pending' => $pending,
            'foal_year' => preg_match('/^(19|20)\d\d$/', $foaled) ? (int) $foaled : $desc['foal_year'],
            'sex' => self::sex($get('sex')) ?? $desc['sex'], 'color' => self::title($get('color')) ?: $desc['color'], 'registry' => $desc['registry'],
            'sire' => $sire, 'dam' => $dam,
            'status' => $status, 'price_cents' => $status === 'sold' ? $cents : null,
            'last_bid_cents' => $status === 'no_sale' && $cents > 0 ? $cents : null,
            'has_buyer' => $status === 'sold' && trim($buyer, " ,-\t") !== '',
        ];
    }

    /** [registered name or null, unnamed?, dam name read off "Pending/<dam>"]. */
    public static function name(string $text): array
    {
        $t = trim(preg_replace('/\s*\*+\s*$/', '', trim($text)));
        if (preg_match('#^pending\s*[A-Z]?\s*[/(]\s*([^)]*?)\)?$#i', $t, $m)) {
            $dam = trim($m[1]);

            // "MagChicDxMadeofPureSpangled" is a sire x dam shorthand, not the dam's name.
            return [null, true, $dam !== '' && ! preg_match('/[a-z][A-Z]/', $dam) ? self::person($dam) : null];
        }
        if (preg_match('/\(\s*pending\s*\)/i', $t) || $t === '' || preg_match('/^(pending|selling on reg app|foal in utero|embryo|weanling|unnamed|tba)\b/i', $t) || Record::placeholderName($t)) {
            return [null, true, null];
        }

        return [self::person($t), false, null];
    }

    /** "2013 Bay APHA Mare", "AQHA/APHA 2013 PALOMINO OVERO MARE", "2008 Sorrel Mare*Bred to ...". */
    public static function description(string $line): array
    {
        $line = trim(preg_replace('/\*.*$/s', '', $line));
        $out = ['foal_year' => null, 'sex' => null, 'color' => null, 'registry' => null];
        if (! preg_match('/\b((?:19|20)\d\d)\b/', $line, $y)) {
            return $out;
        }
        $out['foal_year'] = (int) $y[1];
        $regs = preg_match_all('/\b(AQHA|APHA|NRHA|NCHA|ApHC)\b/i', $line, $r) ? array_unique(array_map('strtoupper', $r[1])) : [];
        $out['registry'] = count($regs) === 1 && $regs[0] !== 'AQHA' ? $regs[0] : null; // "AQHA/APHA" names both: no single registry
        $rest = trim(preg_replace(['/\b(?:19|20)\d\d\b/', '#\b(AQHA|APHA|NRHA|NCHA|ApHC)\b/?#i'], '', $line));
        if (preg_match('/\b(stallion|mare|gelding|filly|colt|unknown)\s*$/i', $rest, $s)) {
            $out['sex'] = self::sex($s[1]);
            $rest = trim(substr($rest, 0, -strlen($s[0])));
        }
        $out['color'] = self::title($rest) ?: null;

        return $out;
    }

    public static function sex(string $s): ?string
    {
        return match (strtolower(trim($s))) {
            'stallion', 'colt' => 'stallion', 'mare', 'filly' => 'mare', 'gelding' => 'gelding', default => null,
        };
    }

    /** "$15,500.00", "15,500", "18500" → cents; anything else 0. */
    public static function cents(string $s): int
    {
        return preg_match('/^\$?\s*([\d,]+)(\.\d\d)?$/', trim($s), $m) ? (int) round((float) (str_replace(',', '', $m[1]).($m[2] ?? '')) * 100) : 0;
    }

    /** A horse's name as printed, all-capitals pages in title case (NrchaHarvester::display). */
    private static function person(string $s): ?string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s), " .*");

        return $s === '' ? null : mb_substr(NrchaHarvester::display($s), 0, 255);
    }

    private static function title(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));

        return $s === '' ? '' : ucwords(strtolower($s), " /-");
    }

    /**
     * The Robin Glenn lot of the same sale (year), hip and horse: names that agree or one holding the other (as
     * WaverlyParser::bidLot). Null when none or more than one.
     */
    public static function robinGlennLot(int $rgSourceId, int $year, string $hip, string $name): ?SaleLot
    {
        $want = ShowRecord::key($name);
        $hits = SaleLot::where('data_source_id', $rgSourceId)->where('sale_year', $year)->where('lot', $hip)
            ->where('sale_name', 'like', 'Legacy Reining Breeders Sale%')->get()->filter(function ($l) use ($want) {
                $k = ShowRecord::key($l->horse_name);
                if ($k === '' || $want === '' || str_starts_with(strtolower($l->horse_name), 'pending')) {
                    return false;
                }

                return $k === $want || (min(strlen($k), strlen($want)) >= 5 && (str_contains($want, $k) || str_contains($k, $want)));
            });

        return $hits->count() === 1 ? $hits->first() : null;
    }
}
