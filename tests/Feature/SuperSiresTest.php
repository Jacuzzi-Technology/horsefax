<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Harvest\SuperSiresHarvester;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\ShowAppearance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Super Sires stallion and foal pages (fictional horses in the site's markup). */
class SuperSiresTest extends TestCase
{
    use RefreshDatabase;

    private function cell(string $class, string $name): string
    {
        return '<div class="'.$class.'"><a class="name" href="#">'.$name.'</a></div>';
    }

    private function side(array $n): string
    {
        $item = fn ($p, $s, $d) => '<div class="pedigree-column2-item">'.$this->cell('pedigree-column2-name', $p).'<div class="pedigree-column3">'.$this->cell('sire-f0', $s).$this->cell('dam-f0', $d).'</div></div>';

        return '<div class="rst-pedigree-matters-item">'.$this->cell('pedigree-column1', $n[0]).'<div class="pedigree-column2">'.$item($n[1], $n[2], $n[3]).$item($n[4], $n[5], $n[6]).'</div></div>';
    }

    private function stallion(): string
    {
        return '<html><body><h1 class="et_pb_module_heading">Sample Blazer</h1><div class="info-stallion"><p>1999 aqha Bay Stallion</p><p>[ Sample Sire x Sample Dam ]</p>'
            .'<div class="owned-standing">Owned by Sample Owner.<br>Standing at Sample Ranch.</div></div><div class="rst-pedigree-matters-content">'
            .$this->side(['Sample Sire', 'Sample SS', 'Sample SSS', 'Sample SSD', 'Sample SD', 'Sample SDS', 'Sample SDD'])
            .$this->side(['Sample Dam', 'Sample DS', 'Sample DSS', 'Sample DSD', 'Sample DD', 'Sample DDS', 'Sample DDD']).'</div></body></html>';
    }

    private function foal(): string
    {
        return '<html><body><h1 class="et_pb_module_heading">Sample Foal</h1><div class="rst-info-foals"><p> Sample Blazer x Sample Mare (Sample Mares Sire)<br /> Owned by Sample Owner </p>'
            .'<div class="rst-info-foals-column"><div class="rst-info-foals-column-inner"><div><span>DOB</span><p>03/02/2021</p></div><div><span>Gender</span><p>Mare</p></div><div><span>aqha</span><p>9999001</p></div></div></div></div>'
            .'<div class="rst-foal-earnings"><table><tr><th>#</th><th>Show</th><th>Class</th><th>Purse type</th><th>Date</th><th>Earnings</th></tr>'
            .'<tr><td>3</td><td>2022 Sample Congress</td><td>Sample Longe Line</td><td>Breeders</td><td><span class="date">03/05/2025</span></td><td align="right">$1,160.00</td></tr></table></div>'
            .'<div class="rst-foal-ownership-details"><table><tr><td> Sample Owner</td></tr></table></div></body></html>';
    }

    public function test_stallions_and_foals_become_records_with_pedigrees_and_no_people(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned Foal', 'birth_precision' => 'unknown']);
        Http::fake([
            'supersires.org/sitemap_index.xml' => Http::response('<loc>https://supersires.org/foals-sitemap.xml</loc><loc>https://supersires.org/stallion-sitemap.xml</loc>'),
            'supersires.org/stallion-sitemap.xml' => Http::response('<loc>https://supersires.org/stallion/sample-blazer/</loc>'),
            'supersires.org/foals-sitemap.xml' => Http::response('<loc>https://supersires.org/foals/sample-foal/</loc><loc>https://supersires.org/foals/sample-owned-foal/</loc>'),
            'supersires.org/stallion/sample-blazer/' => Http::response($this->stallion()),
            'supersires.org/foals/sample-foal/' => Http::response($this->foal()),
            'supersires.org/foals/sample-owned-foal/' => Http::response(str_replace(['Sample Foal', '9999001'], ['Sample Owned Foal', '9999002'], $this->foal())),
        ]);

        $out = (new SuperSiresHarvester(0))->run();
        $this->assertSame([3, 2, 1], [$out['pages'], $out['created'], $out['skipped_owned']]);

        $blazer = Horse::where('registered_name', 'Sample Blazer')->sole();
        $this->assertSame(['published', 'unverified', 'stallion', 1999, 'Bay', 'Quarter Horse'], [$blazer->source, $blazer->identity_status, $blazer->sex, (int) $blazer->birth_year, $blazer->color, $blazer->breed]);
        $this->assertSame(14, $blazer->pedigree()->count());
        $this->assertSame('Sample SDD', $blazer->pedigree()->where('position', 'sdd')->value('name'));
        $this->assertNotNull(Horse::where('registered_name', 'Sample DDD')->first()); // every horse in the chart gets a record
        $this->assertSame('Sample SS', Horse::where('registered_name', 'Sample Sire')->first()->pedigree()->where('position', 's')->value('name'));

        $foal = Horse::where('registered_name', 'Sample Foal')->sole();
        $this->assertSame(['mare', '2021-03-02'], [$foal->sex, $foal->birth_date->toDateString()]);
        $this->assertSame('9999001', HorseIdentifier::where('horse_id', $foal->id)->value('value'));
        $this->assertEqualsCanonicalizing(['s' => 'Sample Blazer', 'd' => 'Sample Mare', 'ds' => 'Sample Mares Sire'], $foal->pedigree()->pluck('name', 'position')->all());
        $this->assertSame($blazer->id, $foal->pedigree()->where('position', 's')->value('linked_horse_id')); // linked to the stallion's record
        $this->assertSame(['3', 116000, 'Sample Blazer'], [ShowAppearance::first()->placing, (int) ShowAppearance::first()->earnings_cents, ShowAppearance::first()->sire_name]);

        $owned = Horse::where('registered_name', 'Sample Owned Foal')->sole();
        $this->assertSame(0, $owned->pedigree()->count());
        $this->assertStringNotContainsString('Sample Owner', json_encode([Horse::all(), \App\Models\PedigreeEntry::all(), ShowAppearance::all()]));
        $this->assertStringNotContainsString('Sample Ranch', json_encode(Horse::all()));

        $again = (new SuperSiresHarvester(0))->run();
        $this->assertSame(0, $again['pages']);
    }
}
