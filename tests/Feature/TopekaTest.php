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
Lot 5 — “SAMPLE ANTICIPATION (ANNIE)” M22222                               Belgian Mare
  Foaled: April 2, 2019. Bay.
Lot 4 — “SAMPLE CAPTAIN “ACE”” S12345                                      Belgian Stallion
  Foaled: April 2, 2019. Bay.
TXT;
    }

    public function test_catalog_lots_with_pedigree_and_no_people(): void
    {
        $lots = TopekaParser::catalog($this->catalogText());
        $this->assertCount(4, $lots);
        [$a, $b, $d, $c] = $lots;
        $this->assertSame('Sample Anticipation', $d['name']);
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

    public function test_seymour_layout_keeps_chart_names_beside_labels_and_drops_people(): void
    {
        $text = <<<'TXT'
#1 – Sample Jordan                                                                    $___________
                                                                                   Percheron Gelding
Consigned by: Sample Farms • Sample, ON                                                Reg # 320521
Markings: Black, no white markings.                                            Foaled: April 27, 2023
 Bred by: Sample Breeder, Sample, OH        Sample Empire Sire          Sample Grandsire
 Sample Sire Line                                                       Sample Granddam
                                            Sample Mid Dam              Sample Third One
                                                                        Sample Third Two
                                            Sample Mid Three            Sample Third Three
 Sample Dam Line                                                        Sample Third Four
                                            Sample Mid Four             Sample Third Five
Remarks: Lead horse talent, no apologies.                               Sample Third Six
                     Seymour Draft Horse Sale Page 9
#2 – Sample Patience $___________
                                                                                       Percheron Mare
Consigned by: Sample Farms • Sample, ON                                                 Reg # Pending
Markings: Black.                                                                Foaled: 2022
#3 –                                                                           $___________
                                                                                   Belgian Gelding
Consigned by: Other Farms • Sample, IN
Markings: Sorrel.                                                               Foaled: 3 year old
Remarks: Broke.
TXT;
        $lots = TopekaParser::hashCatalog($text);
        $this->assertSame(['1', '2'], array_column($lots, 'lot')); // lot 3 has no name
        [$a, $b] = $lots;
        $this->assertSame(['Sample Jordan', '320521', 'Percheron', 'gelding', 2023, '2023-04-27', 'Black'],
            [$a['name'], $a['registration_number'], $a['breed'], $a['sex'], $a['foal_year'], $a['birth_date'], $a['color']]);
        $this->assertSame(['Sample Sire Line', 'Sample Dam Line', 'Sample Empire Sire', 'Sample Grandsire', 'Sample Third Six'],
            [$a['pedigree']['s'], $a['pedigree']['d'], $a['pedigree']['ss'], $a['pedigree']['sss'], $a['pedigree']['ddd']]); // names beside "Bred by" and "Remarks" kept
        $this->assertSame([null, 2022, []], [$b['registration_number'], $b['foal_year'], $b['pedigree']]); // "Pending" is not a number
        foreach (['Sample Farms', 'Sample Breeder', 'talent'] as $gone) {
            $this->assertStringNotContainsString($gone, json_encode($lots));
        }
    }

    public function test_speed_sale_pages_strip_race_marks(): void
    {
        $page = <<<'TXT'
                            Consigned by: Sample Consignor, Sample, IN

2                                        SAMPLE SECRET K                                                     Aisle
                                                 (INDIANA ELIGIBLE)
                              Brown Filly - Foaled April 26, 2025 - Registration #54R55
                                                                                                               5
                                            Microchip #985141010333861

                                                                               Sample Yankee 3, 1:52.2
                                                       Sample Hill 3, 1:50.1 ..............
                                                                               Sample Blondie 3, 1:59.4f
                  Sample Rebuff 3, 1:49.4 .................
                                                                               Sample Glide 3, 1:55.1
                                            Sample Madness p, 3, 1:58.0 ........
                                                                               Sample Miss p,1:56.0
SAMPLE SECRET K
                                                                               Sample Lobell 3, 1:55.3
                                            Sample Hall 3, 1:53.4 ............
                                                                               Sample Angus 3, 2:03.1f
          Sample Getinon 4, 1:54.4f......
                                                                               Sample Glide Two p,T1:55.0
                                            Sample Mission 3, 1:58.1
                                                                               Sample Goal 3, 2:00.0

1st dam
SAMPLE GETINON 4, 1:54.4f ($42,000) 3 wins, by SAMPLE HALL 3, 1:53.4.
TXT;
        $lots = TopekaParser::speedCatalog("cover\f".$page);
        $this->assertCount(1, $lots);
        $l = $lots[0];
        $this->assertSame(['2', 'Sample Secret K', '54R55', 'USTA', 'Standardbred', 'filly', 2025, 'Brown'],
            [$l['lot'], $l['name'], $l['registration_number'], $l['registry'], $l['breed'], $l['sex'], $l['foal_year'], $l['color']]);
        $this->assertSame(['Sample Rebuff', 'Sample Getinon', 'Sample Hill', 'Sample Madness', 'Sample Mission', 'Sample Yankee', 'Sample Miss', 'Sample Goal'],
            [$l['pedigree']['s'], $l['pedigree']['d'], $l['pedigree']['ss'], $l['pedigree']['sd'], $l['pedigree']['dd'], $l['pedigree']['sss'], $l['pedigree']['sdd'], $l['pedigree']['ddd']]);
        $this->assertStringNotContainsString('Consignor', json_encode($lots));
        $this->assertSame('2026-11-09', TopekaParser::sale('tla_2026SpeedSaleCatalog_Web.pdf', "     November 9th-10th, 2026\n Foaled May 28, 2025")['date']);
    }
}
