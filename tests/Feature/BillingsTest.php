<?php

namespace Tests\Feature;

use App\Harvest\BillingsHarvester;
use App\Harvest\BillingsParser;
use Tests\TestCase;

/** Billings Livestock catalog and Top-list reading (fictional horses). */
class BillingsTest extends TestCase
{
    public function test_descriptors_dates_and_top_list_lines(): void
    {
        $this->assertSame(['foal_year' => 2024, 'registry' => 'AQHA', 'color' => 'Bay', 'sex' => 'gelding'], BillingsParser::descriptor('2024 AQHA Bay Gelding'));
        $this->assertSame(['foal_year' => 2018, 'registry' => null, 'color' => 'Frosted Chestnut Mini Appaloosa', 'sex' => 'mare'], BillingsParser::descriptor('2018 Grade Frosted Chestnut Mini Appaloosa Mare'));
        // Catalogs before about 2022 print two-digit years.
        $this->assertSame(['foal_year' => 2007, 'registry' => 'AQHA', 'color' => 'Bay Roan', 'sex' => 'gelding'], BillingsParser::descriptor('07 AQHA Bay Roan Gelding'));
        $this->assertSame(['foal_year' => 1998, 'registry' => null, 'color' => 'Sorrel', 'sex' => 'mare'], BillingsParser::descriptor('98 Grade Sorrel Mare'));
        $this->assertSame(['2026-10-23', 'day'], BillingsHarvester::saleDate('oct26catweb.pdf', "Welcome\nOCTOBER 23-24-25 FALL SPECIAL"));
        $this->assertSame(['2025-02-01', 'month'], BillingsHarvester::saleDate('febcat25web2.pdf', 'no date here'));
        $this->assertTrue(BillingsHarvester::isTopList('SEPTEMBER-2026-TOP-20.pdf'));

        $text = "BILLINGS LIVESTOCK TOP 20 LIST\n September 25, 26, & 27, 2026\n"
            ."2     31,000 220    \"Sample Morning Jac\" 2017 AQHA Bay Gelding x Sample Joe Jack x Sample Jack Red            Some Seller; Bernard, IA      Some Buyer; Spanish Fork, UT\n";
        $list = BillingsParser::topList([], $text);
        $this->assertSame('2026-09-25', $list['date']);
        $r = $list['rows'][0];
        $this->assertSame(['220', 'Sample Morning Jac', 3100000, 2017, 'Sample Joe Jack', 'Sample Jack Red'], [$r['hip'], $r['name'], $r['price_cents'], $r['foal_year'], $r['sire'], $r['dam_sire']]);
        $this->assertStringNotContainsString('Seller', json_encode($list));
    }

    /** Older printed catalogs (2013-2021): section labels, padded words, glued years, and HIP markers inside ad copy. */
    public function test_older_catalog_layouts(): void
    {
        $line = function (float $x, float $y, string $text) {
            $words = [];
            foreach (explode(' ', $text) as $w) {
                $words[] = [$x, $y, $x + 6 * mb_strlen($w), $w];
                $x += 6 * mb_strlen($w) + 4;
            }

            return $words;
        };
        $nb = "\u{00A0}\u{00A0}";
        $page = array_merge(
            // A section label between the HIP marker and the name; words padded with non-breaking spaces (2021).
            [[36, 100, 60, 'HIP'.$nb], [66, 100, 80, '7'.$nb]], $line(36, 112, 'Rope Horse'), $line(36, 124, 'Sample Dun Dandy'),
            $line(36, 136, '4999001'), $line(36, 148, '07AQHA Dun Gelding'), $line(36, 160, 'Sample Consignor Town, MT'),
            // An ad on the same page: "HIP 9" in its copy, no foal year or sex under it.
            $line(300, 100, 'HIP 9'), $line(300, 112, 'She sells, come see her'), $line(300, 124, 'at the preview friday.'),
            // The real hip 9.
            $line(300, 300, 'HIP 9'), $line(300, 312, 'Sample Gray Lady'), $line(300, 324, '2015 Grade Gray Mare'), $line(300, 336, 'Sample Seller Town, WY'),
        );
        $hips = collect(BillingsParser::catalog([$page]))->keyBy('hip');
        $this->assertSame([7, 9], $hips->keys()->sort()->values()->all());
        $this->assertSame(['Sample Dun Dandy', '4999001', 2007, 'AQHA', 'Dun', 'gelding'],
            [$hips['7']['name'], $hips['7']['reg'], $hips['7']['foal_year'], $hips['7']['registry'], $hips['7']['color'], $hips['7']['sex']]);
        $this->assertSame(['Sample Gray Lady', 2015, 'mare'], [$hips['9']['name'], $hips['9']['foal_year'], $hips['9']['sex']]);
        $this->assertStringNotContainsString('Consignor', json_encode($hips));
        $this->assertSame('2013-02', BillingsHarvester::coverMonth("\"February Special Catalog Sale 2013\"\n February 22-23-24"));
        $this->assertSame(['2022-05-28', 'day'], BillingsHarvester::saleDate('may22cat', 'Saturday, May28 2022'));
    }
}
