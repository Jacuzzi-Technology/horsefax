<?php

namespace Tests\Feature;

use App\Harvest\TopekaHarvester;
use App\Harvest\TopekaParser;
use Tests\TestCase;

/** Topeka Livestock Auction catalogs and market reports: facts only, prices only when the report names the same horse (all names fictional). */
class TopekaTest extends TestCase
{
    private function catalogText(): string
    {
        return <<<'TXT'
          Monday, March 16, 2026
Lot 1 — “SAMPLE ACRES DOLLY” M123456                                        Belgian Mare
  Foaled: May 4, 2016. Blonde, stripe, white mane and tail. Bred by Sample Breeder, Sample, IN
                                   Sample Astro                 Sample Penn
   Sample Don                                                   Sample Monique
                                   Sample Daisy                 Sample Warrior
                                                                Sample Farceur
                                   Sample Buck                  Sample Buckshot
   Sample Judy                                                  Sample Dolly
                                   Sample Sharon                Sample Duke
                                                                Sample Jane
Been in all farm equipment. Reason for selling is too many horses.

                  Consigned by SAMPLE CONSIGNOR • Sample, MI                $____________
Lot 2 — “SAMPLE BLAZE (AKA BUDDY)”                                          Belgian Gelding
  Foaled: 6-Jun-21. Red sorrel.

Nice gelding.
                  Consigned by OTHER CONSIGNOR • Sample, IN                $____________
Lot 3 — Pending                                                             Belgian Mare
Lot 4 — “SAMPLE CAPTAIN “ACE”” S12345                                      Belgian Stallion
  Foaled: April 2, 2019. Bay.
TXT;
    }

    public function test_catalog_lots_with_pedigree_and_no_people(): void
    {
        $lots = TopekaParser::catalog($this->catalogText());
        $this->assertCount(3, $lots);
        [$a, $b, $c] = $lots;
        $this->assertSame(['Sample Captain', 'S12345'], [$c['name'], $c['registration_number']]); // the nested nickname is dropped
        $this->assertSame(['1', 'Sample Acres Dolly', 'M123456', 'Belgian', 'mare', 2016, '2016-05-04', 'Blonde'],
            [$a['lot'], $a['name'], $a['registration_number'], $a['breed'], $a['sex'], $a['foal_year'], $a['birth_date'], $a['color']]);
        $this->assertSame(['Sample Don', 'Sample Judy', 'Sample Buck', 'Sample Jane'], [$a['pedigree']['s'], $a['pedigree']['d'], $a['pedigree']['ds'], $a['pedigree']['ddd']]);
        $this->assertCount(14, $a['pedigree']);
        $this->assertSame(['Sample Blaze', 2021, 'gelding', null], [$b['name'], $b['foal_year'], $b['sex'], $b['registration_number']]); // the AKA barn name is dropped
        foreach (['CONSIGNOR', 'Breeder', 'farm equipment'] as $gone) {
            $this->assertStringNotContainsString($gone, json_encode($lots));
        }
        $sale = TopekaParser::sale('topeka_2026SpringDraftCatalog_Web.pdf', $this->catalogText());
        $this->assertSame(['draft-spring-2026', 'Topeka Livestock Auction Spring 2026 Draft Horse Sale', '2026-03-16'], [$sale['key'], $sale['name'], $sale['date']]);
    }

    public function test_report_prices_attach_only_to_the_same_horse(): void
    {
        $report = "    TOPEKA FALL DRAFT HORSE AUCTION\n   Coming soon: the Seymour sale\n                SPRING DRAFT 2026\nBelgian Mares\n"
            ."   1   SAMPLE, CONSIGNOR        SAMPLE, IN     SAMPLE ACRES DOLLY        10 YR REG BEL MARE     BUYER, SAMPLE      SAMPLE,OH     55000\n"
            ." 2   SAMPLE, CONSIGNOR        SAMPLE, IN     4 YR UNREG BEL GELD       BUYER, OTHER      SAMPLE,OH     7100\n";
        $sale = TopekaParser::sale('Market_Report_2026ToPrint.pdf', $report);
        $this->assertSame('draft-spring-2026', $sale['key']); // a mention of the Seymour sale doesn't make it the Seymour report
        $rows = TopekaParser::report($report);
        $this->assertSame([['1', 'SAMPLE ACRES DOLLY', 5500000], ['2', 'BUYER, OTHER', 710000]], array_map(fn ($r) => [$r['lot'], $r['name'], $r['price_cents']], $rows));
        $prices = TopekaParser::pricesFor($sale, [['sale' => $sale, 'rows' => $rows, 'info' => ['url' => 'https://example.com/r.pdf']]]);
        // Lot 2's "name" column is really the buyer (the horse is unnamed): it never matches the catalogued horse, so no price and no name kept.
        $this->assertTrue(TopekaParser::sameName($prices['1']['name'], 'Sample Acres Dolly'));
        $this->assertFalse(TopekaParser::sameName($prices['2']['name'], 'Sample Blaze'));
        $this->assertSame([null, 'catalog', 'report', null], [TopekaHarvester::kind('Consignment%20Form%202026.pdf'), TopekaHarvester::kind('topeka_2026SpringDraftCatalog_Web.pdf'), TopekaHarvester::kind('2025%20Seymour%20Market%20Report.pdf'), TopekaHarvester::kind('Ad%202026.pdf')]);
    }
}
