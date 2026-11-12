<?php

namespace App\Harvest;

/**
 * Colorado Horse Sale's published results (coloradohorsesale.com/sale-results/): full tables for every 2026 sale and
 * October 2025 (PDF or CSV: lot, "Name" - description, consignor, final bid, sold/unsold), and top-seller summaries for
 * 2023-2025 ("Lot 74 “Bojangles” from KC Peterson - $20,000"). Read from printed-catalogs/colorado/results; the files
 * were fetched Nov 8 2026 (robots.txt allows all; Sale Terms only, no collecting clause).
 *
 * Facts only: consignor names, buyers and where horses are headed are never read.
 */
class ColoradoHorseSaleResults
{
    /** file => [sale date, precision, "table" (every horse) or "summary" (top sellers only)] */
    public const FILES = [
        'May-20th-Sale-REPORT.pdf' => ['2023-05-20', 'day', 'summary'],
        'August-2023-CHS-Market-Observations.pdf' => ['2023-08-26', 'day', 'summary'],
        'November-18th-2023-Sale-Report.pdf' => ['2023-11-18', 'day', 'summary'],
        'April-2024-Sale-Report.pdf' => ['2024-04-13', 'day', 'summary'],
        'May-2024-Sale-Report.pdf' => ['2024-05-01', 'month', 'summary'],
        'Ast-31st-2024-Sale-Report.pdf' => ['2024-08-31', 'day', 'summary'],
        'November-2024-Sale-Report.pdf' => ['2024-11-01', 'month', 'summary'],
        'February-2025-Auction-Results.pdf' => ['2025-02-22', 'day', 'summary'],
        'April-2025-Sale-Results.pdf' => ['2025-04-12', 'day', 'summary'],
        'May-2025-Sale-Results-_-Summary.pdf' => ['2025-05-17', 'day', 'summary'],
        'August-2025-Sale-Results_Summary.pdf' => ['2025-08-23', 'day', 'summary'],
        'October-11-2025-Results.pdf' => ['2025-10-11', 'day', 'table'],
        'November-Online-Sale-Summary.pdf' => ['2025-11-01', 'month', 'summary'],
        'February-2026-Auction-Results.pdf' => ['2026-02-01', 'month', 'table'],
        'CHS-April-2026-Sheet3.pdf' => ['2026-04-01', 'month', 'table'],
        'May-30-2026-Results-Sheet1-1.csv' => ['2026-05-30', 'day', 'table'],
        'August-2026-Sale-Result-Sheet1.csv' => ['2026-08-01', 'month', 'table'],
        'October-2026-Sale-Results-Sheet1.csv' => ['2026-10-10', 'day', 'table'],
    ];

    /** @return list<array{lot:string, name:string, description:string, price_cents:?int, status:string}> */
    public static function read(string $path, string $kind): array
    {
        if (str_ends_with($path, '.csv')) {
            return self::csv($path);
        }
        $text = StatePdfHarvester::text((string) file_get_contents($path));

        return $kind === 'table' ? self::table($text) : self::summary($text);
    }

