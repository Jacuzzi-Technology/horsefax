<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Harvest\StallionCompareHarvester;
use App\Harvest\StallionCompareParser;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseMention;
use App\Models\StallionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** StallionCompare stallion and horse pages (fictional horses in the site's markup). */
class StallionCompareTest extends TestCase
{
    use RefreshDatabase;

    private function cell(int $gen, string $name, string $extra = ''): string
    {
        return '<td generation="'.$gen.'" name="'.$name.'"><div class="fw-medium mb-1"><a class="pedigree-link" href="/horse/'.strtolower(str_replace(' ', '-', $name)).'">'.$name.'</a></div>'.$extra.'</td>';
    }

    private function stallion(): string
    {
        $ped = '<div id="pedigree"><table><tr>'.$this->cell(1, 'Sample Hot Sire', '<p class="xsmall">1992 Bay Quarter Horse</p><p class="xsmall">$37,755</p>')
            .$this->cell(2, 'Sample Grandsire').'</tr><tr>'.$this->cell(2, 'Sample Granddam').'</tr><tr>'
            .$this->cell(1, 'Sample Baby Jane', '<p class="xsmall">1991 Brown Quarter Horse</p>').$this->cell(2, 'Sample Pine Three').'</tr><tr>'.$this->cell(2, 'Sample Annie').'</tr></table></div>';

        return '<html><body><header><h1 class="text-navy">Sample Blazer</h1><p class="fw-bold mb-0">1997 Bay Quarter Horse (AQHA ) Stallion, 16.3h</p>'
            .'<p>Sample Hot Sire x Sample Baby Jane (Sample Pine Three)</p><p class="xsmall"> In loving memory of Sample Blazer, 1997 – 2019.</p>'
            .'<p class="xsmall">Owned by Sample Owner LLC</p></header>'
            .'<main id="overview"><div class="row"><div class="col-lg-4"><p class="xsmall text-uppercase text-spaced mb-2">Genetics</p><p class="fw-semibold text-navy mb-1 fs-5">5 Panel Negative</p>'
            .'<div class="column-genetics"><div class="row xsmall"><div class="col">HYPP&nbsp;(N/N)<br>HERDA&nbsp;(N/N)</div></div></div></div>'
            .'<div class="col-lg-4"><p class="xsmall text-uppercase text-spaced mb-2">Fees</p><p class="fw-semibold text-navy mb-1 fs-5">$2,000 <span>USD</span></p><p class="xsmall"><strong>Chute Fee:</strong> $500</p></div>'
            .'<div class="col-lg-4"><p class="xsmall text-uppercase text-spaced mb-2">Breeding</p><ul class="list-inline"></ul><p class="xsmall">Frozen transported semen available</p></div></div></main>'
            .$ped
            .'<div class="py-5" id="contacts"><div class="row"><div class="col-lg-4"><p class="xsmall text-uppercase text-spaced mb-2">Standing At</p><p class="fw-semibold text-navy mb-1 fs-5">Sample Farm LLC</p>'
            .'<p class="text-primary">123 Sample Road<br>Purcell, OK 73093<br>United States<br><a href="https://sample-farm.example">Website</a><a href="tel:555-0100">Phone</a><a href="mailto:Sample@farm.example">Email</a></p></div>'
            .'<div class="col-lg-4"><p class="xsmall text-uppercase text-spaced mb-2">Breeding Manager</p><p class="fw-semibold text-navy mb-1 fs-5">Sample Manager</p><p><a href="tel:555-0101">Phone</a><a href="mailto:manager@farm.example">Email</a></p></div>'
            .'<div class="col-lg-4"><p class="xsmall text-uppercase text-spaced mb-2">Owner(s)</p><p class="fw-semibold fs-5">Sample Owner LLC</p><p><a href="tel:555-9999">Phone</a><a href="mailto:owner@private.example">Email</a></p></div></div></div>'
            .'<div id="nominations"><a href="/program/super-sires"><img data-bs-title="Sample Super Sires" /></a></div>'
            .'<div class="mt-5" id="highlights"><div><p class="xsmall text-uppercase text-spaced mb-2">Highlights</p><p class="fw-semibold text-navy mb-1 fs-5">$40,110 in LTE</p>'
            .'<ul><li>Sample Hall of Fame Stallion (2021)</li><li> 2002 Sample World Champion Jr HUS</li><li></li></ul></div>'
            .'<div><div class="pt-5" id="progeny"><table><thead><tr><td colspan="2">Horse</td><td>Info</td><td>Sire</td><td>Dam</td></tr></thead>'
            .'<tr><td></td><td><a href="/horse/sample-hidden-agenda"><p>Sample Hidden Agenda</p></a></td><td>2014 Sorrel Mare </td><td>Sample Blazer</td><td>Sample Agenda Dam</td></tr>'
            .'<tr><td></td><td><a href="/horse/sampleone"><p>Sampleone</p></a></td><td>2010 Mare</td><td>Sample Blazer</td><td>Sample Dam Two</td></tr>'
            .'<tr><td></td><td><a href="/horse/sample-no-dam"><p>Sample No Dam</p></a></td><td>2011 Mare</td><td>Sample Blazer</td><td></td></tr></table></div>'
            .'<div id="articles"><ul><li><a href="/article/sample-market-story"><div><p class="fw-semibold mb-0">Sample Market Story</p><p class="xsmall">Their own summary text.</p></div></a></li></ul></div></div></div>'
            .'</body></html>';
    }

    private function horsePage(): string
    {
        return '<html><body><h1 class="text-navy">Sample Hot Sire</h1><p class="fw-bold mb-0">1992 Bay Quarter Horse Stallion</p><p>Sample Grandsire x Sample Granddam</p>'
            .'<div id="highlights"><p class="xsmall text-spaced">Overview</p><ul><li>Sample Performance ROM</li></ul></div></body></html>';
    }

    public function test_parser_reads_every_section_and_no_contacts(): void
    {
        $p = StallionCompareParser::page($this->stallion());
        $this->assertSame([1997, 'Bay', 'Quarter Horse', 'AQHA', 'stallion', 16.3, 2019], [$p['foal_year'], $p['color'], $p['breed'], $p['registry'], $p['sex'], $p['height'], $p['died_year']]);
        $this->assertSame(['s', 'd', 'ds', 'ss', 'sd', 'dd'], array_keys($p['pedigree']));
        $this->assertSame(['Sample Grandsire', 'Sample Pine Three', 'Sample Annie'], [$p['pedigree']['ss']['name'], $p['pedigree']['ds']['name'], $p['pedigree']['dd']['name']]);
        $this->assertSame(3775500, $p['pedigree']['s']['earnings_cents']);
        $this->assertSame(['summary' => '5 Panel Negative', 'tests' => ['HYPP (N/N)', 'HERDA (N/N)']], $p['genetics']);
        $this->assertSame(['fee_cents' => 200000, 'currency' => 'USD', 'chute_fee_cents' => 50000, 'breeding' => 'Frozen transported semen available'], $p['fees']);
        $this->assertSame(['name' => 'Sample Farm LLC', 'city' => 'Purcell', 'region' => 'OK', 'country' => 'United States', 'website' => 'https://sample-farm.example', 'phone' => '555-0100', 'email' => 'sample@farm.example'], $p['standing']);
        $this->assertSame(['name' => 'Sample Manager', 'phone' => '555-0101', 'email' => 'manager@farm.example'], $p['manager']);
        $this->assertSame([4011000, ['Sample Hall of Fame Stallion (2021)', '2002 Sample World Champion Jr HUS']], [$p['earnings_cents'], $p['highlights']]);
        $this->assertSame(['Sample Super Sires'], $p['nominations']);
        $this->assertSame([['title' => 'Sample Market Story', 'url' => 'https://stallioncompare.com/article/sample-market-story']], $p['articles']);
        $this->assertStringNotContainsString('Sample Owner', json_encode($p));
        $this->assertStringNotContainsString('555-9999', json_encode($p));        // the owner card's contacts
        $this->assertStringNotContainsString('owner@private', json_encode($p));
        $this->assertStringNotContainsString('Sample Road', json_encode($p));     // no street address
    }

    public function test_harvest_loads_records_extras_and_progeny(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        app(CreateHorse::class)($owner, ['registered_name' => 'Sample No Dam', 'birth_precision' => 'unknown']);
        Http::fake([
            'stallioncompare.com/listing/1/*' => Http::response('<a href="/stallion/sample-featured">x</a> Refine Search <a href="/stallion/sample-blazer">x</a>'),
            'stallioncompare.com/listing/*' => Http::response('<a href="/stallion/sample-featured-2">x</a> Refine Search'),
            'stallioncompare.com/stallion/sample-blazer' => Http::response($this->stallion()),
            'stallioncompare.com/horse/sample-hot-sire' => Http::response($this->horsePage()),
            'stallioncompare.com/horse/*' => Http::response('', 404),
        ]);
        $h = new StallionCompareHarvester(0.0);
        $c = $h->collect();
        $this->assertSame(1, $c['stallions']);
        Http::assertSent(fn ($r) => str_starts_with($r->header('User-Agent')[0], 'HorsefaxBot'));
        $out = $h->load();

        $blazer = Horse::where('registered_name', 'Sample Blazer')->sole();
        $this->assertSame(['stallion', '16.3', 1997], [$blazer->sex, rtrim(rtrim((string) $blazer->height_hands, '0'), '.'), $blazer->birth_year]);
        $s = StallionService::where('horse_id', $blazer->id)->sole();
        $this->assertSame(['Sample Farm LLC', 'Purcell', 200000, 50000, '$2,000'], [$s->standing_at, $s->city, $s->fee_cents, $s->chute_fee_cents, $s->feeLabel()]);
        $types = HorseEvent::where('horse_id', $blazer->id)->pluck('type')->sort()->values()->all();
        $this->assertSame(['death', 'earnings', 'enrollment', 'genetic_test', 'title', 'title'], $types);
        $this->assertSame('2021', HorseEvent::where('horse_id', $blazer->id)->where('title', 'Sample Hall of Fame Stallion (2021)')->sole()->occurred_on->format('Y'));
        $this->assertSame('HYPP (N/N), HERDA (N/N)', HorseEvent::where('horse_id', $blazer->id)->where('type', 'genetic_test')->value('result'));
        $this->assertSame('Sample Market Story', HorseMention::where('horse_id', $blazer->id)->value('title'));
        // The sire's earnings from the chart land on the sire's record; his own page adds his title.
        $sire = Horse::where('registered_name', 'Sample Hot Sire')->sole();
        $this->assertSame(3775500, HorseEvent::where('horse_id', $sire->id)->where('type', 'earnings')->value('earnings_cents'));
        $this->assertSame(1, HorseEvent::where('horse_id', $sire->id)->where('title', 'Sample Performance ROM')->count());
        // Progeny: registered names with both parents only; the owner's record untouched.
        $this->assertSame(1, Horse::where('registered_name', 'Sample Hidden Agenda')->count());
        $this->assertSame(0, Horse::where('registered_name', 'Sampleone')->count());
        $this->assertSame(1, Horse::where('registered_name', 'Sample No Dam')->count());
        // Nothing about people beyond the farm.
        $this->assertSame(0, HorseEvent::where('details', 'like', '%Owner%')->count());

        // Rerun: nothing new, nothing duplicated.
        $events = HorseEvent::count();
        $h->collect();
        $this->assertSame(0, $h->load()['pages']);
        $h->load(true);
        $this->assertSame($events, HorseEvent::count());

        // Shown on the record.
        $this->actingAs(User::factory()->create())->get(route('horse.public', $blazer))->assertOk()
            ->assertSee('Published profile')->assertSee('Sample Farm LLC')->assertSee('sample@farm.example')->assertSee('Sample Manager')->assertDontSee('owner@private.example')->assertSee('$40,110')->assertSee('Sample Market Story')->assertSee('Sample World Champion Jr HUS')
            ->assertDontSee('Sample Owner LLC')->assertDontSee('Their own summary text.');
    }
}
