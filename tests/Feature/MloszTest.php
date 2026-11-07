<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Console\Commands\BackfillBreeders;
use App\Harvest\MloszHarvester;
use App\Models\Horse;
use App\Models\HorseFact;
use App\Models\HorseIdentifier;
use App\Models\PedigreeEntry;
use App\Models\RegistryRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** MLOSZ (Hungary) stud-book search and the Breeder field (fictional horses in the API's shape). */
class MloszTest extends TestCase
{
    use RefreshDatabase;

    private function row(int $id, string $name, string $sire, string $dam, array $extra = []): array
    {
        return $extra + ['loId' => $id, 'nev' => $name, 'loAzon' => 'HUN K IM9000'.$id, 'anyaNev' => $dam, 'apaNev' => $sire, 'anyApNev' => 'Sample Damsire',
            'fajtaKod' => 'quart', 'szuletesiEv' => 2015, 'szuletesiHo' => 4, 'szuletesiNap' => 9, 'nemKod' => 'K', 'ivartalanitva' => false,
            'szinEn' => 'bay', 'laraSzincsoportEn' => 'bay', 'tenyesztoNev' => null];
    }

    private function assets(): array
    {
        return ['mlosz.hu/api/assets' => Http::response(['fajtak' => [
            ['fajtaKod' => 'quart', 'fajtaMegnevezesHu' => 'Quarter horse', 'fajtaMegnevezesEn' => 'American Quarter Horse'],
            ['fajtaKod' => 'msp', 'fajtaMegnevezesHu' => 'magyar sportló', 'fajtaMegnevezesEn' => 'Hungarian Sporthorse'],
            ['fajtaKod' => 'tgc', 'fajtaMegnevezesHu' => 'tarpán', 'fajtaMegnevezesEn' => null],
            ['fajtaKod' => 'szam', 'fajtaMegnevezesHu' => 'szamár', 'fajtaMegnevezesEn' => 'Donkey'],
        ]])];
    }

    private function unowned(string $name, string $sire): Horse
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'published', 'identity_status' => 'unverified'])->save();
        PedigreeEntry::create(['horse_id' => $h->id, 'position' => 's', 'name' => $sire, 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'unverified']);

        return $h;
    }

    public function test_stud_book_rows_become_records_with_breeder_and_no_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned Lena', 'birth_precision' => 'unknown']);
        $sameSire = $this->unowned('Sample Same Doc', 'Sample Sire');
        $otherSire = $this->unowned('Sample Two Docs', 'Sample Texas Sire');

        $rows = [
            $this->row(1, 'Sample Remedy Freckles', 'Sample Sire', 'Sample Dam', ['tenyesztoNev' => 'Sample, John W. és Katsy']),
            $this->row(2, 'Sample Same Doc', 'Sample Sire', 'Sample Dam 2', ['tenyesztoNev' => 'Sample Quarter Horses Inc']),
            $this->row(3, 'Sample Two Docs', 'Sample Hungarian Sire', 'Sample Dam 3', ['nemKod' => 'M', 'ivartalanitva' => true]),
            $this->row(4, 'Sample Owned Lena', 'Sample Sire', 'Sample Dam 4', ['tenyesztoNev' => 'Sample Breeder']),
            $this->row(5, 'Sample Gone Mare', 'Sample Sire', 'Sample Dam 5', ['ueln' => '348002000012345', 'fogyatekVegleges' => true]),
        ];
        Http::fake($this->assets() + ['mlosz.hu/api/search/detailed-search*' => Http::response(['total' => '5', 'horses' => $rows])]);

        $h = new MloszHarvester(0.0, null, ['quart']);
        $this->assertSame(['requests' => 1, 'kept' => 0, 'horses' => 5, 'changed' => 1], $h->collect());
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['fajtaKod'] === 'quart' && str_starts_with($r->header('User-Agent')[0], 'HorsefaxBot'));
        $out = $h->load();
        $this->assertSame([3, 1, 1, 1, 1], [$out['created'], $out['updated'], $out['skipped_owned'], $out['ueln_added'], $out['deaths_added']]); // rows 1, 3, 5 created
        $gone = Horse::where('registered_name', 'Sample Gone Mare')->sole();
        $this->assertSame('348002000012345', HorseIdentifier::where('horse_id', $gone->id)->where('kind', 'ueln')->value('value'));
        $this->assertSame('unknown', \App\Models\HorseEvent::where('horse_id', $gone->id)->where('type', 'death')->sole()->date_precision);

        $new = Horse::where('registered_name', 'Sample Remedy Freckles')->sole();
        $this->assertSame(['Sample, John W. & Katsy', 'Quarter Horse', 'mare', 'day', 'Bay'], [$new->breeder, $new->breed, $new->sex, $new->birth_precision, $new->color]);
        $this->assertSame('Sample, John W. & Katsy', HorseFact::where('horse_id', $new->id)->where('field', 'breeder')->value('value'));
        $this->assertSame(['d' => 'Sample Dam', 'ds' => 'Sample Damsire', 's' => 'Sample Sire'], $new->pedigree()->orderBy('position')->pluck('name', 'position')->all());
        $this->assertSame('MLOSZ', HorseIdentifier::where('horse_id', $new->id)->value('registry'));

        // Same name, same sire: the existing record is filled, not duplicated.
        $this->assertSame('Sample Quarter Horses Inc', $sameSire->fresh()->breeder);
        $this->assertSame(1, Horse::where('registered_name', 'Sample Same Doc')->count());
        // Same name, a different sire: a different horse, so a new record beside it.
        $this->assertSame(2, Horse::where('registered_name', 'Sample Two Docs')->count());
        $this->assertNull($otherSire->fresh()->sex);
        $this->assertSame('gelding', Horse::where('registered_name', 'Sample Two Docs')->where('id', '!=', $otherSire->id)->value('sex'));
        // The owner's record is left alone.
        $this->assertNull(Horse::where('registered_name', 'Sample Owned Lena')->value('breeder'));

        // Rerun from the kept copies: no requests, nothing new.
        Http::fake(fn () => throw new \RuntimeException('no requests expected'));
        $this->assertSame(1, $h->collect()['kept']);
        $count = Horse::count();
        $again = $h->load();
        $this->assertSame([$count, 0, 0], [Horse::count(), $again['ueln_added'], $again['deaths_added']]);
        $this->assertSame(0, $again['pages']);   // nothing new to load

        // Monthly refresh: the page is fetched again but unchanged, so it isn't reloaded.
        Http::fake($this->assets() + ['mlosz.hu/api/search/detailed-search*' => Http::response(['total' => '5', 'horses' => $rows])]);
        $this->assertSame(0, $h->collect(true)['changed']);
        $this->assertSame(0, $h->load()['pages']);
        $this->assertSame(1, $h->load(true)['pages']);

        // Shown on the record and in its public report.
        $this->actingAs(User::factory()->create())->get(route('horse.public', $new))->assertOk()->assertSee('Breeder')->assertSee('Sample, John W. &amp; Katsy', false);
    }

    public function test_offset_is_a_page_number(): void
    {
        Storage::fake('local');
        Http::fake(function ($r) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
            $rows = (int) $q['offset'] === 0 ? array_map(fn ($i) => $this->row($i, 'Sample Horse '.$i, 'Sample Sire', 'Sample Dam'), range(1, 100))
                : [$this->row(101, 'Sample Horse 101', 'Sample Sire', 'Sample Dam')];

            return Http::response(['total' => '101', 'horses' => $rows]);
        });
        Storage::put('harvest/mlosz/assets.json.gz', gzencode('{"fajtak":[]}'));
        $this->assertSame(['requests' => 2, 'kept' => 0, 'horses' => 101, 'changed' => 2], (new MloszHarvester(0.0, null, ['quart']))->collect());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'offset=1&limit=100'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'offset=100'));
    }

    public function test_every_horse_breed_is_collected_by_default(): void
    {
        Storage::fake('local');
        Http::fake($this->assets() + ['mlosz.hu/api/search/detailed-search*' => function ($r) {
            $code = $r->data()['fajtaKod'];

            return Http::response(['total' => '1', 'horses' => [$this->row(crc32($code) % 100000, 'Sample '.$code.' Horse', 'Sample Sire', 'Sample Dam', ['fajtaKod' => $code])]]);
        }]);
        $h = new MloszHarvester(0.0, null);
        $this->assertSame(3, $h->collect()['requests']);   // quart, msp, tgc; not the donkey
        Http::assertNotSent(fn ($r) => ($r->data()['fajtaKod'] ?? null) === 'szam');
        $h->load();
        $this->assertSame(['Hungarian Sporthorse', 'Quarter Horse', 'Tarpán'], Horse::whereNotNull('breed')->orderBy('breed')->pluck('breed')->all());
    }

    public function test_a_403_stops_the_run(): void
    {
        Storage::fake('local');
        Http::fake(['mlosz.hu/*' => Http::response('', 403)]);
        $this->expectException(\App\Harvest\HarvestBlocked::class);
        (new MloszHarvester(0.0, null, ['quart']))->collect();
    }

    public function test_owner_can_set_breeder_with_history(): void
    {
        $owner = User::factory()->create();
        $horse = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Bred Horse', 'birth_precision' => 'unknown']);
        $this->actingAs($owner)->put(route('horses.update', $horse), ['registered_name' => 'Sample Bred Horse', 'birth_precision' => 'unknown', 'breeder' => 'Sample Ranch'])->assertRedirect();
        $this->assertSame('Sample Ranch', $horse->fresh()->breeder);
        $this->assertSame('owner_reported', HorseFact::where('horse_id', $horse->id)->where('field', 'breeder')->where('is_current', true)->value('source_type'));
        $this->actingAs($owner)->get(route('horses.edit', $horse))->assertSee('name="breeder"', false);
    }

    public function test_breeder_backfill_from_aqha_papers(): void
    {
        $this->assertSame('Robert R &/or Wendy Dexter', BackfillBreeders::breeder("Genetic Typed\n\nBreeder:   ROBERT R &/OR WENDY DEXTER                    Owner:   SAMPLE OWNER\n"));
        $this->assertSame('B & P Partnership', BackfillBreeders::breeder('Breeder:   B & P PARTNERSHIP          Owner: X'));
        $this->assertNull(BackfillBreeders::breeder('Owner:   SAMPLE OWNER'));

        $unowned = $this->unowned('Sample Papers Horse', 'Sample Sire');
        $owner = User::factory()->create();
        $owned = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned Papers', 'birth_precision' => 'unknown']);
        foreach ([[$unowned, '1'], [$owned, '2']] as [$h, $n]) {
            RegistryRecord::create(['horse_id' => $h->id, 'registry' => 'AQHA', 'registration_number' => '600000'.$n, 'document_kind' => 'AQHA Records (XML)',
                'document_ref' => 'order:'.$n, 'raw_text' => "Breeder:   SAMPLE BREEDER LLC          Owner:   SAMPLE OWNER\n"]);
        }
        $this->artisan('hf:backfill-breeders', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($unowned->fresh()->breeder);
        $this->artisan('hf:backfill-breeders')->expectsOutputToContain('"filled":1,"already_set":0,"skipped_owned":1')->assertSuccessful();
        $this->assertSame('Sample Breeder LLC', $unowned->fresh()->breeder);
        $this->assertNull($owned->fresh()->breeder);
        $this->artisan('hf:backfill-breeders')->expectsOutputToContain('"filled":0,"already_set":1')->assertSuccessful();
    }
}
