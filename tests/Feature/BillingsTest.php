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
}
