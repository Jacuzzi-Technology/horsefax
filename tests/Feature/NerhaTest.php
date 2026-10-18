<?php

namespace Tests\Feature;

use App\Harvest\NerhaHarvester;
use App\Models\Horse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Nebraska Ranch Horse Association stallion directory (fictional stallion in the site's layout). */
class NerhaTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_stallions_get_sire_and_dam_and_no_fees_or_owners(): void
    {
        Storage::fake('local');
        Http::fake([
            NerhaHarvester::LIST => Http::response('<a href="https://nebraskarha.org/sample-jack/">Read More »</a><a href="https://nebraskarha.org/stalliondirectory/2/">2</a>'),
            'nebraskarha.org/stalliondirectory/2/' => Http::response(''),
            'nebraskarha.org/sample-jack/' => Http::response('<h1>Sample Jack</h1><div><p>Sample Jack</p><p>2013</p><p>AQHA</p><p>Red Roan</p><p>SAMPLE WARRIOR x SAMPLE WATCH</p><p>2025 Stud fee: $650</p><p>Owned by: Sample Ranch</p></div>'),
        ]);
        $out = (new NerhaHarvester(0))->run();
        $this->assertSame([1, 1], [$out['pages'], $out['created']]);
        $h = Horse::where('registered_name', 'Sample Jack')->sole();
        $this->assertSame([2013, 'Red Roan', 'Quarter Horse'], [(int) $h->birth_year, $h->color, $h->breed]);
        $this->assertEqualsCanonicalizing(['s' => 'Sample Warrior', 'd' => 'Sample Watch'], $h->pedigree()->pluck('name', 'position')->all());
        $this->assertStringNotContainsString('Sample Ranch', json_encode(Horse::all()));
    }
}
