<?php

namespace Tests\Feature;

use App\Models\Horse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** hf:import-pedigrees: pedigrees read from stallion websites (fictional horses). */
class ImportPedigreesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deep_pedigree_gives_every_ancestor_a_record_with_its_own_lines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ped');
        file_put_contents($file, json_encode([
            ['name' => 'SAMPLE HOLIDAY', 'foal_year' => 2011, 'registry' => 'aqha', 'registration_number' => null, 'color' => 'bay', 'sex' => 'stallion', 'source_url' => 'https://example.test/holiday',
                'pedigree' => ['s' => 'SAMPLE BEST', 'd' => 'SAMPLE ON HOLIDAY', 'ss' => 'SAMPLE TOP', 'sd' => 'SAMPLE LADY', 'sss' => 'SAMPLE DEEP', 'bad' => 'x', 'dd' => '']],
            ['name' => '', 'source_url' => 'https://example.test/none', 'pedigree' => []],
        ]));
        $this->artisan('hf:import-pedigrees', ['file' => $file])->assertSuccessful();

        $h = Horse::where('registered_name', 'Sample Holiday')->sole();
        $this->assertSame([2011, 'Bay', 'Quarter Horse'], [(int) $h->birth_year, $h->color, $h->breed]);
        $this->assertSame(5, $h->pedigree()->count());
        $sire = Horse::where('registered_name', 'Sample Best')->sole();
        $this->assertEqualsCanonicalizing(['s' => 'Sample Top', 'd' => 'Sample Lady', 'ss' => 'Sample Deep'], $sire->pedigree()->pluck('name', 'position')->all());
        $this->assertNotNull(Horse::where('registered_name', 'Sample Deep')->first());
    }
}
