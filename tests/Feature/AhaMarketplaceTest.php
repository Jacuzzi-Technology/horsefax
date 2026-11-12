<?php

namespace Tests\Feature;

use App\Harvest\AhaMarketplaceHarvester;
use App\Models\Horse;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AHA marketplace ads: identity with numbered pedigree, asking prices held, stud fees and contact details never stored (names fictional). */
class AhaMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private function list(string $rows): string
    {
        return '<table id="searchResults"><tbody class="small80">'.$rows.'</tbody></table>';
    }

    private function row(string $horseId, string $caid, string $name, string $number, string $foaled, string $sex, string $price): string
    {
        return '<tr> <td><a href="/login/marketplace/classifieds/marketplaceSearchResult?horseId='.$horseId.'&caid='.$caid.'" class="showOverlay">'.$name.'</a></td> <td>'.$number.'</td>'
            .'<td style="text-align: center">Yes</td> <td>'.$foaled.'</td> <td>'.$sex.'</td> <td style="text-align: center">MT</td> <td style="text-align: right">'.$price.'</td> </tr>';
    }

    private function ad(string $name, string $number, string $foaled, string $colorSex, string $price): string
    {
        $d = fn ($v) => '<div>'.$v.'</div>';

        return '<html><body>'.$d($name.' '.$number).$d('Foaled').$d($foaled).$d('Gender').$d($colorSex).$d('Parentage').$d('DNA Typed. Parentage qualified.')
            .$d('Sire').$d('AHR*600001').$d('SAMPLE SIRE KA').$d('Dam').$d('AHR*600002').$d('SAMPLE DAM-I').$d("Dam's Sire").$d('AHR*500003').$d('SAMPLE DAMSIRE')
            .$d('Breeder').$d('SAMPLE ARABIANS').$d('Groups').$d('Ad Details').$d('Horse For Sale').$d('Price:').$d($price)
            .$d('Description:').$d('Seller prose never kept, call 555-555-0100').$d('Height:').$d('15.1 Hands').$d('Contact Info:').$d('SAMPLE SELLER').$d('555.555.0100').$d('SELLER@EXAMPLE.COM').'</body></html>';
    }

    public function test_ads_load_with_numbered_pedigrees_and_no_contact_details(): void
    {
        Http::fake(function ($request) {
            $u = $request->url();

            return match (true) {
                str_contains($u, 'cpid=18') => Http::response($this->list($this->row('101', '9001', 'AA-SAMPLE MARE', 'AHR*692103', '5/15/2025', 'MARE', '6500'))),
                str_contains($u, 'cpid=19') => Http::response($this->list($this->row('102', '9002', 'SAMPLE STALLION', 'HAHR*2A380750', '4/06/2020', 'STALLION', '750'))),
                str_contains($u, 'caid=9001') => Http::response($this->ad('AA-SAMPLE MARE', 'AHR*692103', '05/15/2025', 'GREY MARE', '6500')),
                str_contains($u, 'caid=9002') => Http::response($this->ad('SAMPLE STALLION', 'HAHR*2A380750', '04/06/2020', 'BAY STALLION', '750')),
                default => Http::response('Not found', 404),
            };
        });
        $h = new AhaMarketplaceHarvester(0, null, sys_get_temp_dir().'/aha-test-'.uniqid());
        $this->assertSame(['ads' => 2, 'fetched' => 2, 'cached' => 0], $h->collect());
        $out = $h->load();
        $this->assertSame([2, 2, 1], [$out['ads'], $out['created'], $out['asking_prices']]);

        $mare = Horse::where('registered_name', 'AA-Sample Mare')->firstOrFail();
        $this->assertSame(['mare', 'Grey', 'Arabian', 'Sample Arabians', '2025-05-15'], [$mare->sex, $mare->color, $mare->breed, $mare->breeder, $mare->birth_date?->toDateString()]);
        $this->assertSame('692103', $mare->identifiers()->where('kind', 'registration')->value('value'));
        $sire = $mare->pedigree()->where('position', 's')->first();
        $this->assertSame(['Sample Sire Ka', '600001'], [$sire->name, $sire->registration_number]);
        $this->assertSame('Sample Dam-I', $mare->pedigree()->where('position', 'd')->value('name'));
        $this->assertNotNull(Horse::where('registered_name', 'Sample Sire Ka')->first()); // ancestors become records

        $lot = SaleLot::where('external_id', 'aha:9001')->firstOrFail();
        $this->assertSame(['asking', 650000, 'held', $mare->id], [$lot->status, $lot->price_cents, $lot->review_status, $lot->horse_id]);
        $this->assertNull(SaleLot::where('external_id', 'aha:9002')->first()); // a stud fee is not a sale price
        $this->assertSame('Half-Arabian', Horse::where('registered_name', 'Sample Stallion')->value('breed'));

        $all = json_encode([SaleLot::all(), Horse::all(), \Illuminate\Support\Facades\DB::table('horse_facts')->get()]);
        foreach (['555-0100', 'SELLER@EXAMPLE.COM', 'Sample Seller', 'prose'] as $gone) {
            $this->assertStringNotContainsStringIgnoringCase($gone, $all);
        }
        $this->assertSame(['ads' => 2, 'fetched' => 0, 'cached' => 2], $h->collect()); // a rerun fetches only new ads
    }

    public function test_names_keep_prefixes_and_numerals(): void
    {
        $this->assertSame('AA-Cinderella-I', AhaMarketplaceHarvester::caps('AA-CINDERELLA-I'));
        $this->assertSame(['AHA', '2A382587', 'Half-Arabian'], AhaMarketplaceHarvester::number('HAHR*2A382587'));
    }
}
