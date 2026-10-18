<?php

namespace Tests\Feature;

use App\Harvest\HighpointHarvester;
use App\Models\Horse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Highpoint Performance stallion pages (fictional horses in the site's layout). */
class HighpointTest extends TestCase
{
    use RefreshDatabase;

    private const TSV = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
        ."1\t1\t0\t0\t0\t0\t0\t0\t454\t189\t-1\t\n"
        ."5\t1\t1\t1\t1\t1\t274\t10\t50\t12\t95\tSAMPLE\n5\t1\t1\t1\t1\t2\t330\t10\t50\t12\t95\tGRANDSIRE\n"
        ."5\t1\t1\t1\t1\t1\t38\t36\t50\t12\t95\tSAMPLE\n5\t1\t1\t1\t1\t2\t95\t36\t50\t12\t95\tSIRE\n"
        ."5\t1\t1\t1\t1\t1\t215\t49\t10\t12\t40\ta\n"
        ."5\t1\t1\t1\t1\t1\t274\t60\t50\t12\t95\tSAMPLE\n5\t1\t1\t1\t1\t2\t331\t60\t50\t12\t95\tGRANDDAM\n"
        ."5\t1\t1\t1\t1\t1\t274\t117\t50\t12\t95\tSAMPLE\n5\t1\t1\t1\t1\t2\t336\t117\t50\t12\t95\tDAMSIRE\n"
        ."5\t1\t1\t1\t1\t1\t36\t143\t50\t12\t95\tSAMPLE\n5\t1\t1\t1\t1\t2\t102\t143\t50\t12\t95\tDAM\n"
        ."5\t1\t1\t1\t1\t1\t274\t168\t30\t12\t95\tNMA\n5\t1\t1\t1\t1\t2\t312\t168\t50\t12\t95\tSAMPLEDAMDAM\n";

    public function test_stallions_get_sire_dam_and_grandparents_and_no_stud_fee_or_contact(): void
    {
        Storage::fake('local');
        Horse::forceCreate(['horsefax_id' => 'HF-TEST-0001', 'registered_name' => 'NM A Sampledamdam', 'birth_precision' => 'unknown', 'source' => 'pedigree', 'identity_status' => 'unverified', 'created_by' => \App\Models\User::factory()->create()->id]);
        Http::fake([
            HighpointHarvester::LIST => Http::response('<nav><a href="https://highpointperformance.com/portfolio-items/not-a-stallion/">x</a></nav><main><a href="https://highpointperformance.com/portfolio-items/sample-boss/?portfolioCats=5">Sample Boss</a></main>'),
            'highpointperformance.com/portfolio-items/sample-boss/' => Http::response('<main><h2>SAMPLE BOSS</h2><p>2016 Sorrel Quarter Horse Stallion</p><p>Stud Fee: $2100 call 555-0100</p><p>Sire: SAMPLE SIRE</p><p>Dam: SAMPLE DAM</p>'
                .'<img title="SAMPLE BOSS Pedigree" src="https://highpointperformance.com/wp-content/uploads/sample-pedigree.png"></main>'),
            'highpointperformance.com/wp-content/uploads/sample-pedigree.png' => Http::response('PNG'),
        ]);
        Process::fake(['*' => Process::result(self::TSV)]);

        $out = (new HighpointHarvester(0))->run();
        $this->assertSame([1, 1], [$out['pages'], $out['created']]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'not-a-stallion'));
        $h = Horse::where('registered_name', 'Sample Boss')->sole();
        $this->assertSame(['published', 2016, 'Sorrel', 'Quarter Horse'], [$h->source, (int) $h->birth_year, $h->color, $h->breed]);
        $this->assertEqualsCanonicalizing(
            ['s' => 'Sample Sire', 'd' => 'Sample Dam', 'ss' => 'Sample Grandsire', 'sd' => 'Sample Granddam', 'ds' => 'Sample Damsire', 'dd' => 'NM A Sampledamdam'],
            $h->pedigree()->pluck('name', 'position')->all()
        );
        $this->assertSame('HF-TEST-0001', Horse::find($h->pedigree()->where('position', 'dd')->value('linked_horse_id'))?->horsefax_id);
        $this->assertStringNotContainsString('2100', json_encode(Horse::all()));
        $this->assertSame(0, (new HighpointHarvester(0))->run()['pages']);
    }
}
