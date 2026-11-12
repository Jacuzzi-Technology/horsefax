<?php

namespace Tests\Feature;

use App\Harvest\BuckeyeClassicHarvester;
use App\Harvest\TurnbullHarvester;
use Tests\TestCase;

/** Buckeye Classic results pages and the Great American Ranch & Trail sale's catalog lines and High Sellers (all names fictional). */
class BuckeyeTurnbullTest extends TestCase
{
    public function test_buckeye_results_rows_keep_facts_and_drop_people(): void
    {
        $row = fn ($hip, $horse, $parents, $price) => '<tr> <td valign="top" align="right" width="4%"> <font face="arial" size="2"><b>'.$hip.'</b>&nbsp;&nbsp;</font></td>
         <td valign="top" width="20%"><font face="arial" size="2"><b>'.$horse.'</b></font></td>
         <td valign="top" width="23%">'.$parents.'</td>
		  <td valign="top" width="20%">Sample Farm, Agent for&nbsp;Sample Person</td>
		  <td valign="top" width="27%">Sample Buyer,&nbsp;Berlin,&nbsp;OH</td>
		  <td valign="top" width="6%">'.$price.'</td>
        </tr>';
        $html = '<h3 class="p3">2025 Buckeye Classic Yearling Sale Results - Hips 1-253<br> Tuesday, October 7 - 10 a.m. Start Time </h3><table>'
            .str_replace('</b></font>', '</b> (Bay&nbsp;Colt)</font>', $row('1', 'Sample Express', 'Sample Miki - Sample Eden', '$4,500'))
            .str_replace('</b></font>', '</b> (Brown&nbsp;Filly)</font>', $row('6', 'Sample Paige', 'Sample Range - Sample Explosive', 'Out'))
            .'</table>';
        $sale = BuckeyeClassicHarvester::parse($html, 2025);

        $this->assertSame('2025-10-07', $sale['date']);
        $this->assertSame(['hip' => '1', 'name' => 'Sample Express', 'sex' => 'colt', 'color' => 'Bay', 'sire' => 'Sample Miki', 'dam' => 'Sample Eden', 'status' => 'sold', 'price_cents' => 450000], $sale['rows'][0]);
        $this->assertSame(['withdrawn', null, 'filly'], [$sale['rows'][1]['status'], $sale['rows'][1]['price_cents'], $sale['rows'][1]['sex']]);
        $this->assertStringNotContainsString('Buyer', json_encode($sale));
        $this->assertStringNotContainsString('Agent', json_encode($sale));
    }

    public function test_turnbull_catalog_lines(): void
    {
        $this->assertSame(['foal_year' => 2022, 'color' => 'Grey Roan', 'sex' => 'gelding', 'registry' => 'AQHA', 'breed' => 'Quarter Horse', 'registered' => true],
            TurnbullHarvester::describe('2022 AQHA Grey Roan Gelding'));
        $this->assertSame(['foal_year' => 2008, 'color' => null, 'sex' => 'gelding', 'registry' => null, 'breed' => 'Gypsy Vanner', 'registered' => null],
            TurnbullHarvester::describe('2008 Gypsy Vanner Gelding'));
        $this->assertSame('APHA', TurnbullHarvester::describe('2018 APHA Palomino Mare')['registry']);
    }

    public function test_turnbull_high_sellers(): void
    {
        $html = '<p>2026 Great American Ranch and Trail Horse Sale HIGH SELLERS</p><p>Reserve High Selling Horse<br>Hip #22 Sample Papas Ike “Marshal”<br>Sample Farm LLC<br>$55,000</p>'
            .'<p>Past Top Selling Horses</p><p>2025 High Selling Horse<br>Hip #48 “R.J.” SAMPLE LAST TIGER<br>Selling for $78,000<br>Consigned by Sample Equine</p>'
            .'<p>2023 High Selling Horse<br>Hawk – Hip #30<br>Selling for $77,000</p>';
        $p = TurnbullHarvester::highSellers($html, 2026);

        $this->assertSame(['name' => 'SAMPLE PAPAS IKE', 'price_cents' => 5500000], $p[2026]['22']);
        $this->assertSame(['name' => 'SAMPLE LAST TIGER', 'price_cents' => 7800000], $p[2025]['48']);
        $this->assertSame(['name' => 'HAWK', 'price_cents' => 7700000], $p[2023]['30']);
    }
}
