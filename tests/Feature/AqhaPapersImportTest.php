<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** hf:import-aqha-papers: identity, registration numbers and pedigrees read from AQHA papers (fictional horses). */
class AqhaPapersImportTest extends TestCase
{
    use RefreshDatabase;

    private function import(array $items): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aqha');
        file_put_contents($file, json_encode($items));
        $this->artisan('hf:import-aqha-papers', ['file' => $file])->assertSuccessful();
        unlink($file);
    }

    private function line(string $name, string $reg, int $year, ?string $color = null): array
    {
        return ['name' => $name, 'registration_number' => $reg, 'foal_year' => $year, 'color' => $color];
    }

    private function reg(Horse $h): ?string
    {
        return $h->identifiers()->where('kind', 'registration')->value('value');
    }

    public function test_papers_create_records_with_numbers_and_link_ancestors_by_number(): void
    {
        $this->import([
            ['name' => 'SAMPLE BETS', 'registration_number' => '6000001', 'foal_year' => 2021, 'birth_date' => '2021-03-11', 'sex' => 'gelding', 'color' => 'Sorrel',
                'pedigree' => ['s' => $this->line('SAMPLE GUN', '5000001', 2007), 'd' => $this->line('SAMPLE DREAMGIRL', '5000002', 2011),
                    'ss' => $this->line('SAMPLE SMOKING GUN', '4000001', 1993, 'Sorrel')]],
            // The sire's own row (older, so imported first): its record must be the one the chart links to.
            ['name' => 'SAMPLE GUN', 'registration_number' => '5000001', 'foal_year' => 2007, 'sex' => 'stallion', 'color' => 'Gray'],
        ]);

        $h = Horse::where('registered_name', 'Sample Bets')->sole();
        $this->assertSame(['day', '2021-03-11', 'Sorrel', 'gelding', 'Quarter Horse', 'published', 'unverified'],
            [$h->birth_precision, $h->birth_date?->toDateString() ?? $h->birth_date, $h->color, $h->sex, $h->breed, $h->source, $h->identity_status]);
        $this->assertSame('6000001', $this->reg($h));
        $this->assertSame('AQHA', $h->identifiers()->value('registry'));

        $sire = Horse::where('registered_name', 'Sample Gun')->sole(); // one record, not a second one from the chart
        $this->assertSame($sire->id, $h->pedigree()->where('position', 's')->value('linked_horse_id'));
        $this->assertSame(['Grey', '5000001'], [$sire->color, $this->reg($sire)]);
        $this->assertSame('4000001', $h->pedigree()->where('position', 'ss')->value('registration_number'));
        $this->assertSame('4000001', $this->reg(Horse::where('registered_name', 'Sample Smoking Gun')->sole()));
    }

    public function test_a_name_only_record_gets_its_number_and_full_foal_date(): void
    {
        $system = User::factory()->create();
        $h = app(CreateHorse::class)($system, ['registered_name' => 'Sample Chic Olena', 'birth_precision' => 'year', 'birth_year' => 1985]);
        $h->stewardships()->delete(); // an unowned record, as pedigree-made ancestors are

        $this->import([['name' => 'SAMPLE CHIC OLENA', 'registration_number' => '2000001', 'foal_year' => 1985, 'birth_date' => '1985-01-22', 'color' => 'Sorrel']]);

        $h->refresh();
        $this->assertSame(['day', '2000001'], [$h->birth_precision, $this->reg($h)]);
        $this->assertSame(1, Horse::where('registered_name', 'Sample Chic Olena')->count());
    }

    public function test_a_record_under_a_former_name_takes_the_official_name(): void
    {
        $system = User::factory()->create();
        $old = app(CreateHorse::class)($system, ['registered_name' => 'Sample Seizing The Day', 'birth_precision' => 'year', 'birth_year' => 2021]);
        $old->stewardships()->delete();

        $this->import([['name' => 'SAMPLE SPARK FOR GOLD', 'registration_number' => '6000002', 'foal_year' => 2021, 'birth_date' => '2021-01-08',
            'previous_names' => ['SAMPLE SEIZING THE DAY']]]);

        $old->refresh();
        $this->assertSame('Sample Spark For Gold', $old->registered_name);
        $this->assertSame(['Sample Seizing The Day'], $old->names()->where('kind', 'previous')->pluck('name')->all());
        $this->assertSame(1, Horse::count());
    }

    public function test_an_owned_record_is_never_changed(): void
    {
        $owner = User::factory()->create();
        $h = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned Horse', 'birth_precision' => 'unknown']);

        $this->import([['name' => 'SAMPLE OWNED HORSE', 'registration_number' => '6000003', 'foal_year' => 2019, 'color' => 'Bay']]);

        $this->assertNull($h->fresh()->color);
        $this->assertSame(0, HorseIdentifier::count());
    }
}
