<?php

namespace Tests\Feature;

use App\Actions\Record\AddPublishedIdentity;
use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Harvest\RegistrationOnlyMatch;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\SaleLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Catalog lots join empty same-name records; catalog pedigrees fill missing positions (Oct 27 2026). Fictional horses. */
class EmptyRecordMatchTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $name, string $source): Horse
    {
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = BuildRecordsFromResults::system()->id;
        $h->forceFill(['source' => $source, 'listed' => true, 'identity_status' => 'unverified'])->save();

        return $h;
    }

    private function lot(string $name, array $extra = []): SaleLot
    {
        $ds = DataSource::firstOrCreate(['key' => 'billings'], ['name' => 'Billings Livestock', 'url' => 'https://www.billingslivestock.com']);

        return SaleLot::record($ds->id, uniqid(), $extra + ['sale_name' => 'Sample Sale', 'sale_date' => '2015-02-27', 'lot' => '7', 'horse_name' => $name,
            'status' => 'offered', 'source_url' => 'https://example.test/catalog.pdf']);
    }

    public function test_a_lot_joins_an_empty_same_name_record_but_not_one_that_disagrees(): void
    {
        $empty = $this->record('Sample Pepto Roan Bar', 'pedigree');
        $lot = $this->lot('Sample Pepto Roan Bar', ['registry' => 'AQHA', 'registration_number' => '4978795', 'sire_name' => 'Sample Pepto Power', 'dam_name' => 'Sample Badger Gal']);
        $this->assertSame('matched', RegistrationOnlyMatch::place($lot, 'Billings Livestock'));
        $this->assertSame([$empty->id, 'name_empty_record'], [$lot->fresh()->horse_id, $lot->fresh()->match_tier]);

        // A record that already has different parents is another horse: no attach.
        $other = $this->record('Sample Little Tassa', 'pedigree');
        PedigreeEntry::create(['horse_id' => $other->id, 'position' => 's', 'name' => 'Sample Someone Else', 'visibility' => 'public', 'added_by' => $other->created_by]);
        $lot2 = $this->lot('Sample Little Tassa', ['registry' => 'AQHA', 'registration_number' => '4543043', 'sire_name' => 'Sample Shorty Lena', 'dam_name' => 'Sample Tassa Mia']);
        RegistrationOnlyMatch::place($lot2, 'Billings Livestock');
        $this->assertNotSame($other->id, $lot2->fresh()->horse_id);

        // An owner's record is never filled this way.
        $owned = app(CreateHorse::class)(User::factory()->create(), ['registered_name' => 'Sample Owned Spades', 'birth_precision' => 'unknown']);
        $lot3 = $this->lot('Sample Owned Spades', ['registry' => 'AQHA', 'registration_number' => '5160478', 'sire_name' => 'Sample Playgun', 'dam_name' => 'Sample Amy']);
        RegistrationOnlyMatch::place($lot3, 'Billings Livestock');
        $this->assertNull($lot3->fresh()->horse_id);
        $this->assertFalse($owned->fresh()->pedigree()->exists());
    }

    public function test_a_catalog_pedigree_fills_missing_positions_but_not_under_a_disagreeing_line(): void
    {
        $h = $this->record('Sample Shining Smart', 'results');
        $by = $h->created_by;
        PedigreeEntry::create(['horse_id' => $h->id, 'position' => 's', 'name' => 'Sample Bay Shine', 'visibility' => 'public', 'added_by' => $by]);
        PedigreeEntry::create(['horse_id' => $h->id, 'position' => 'd', 'name' => 'Sample Other Dam', 'visibility' => 'public', 'added_by' => $by]);
        $filled = app(AddPublishedIdentity::class)($h, BuildRecordsFromResults::system(), ['pedigree' => [
            's' => 'Sample Bay Shine', 'ss' => 'Sample Shine Top', 'sd' => 'Sample Shine Dam',
            'd' => 'Sample City Girl', 'ds' => 'Sample City Sire', 'dd' => 'Sample City Dam',
        ]], 'test');
        $this->assertSame(['pedigree'], $filled);
        $lines = $h->pedigree()->pluck('name', 'position')->all();
        $this->assertSame('Sample Shine Top', $lines['ss']);        // filled under the agreeing sire
        $this->assertSame('Sample Other Dam', $lines['d']);         // never overwritten
        $this->assertArrayNotHasKey('ds', $lines);                   // not added under a dam that disagrees
        $this->assertNotNull(Horse::where('registered_name', 'Sample Shine Top')->first()); // ancestors get records
    }
}