    private static function csv(string $path): array
    {
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path))));
        $head = array_map(fn ($h) => strtolower(preg_replace('/[^a-z#]/i', '', $h)), array_shift($rows));
        $col = fn (array $names) => array_values(array_intersect($names, $head)) ? array_search(array_values(array_intersect($names, $head))[0], $head, true) : null;
        [$lotC, $nameC, $priceC, $statusC] = [$col(['lotnumber']), $col(['lotname']), $col(['finalbid', 'finalprice', 'hammerprice']), $col(['status', 'salestatus'])];
        $bidC = $col(['currentbid']); // an unsold horse's standing bid (August 2026)
        $out = [];
        foreach ($rows as $r) {
            $lot = trim($r[$lotC] ?? '');
            if (! preg_match('/^\d{1,4}$/', $lot) || trim($r[$nameC] ?? '') === '') {
                continue; // "Catalog Session" headings, totals
            }
            $price = trim($r[$priceC] ?? '') !== '' ? $r[$priceC] : ($bidC !== null ? ($r[$bidC] ?? '') : '');
            $out[] = self::row($lot, $r[$nameC], $price, $r[$statusC] ?? '');
        }

        return array_values(array_filter($out));
    }

    /** "29  "Big John" - Stunning 2 Year Old ... Gelding, Quiet &    16,000    Glen Beachey", wrapped lines after it. */
    private static function table(string $text): array
    {
        $out = [];
        $lines = preg_split('/\n/', $text);
        foreach ($lines as $i => $line) {
            if (! preg_match('/^\s*(\d{1,3})\s+(\S.*)$/', $line, $m)) {
                continue;
            }
            $cells = preg_split('/\s{2,}/', trim($m[2]));
            $name = array_shift($cells);
            $rest = implode('  ', $cells);
            $next = $lines[$i + 1] ?? '';
            $status = preg_match('/\b(Unsold|Passed|No Sale)\b/i', $rest.' '.(preg_match('/^\s*\d{1,3}\s/', $next) ? '' : $next), $s) ? $s[1] : 'Sold';
            preg_match('/(?:^|\s)\$?(\d{1,3}(?:,\d{3})+|\d{3,6})(?:\.\d\d)?(?:,|\s|$)/', ' '.$rest.' ', $p);
            $out[] = self::row($m[1], $name, $p[1] ?? '', $status);
        }

        return array_values(array_filter($out));
    }

    /** Top sellers in prose: "Lot 49 "Watch Red Bo CD" consigned by ... - $20,000", "Lot 51 Smokin Little Houston (Ranch) - $15,250". */
    private static function summary(string $text): array
    {
        $out = [];
        $lines = preg_split('/\n/', $text);
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (! preg_match('/\b(?:Lot|HIP)\s*#?\s*(\d{1,3})[A-Z]?\b[\s,–-]*(.*)$/iu', $line, $m)) {
                continue;
            }
            $tail = $m[2].(str_contains($line, '$') ? '' : ' '.trim($lines[$i + 1] ?? '')); // "... sold for" / "$15,750 to ..."
            if (! preg_match('/\$\s?([\d,]{3,9})/', $tail, $p) && ! preg_match('/\$\s?([\d,]{3,9})[^$]*\b(?:Lot|HIP)\s*#?\s*'.$m[1].'\b/i', $line, $p)) {
                continue; // the price may come first: "Top Selling Yearling: $6100, HIP #41 - CAT WITH PISTOLS"
            }
            if (preg_match('/^[“"”]([^“"”]+)[“"”]\s*(.*)$/u', $tail, $q)) {
                [$name, $desc] = [$q[1], $q[2]];
            } elseif (preg_match('/^([A-Z][A-Za-z0-9\' ]{2,40}?)\s*(?:\(|\s-\s|\s–\s|\sconsigned\b|,|\sfor\s)/u', $tail, $q)) {
                [$name, $desc] = [$q[1], ''];
            } else {
                continue; // "LOT 35 – $18,000": no name
            }
            $desc = preg_replace('/\s*(?:[-–]\s*)?\$.*$|\b(?:from|consigned|sold for|headed to|trained by|to)\b.*$/iu', '', $desc); // never the consignor or buyer
            $desc = trim(preg_replace('/^[\s,–-]+/u', '', $desc));
            if (! preg_match('/\b(?:19|20)\d\d\b|\byears?\b|\byr\b/i', $desc)) {
                $desc = ''; // "- Clint Jones of Split Fort Outfitters": a consignor, not the horse
            }
            $out[] = self::row($m[1], '"'.$name.'" - '.$desc, $p[1], 'Sold');
        }
        $best = [];
        foreach (array_filter($out) as $r) {
            $best[$r['lot']] ??= $r;
        }

        return array_values($best);
    }

    private static function row(string $lot, string $cell, string $price, string $status): ?array
    {
        $cell = trim(preg_replace(['/\*\*[^*]+\*\*/', '/\s+/'], ['', ' '], $cell));
        if (preg_match('/^[“"”]([^“"”]+)[“"”]\s*[-–]?\s*(.*)$/u', $cell, $m)) {
            [$name, $desc] = [$m[1], $m[2]];
        } else {
            [$name, $desc] = array_pad(preg_split('/\s+[-–]\s+/', $cell, 2), 2, ''); // an open consignment: "Hoss Cartwright - 14 Year Old Paint Gelding"
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            return null;
        }
        $cents = ($p = (int) preg_replace('/[^\d]/', '', preg_replace('/\.\d\d$/', '', trim($price)))) > 0 ? $p * 100 : null;
        $sold = ! preg_match('/unsold|passed|no sale/i', $status);

        return ['lot' => $lot, 'name' => $name, 'description' => trim($desc), 'price_cents' => $cents, 'status' => $sold ? ($cents ? 'sold' : 'catalog') : ($cents ? 'high_bid' : 'no_sale')];
    }
}
