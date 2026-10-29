<?php

namespace Tests\Feature;

use App\Harvest\FarmSiteHarvester;
use App\Harvest\FtboaHarvester;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseMention;
use App\Models\PedigreeEntry;
use App\Models\StallionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** FTBOA registry and stallions, and Horse Hosting farm sites (fictional horses in the sites' markup). */
class FtboaTest extends TestCase
{
    use RefreshDatabase;

    private function ftboaStallion(): string
    {
        return '<html><body><div><strong>Stallion Profile</strong></div><div><h3 class="fusion-title-heading">Sample Charlie</h3></div>'
            .'<div class="fusion-text"><p>Sample Indian - </p></div><div class="fusion-text"><p>Sample Totem, </p></div><div class="fusion-text"><p> by Sample Afleet </p></div>'
            .'<div class="fusion-text"><p>Sample Stud</p><script>ad()</script></div><div class="fusion-text"><p>Florida Foal Crops: 2013 - 2027</p></div>'
            .'<a href="https://www.samplestud.example/" class="fusion-button"><img alt="Farm Website" /> Farm Website</a></body></html>';
    }

    private function cell(int $rowspan, string $name, string $country = 'USA', string $cy = ''): string
    {
        return '<td colspan="2"'.($rowspan > 1 ? ' rowspan="'.$rowspan.'"' : '').' class="m"><a href="#">'.$name.'</a> ('.$country.')'.($cy ? '<br> '.$cy : '').'</td>';
    }

    private function farmStallion(): string
    {
        // Two generations are enough to check the tree order.
        $ped = '<table class="pedigreetable table"><tbody><tr>'.$this->cell(2, 'SAMPLE INDIAN', 'USA', 'b. 1995').$this->cell(1, 'SAMPLE EXCESS', 'IRE', 'dkb/br. 1987').'</tr>'
            .'<tr>'.$this->cell(1, 'SAMPLE SOJOURN', 'USA', 'b. 1989').'</tr><tr>'.$this->cell(2, 'SAMPLE TOTEM', 'USA', 'dkb/br. 2000').$this->cell(1, 'SAMPLE AFLEET').'</tr>'
            .'<tr>'.$this->cell(1, 'SAMPLE NURYETTE').'</tr></tbody></table>';

        return '<html><body><div class="StallionNameBig"> Sample Charlie</div><div class="Breedline"><span class="lbSire">Sample Indian</span> - Sample Totem, by </span> <span class="lbBroodmareSire">Sample Afleet</span></div>'
            .'<div id="fee-button"><span class="sexDetails">Fee: $3,000&nbsp;LFSN</span></div>'.$ped
            .'<div id="stallionRaceRecord"><table class="horseTable"><tbody><tr><th>Age</th><th>Starts</th><th>1st</th><th>2nd</th><th>3rd</th><th>Earned</th></tr>'
            .'<tr> <td>2</td> <td>1</td> <td>0</td> <td>1</td> <td>0</td> <td>$ 10,200</td> </tr><tr> <td>Totals</td> <td>7</td> <td>3 (1)</td> <td>3 (2)</td> <td>0</td> <td>$251,090</td> </tr></tbody></table></div>'
            .'<div class="newsBlockHeader"> <a href="/articles/sample-win.html"> Sample colt wins stakes</a> </div><div class="newsInfo"><div class="catTitle"> <a href="#"> Sample Charlie</a> | 6/8/2025 </div></div>'
            .'<div class="footer">Powered by <a href="http://www.horsehosting.com">Horse Hosting</a></div></body></html>';
    }

    public function test_registry_stallions_and_farm_sites(): void
    {
        Storage::fake('local');
        $csv = "Sample Agasaya, , ,2015,Mare,N,N,N,N,N\r\nSample Lady Grace,Sample Chara,Sample Can Man,2018,Mare,Y,N,N,N,N\r\n,Sample Unnamed Dam,Sample Sire,2026,Filly,Y,Y,Y,Y,Y\r\nSample Young Colt\0,Sample Chara,Sample Can Man,2025,Colt,Y,Y,Y,Y,Y\r\n";
        Http::fake([
            'www.ftboa.com/wp-content/*' => Http::response($csv),
            'www.ftboa.com/wp-json/wp/v2/stallion*' => Http::response([['id' => 1, 'slug' => 'sample-charlie', 'link' => 'https://www.ftboa.com/stallion/sample-charlie']]),
            'www.ftboa.com/stallion/sample-charlie' => Http::response($this->ftboaStallion()),
            'www.samplestud.example/robots.txt' => Http::response("User-agent: *\nDisallow: /Admin\n"),
            'www.samplestud.example/' => Http::response('<a href="/horses/sample-charlie-3131.html">Sample Charlie</a> Powered by horsehosting.com'),
            'www.samplestud.example/horses/sample-charlie-3131.html' => Http::response($this->farmStallion()),
            'www.blockedstud.example/robots.txt' => Http::response("User-agent: *\nDisallow: /horses/\n"),
            'www.termsstud.example/robots.txt' => Http::response(''),
            'www.termsstud.example/' => Http::response('<a href="/terms-of-use.html">Terms</a> /Domains/x'),
        ]);

        $f = new FtboaHarvester(0.0);
        $this->assertSame(['registry' => true, 'stallions' => 1], $f->collect());
        $out = $f->load();
        $this->assertSame([4, 2, 1], [$out['rows'], $out['named'], $out['stallions']]);

        $grace = Horse::where('registered_name', 'Sample Lady Grace')->sole();
        $this->assertSame(['Thoroughbred', 'mare', 2018], [$grace->breed, $grace->sex, $grace->birth_year]);
        $this->assertSame(['Registered Florida-bred (FTBOA)'], HorseEvent::where('horse_id', $grace->id)->where('type', 'enrollment')->pluck('title')->all());
        $this->assertSame('colt', Horse::where('registered_name', 'Sample Young Colt')->value('sex'));
        $this->assertSame(0, Horse::where('registered_name', 'Sample Agasaya')->count());   // no parents given

        $charlie = Horse::where('registered_name', 'Sample Charlie')->sole();
        $s = StallionService::where('horse_id', $charlie->id)->sole();
        $this->assertSame(['Sample Stud', 'https://www.samplestud.example/', 'Florida foal crops 2013-2027'], [$s->standing_at, $s->website, $s->breeding]);

        // Farm sites: the allowed one is collected; robots and terms are respected.
        $farms = new FarmSiteHarvester(0.0);
        $c = $farms->collect(['https://www.samplestud.example/', 'https://www.blockedstud.example/', 'https://www.termsstud.example/']);
        $this->assertSame([1, 1, 1, 1], [$c['allowed'], $c['robots_blocked'], $c['review'], $c['pages']]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'blockedstud.example/horses'));
        $this->assertSame('review', HarvestPage::where('url', 'https://www.termsstud.example/')->value('status'));
        $farms->load();

        $this->assertSame([300000, 'Live foal, stands and nurses'], [StallionService::where('horse_id', $charlie->id)->where('fee_cents', '>', 0)->value('fee_cents'),
            StallionService::where('horse_id', $charlie->id)->where('fee_cents', '>', 0)->value('breeding')]);
        $ped = PedigreeEntry::where('horse_id', $charlie->id)->pluck('name', 'position');
        $this->assertSame(['Sample Excess (IRE)', 'Sample Sojourn', 'Sample Nuryette'], [$ped['ss'], $ped['sd'], $ped['dd']]);
        $this->assertSame([1987, 'Dark Bay/Brown'], array_values(PedigreeEntry::where('horse_id', $charlie->id)->where('position', 'ss')->first()->only(['foal_year', 'color'])));
        $this->assertSame('7 starts, 3 wins, 3 seconds, 0 thirds, $251,090', HorseEvent::where('horse_id', $charlie->id)->where('type', 'earnings')->value('result'));
        $m = HorseMention::where('horse_id', $charlie->id)->sole();
        $this->assertSame(['Sample colt wins stakes', 'https://www.samplestud.example/articles/sample-win.html', 'Sample Stud', '2025-06-08'],
            [$m->title, $m->url, $m->publisher, $m->published_on->toDateString()]);

        // Rerun: nothing new.
        $events = HorseEvent::count();
        $f->load();
        $farms->load();
        $this->assertSame($events, HorseEvent::count());
    }
}
