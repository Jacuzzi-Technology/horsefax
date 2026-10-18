<?php

namespace Tests\Feature;

use App\Harvest\PremierSiresHarvester;
use App\Models\Horse;
use App\Models\ShowAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Premier Sires stallions, foal lookup and results (fictional horses in the site's formats). */
class PremierSiresTest extends TestCase
{
    use RefreshDatabase;

    public function test_stallions_foals_and_results_without_people(): void
    {
        Storage::fake('local');
        $row = fn ($foal, $sire, $dam) => ['1', 'premiersires', '', 'premiersires', '', $foal, 'P180017', 'AQHA / APHA', '2018', $sire, $dam, 'Nominator, Sample', 'Sample Owner One', 'Sample Owner Two', 'Western'];
        Http::fake([
            'premiersires.com/stallions/' => Http::response('<div><h3>Sample Machine</h3><p>2019 AQHA/APHA Stallion</p><p>Sample Good Machine x Sample Amble</p></div>'),
            'premiersires.com/horse-lookup/' => Http::response('<input type="hidden" id="wdtNonceFrontendServerSide_20" name="wdtNonceFrontendServerSide_20" value="abc123" />'),
            'premiersires.com/wp-admin/admin-ajax.php*' => Http::response(['draw' => 1, 'recordsTotal' => '2', 'data' => [$row('Sample Foal', 'Sample Machine', 'Sample Mare'), $row('Sample Filly', 'Sample Machine', 'Sample Other Mare')]]),
            'premiersires.com/results/' => Http::response('<a href="https://premiersires.com/thepremier-results-2025/">View Results</a>'),
            'premiersires.com/thepremier-results-2025/' => Http::response('<title>The Premier Futurity Results 2025</title><h5>3 YEAR OLD OPEN TRAIL STAKES</h5>'
                .'<h5><strong>Champion &#8211; $3,275 &#8211; Sample Foal (Sample Machine x Sample Mare) bred by Sample Breeder, owned by Sample Owner One and shown by Sample Rider</strong></h5>'),
        ]);

        $out = (new PremierSiresHarvester(0))->run();
        $this->assertSame([1, 2, 1], [$out['stallions'], $out['foals'], $out['results']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'admin-ajax') && $r['wdtNonce'] === 'abc123');

        $foal = Horse::where('registered_name', 'Sample Foal')->sole();
        $this->assertSame([2018, 'Quarter Horse'], [(int) $foal->birth_year, $foal->breed]);
        $this->assertEqualsCanonicalizing(['s' => 'Sample Machine', 'd' => 'Sample Mare'], $foal->pedigree()->pluck('name', 'position')->all());
        $stallion = Horse::where('registered_name', 'Sample Machine')->sole(); // the stallion's record is shared, not duplicated
        $this->assertSame('Sample Good Machine', $stallion->pedigree()->where('position', 's')->value('name'));
        $a = ShowAppearance::sole();
        $this->assertSame(['1', 327500, 'Sample Machine', 2025], [$a->placing, (int) $a->earnings_cents, $a->sire_name, (int) $a->event_year]);
        $this->assertStringNotContainsString('Sample Owner', json_encode([Horse::all(), ShowAppearance::all(), \App\Models\PedigreeEntry::all()]));
        $this->assertStringNotContainsString('Nominator', json_encode(Horse::all()));
        $this->assertStringNotContainsString('Sample Rider', json_encode(ShowAppearance::all()));
    }
}
