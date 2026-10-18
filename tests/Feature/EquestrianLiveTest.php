<?php

namespace Tests\Feature;

use App\Harvest\EquestrianLiveHarvester;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** EquestrianLive feeds (fictional entries in the published format). */
class EquestrianLiveTest extends TestCase
{
    use RefreshDatabase;

    private const JUMPER = '1|type=show+st=1+n1=Sample Fall Classic+s=10/1/2026+e=10/1/2026|type=class+st=1+hj=J+cn=501+d=501 0.90m Jumper+t=3+u=N+ta=80000+jta=51000+jspr=0'
        .'|type=j+B=1+st=5+HO=SAMPLE OWNER+HR=SAMPLE RIDER+H=SAMPLE SLOW CLEAR+N=USA+t=75.00+ti=75000+f=0+jt=40.00+jti=40000+jf=0'
        .'|type=j+B=2+st=6+HO=SAMPLE OWNER+HR=SAMPLE RIDER+H=SAMPLE FAST CLEAR+N=USA+t=79.00+ti=79000+f=0+jt=33.00+jti=33000+jf=0'
        .'|type=j+B=3+st=7+HO=SAMPLE OWNER+HR=SAMPLE RIDER+H=SAMPLE`S RAIL+N=USA+t=70.00+ti=70000+f=0+jt=31.00+jti=31000+jf=400'
        .'|type=j+B=4+st=8+HO=SAMPLE OWNER+HR=SAMPLE RIDER+H=SAMPLE TIME FAULT+N=USA+t=80.50+ti=80500+f=0+jt=+jti=9000009+jf=99900'
        .'|type=j+B=5+st=9+HO=SAMPLE OWNER+HR=SAMPLE RIDER+H=SAMPLE ELIMINATED+N=USA+t=EL+ti=9000001+f=99900+jt=+jti=9000009+jf=99900';

    private const HUNTER = '1|type=show+st=1+n1=Sample Fall Classic+s=10/1/2026+e=10/1/2026|type=class+st=1+hj=H+cn=265+d=SAMPLE ADULT HUNTER+nj=1+nr=1+cr=1'
        .'|type=h+B=7+st=2+HO=SAMPLE OWNER+HR=SAMPLE RIDER+H=SAMPLE HUNTER+N=+r1j1=8800+r1t=88.00+t=88.00+ti=8800+r=2'
        .'|type=h+B=8+st=3+HO=SAMPLE OWNER+HR=SAMPLE RIDER+H=SAMPLE UNPLACED+N=+t=+ti=0+r=0';

    public function test_jumper_placings_follow_the_sites_rules(): void
    {
        $r = EquestrianLiveHarvester::results(self::JUMPER);
        $this->assertSame(['501', '501 0.90m Jumper'], [$r['class_code'], $r['class_name']]);
        $this->assertSame([
            ['SAMPLE FAST CLEAR', '1', '0 faults, 79.00 · jump-off 0 faults, 33.00'],
            ['SAMPLE SLOW CLEAR', '2', '0 faults, 75.00 · jump-off 0 faults, 40.00'],
            ["SAMPLE'S RAIL", '3', '0 faults, 70.00 · jump-off 4 faults, 31.00'],
            ['SAMPLE TIME FAULT', '4', '0+1 faults, 80.50'],
        ], array_map(fn ($x) => [$x['horse'], $x['placing'], $x['score']], $r['rows']));
    }

    public function test_hunter_placings_come_from_the_feed(): void
    {
        $this->assertSame([['horse' => 'SAMPLE HUNTER', 'placing' => '2', 'score' => '88.00']], EquestrianLiveHarvester::results(self::HUNTER)['rows']);
    }

    public function test_harvest_reads_finished_shows_once_and_keeps_no_people(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 17));
        Http::fake([
            'equestrianlive.com/includes/aj_shows.php?show=1' => Http::response('1|type=now+t=1|type=show+id=900+n1=Sample Fall Classic+n2=+n3=+s=10/1/2026+e=10/1/2026|type=show+id=901+n1=Sample Live Today+s=10/17/2026+e=10/17/2026'),
            'equestrianlive.com/includes/aj_shows.php?show=900' => Http::response('1|type=show+id=900+n1=Sample Fall Classic|type=class+id=11+hj=J+n=501+d=501 0.90m Jumper|type=class+id=12+hj=H+n=265+d=SAMPLE ADULT HUNTER'),
            'equestrianlive.com/includes/aj_classjumper.php*' => Http::response(self::JUMPER),
            'equestrianlive.com/includes/aj_classhunter.php*' => Http::response(self::HUNTER),
        ]);

        $this->assertSame([1, 5], (new EquestrianLiveHarvester(0))->run());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'show=901'));
        $rec = ShowRecord::where('name_key', 'SAMPLESRAIL')->first();
        $this->assertSame(["Sample's Rail", null, 1], [$rec->display_name, $rec->registry_inferred, $rec->appearances]);
        $a = ShowAppearance::where('show_record_id', $rec->id)->first();
        $this->assertSame(['Sample Fall Classic', '10/1/2026', 2026, 'Jumper', '3'], [$a->event_name, $a->event_dates, $a->event_year, $a->organization, $a->placing]);
        $this->assertStringNotContainsString('RIDER', json_encode(ShowAppearance::all()));
        $this->assertSame(1, HarvestPage::where('kind', 'index')->where('status', 'parsed')->count());

        Http::fake(['*' => Http::response('1|type=show+id=900+n1=Sample Fall Classic+s=10/1/2026+e=10/1/2026')]);
        $this->assertSame([0, 0], (new EquestrianLiveHarvester(0))->run());
    }
}
