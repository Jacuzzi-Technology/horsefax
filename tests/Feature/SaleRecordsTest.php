<?php

namespace Tests\Feature;

use App\Actions\Record\CreateRecordsFromSaleLots;
use App\Actions\Record\MatchSaleLots;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Records for a sale's identified horses (Billings, Oct 19 2026). All names fictional. */
class SaleRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function lot(string $name, array $extra = []): SaleLot
    {
        $ds = DataSource::firstOrCreate(['key' => 'billings'], ['name' => 'Billings Livestock', 'url' => 'https://www.billingslivestock.com']);

        return SaleLot::record($ds->id, uniqid(), $extra + [
            'sale_name' => 'Sample Fall Sale', 'sale_date' => '2025-09-25', 'lot' => '12', 'horse_name' => $name,
            'status' => 'offered', 'source_url' => 'https://www.billingslivestock.com/sample.pdf',
        ]);
    }

    public function test_identified_lots_become_records_and_repeat_lots_join_them(): void
    {
        $this->lot('Sample Smart Dun', ['registry' => 'AQHA', 'registration_number' => '6100001', 'foal_year' => 2021, 'sex' => 'Gelding', 'color' => 'Red Dun',
            'sire_name' => 'Sample Smart Sire', 'dam_name' => 'Sample Dun Dam', 'status' => 'sold', 'price_cents' => 1250000]);
        $this->lot('Sample Smart Dun', ['registry' => 'AQHA', 'registration_number' => '6100001', 'foal_year' => 2021, 'sale_date' => '2026-10-23', 'status' => 'catalog']);
        $this->lot('Sample Top Roan', ['registry' => 'AQHA', 'foal_year' => 2019, 'sex' => 'Mare', 'color' => 'Blue Roan', 'sire_name' => 'Sample Roan Sire', 'status' => 'sold', 'price_cents' => 900000]);
        $this->lot('Dusty', ['foal_year' => 2013, 'sex' => 'Gelding', 'color' => 'Red Roan']);  // barn name only
        $this->lot('Sample Mule', ['foal_year' => 2015, 'sex' => 'molly', 'sire_name' => 'Sample Jack']);
        $this->lot('Hip');

        $dry = app(CreateRecordsFromSaleLots::class)('billings', true);
        $this->assertSame(3, $dry['created']); // each lot rolled back alone, so the repeat lot counts too
        $this->assertSame(0, Horse::count());

        $out = app(CreateRecordsFromSaleLots::class)('billings');
        $this->assertSame(['created' => 2, 'attached' => 2, 'linked' => 1, 'queued' => 0, 'skipped_unidentified' => 1, 'skipped_not_horse' => 2], $out);

        $dun = Horse::where('registered_name', 'Sample Smart Dun')->sole();
        $this->assertSame(['gelding', 'Red Dun', 'Quarter Horse', 2021, 'published'], [$dun->sex, $dun->color, $dun->breed, (int) $dun->birth_year, $dun->source]);
        $this->assertSame('6100001', $dun->identifiers()->where('kind', 'registration')->value('value'));
        $this->assertSame(2, SaleLot::where('horse_id', $dun->id)->count()); // the catalog lot joined the same record
        $this->assertSame('reported_sale', HorseEvent::where('horse_id', $dun->id)->value('result'));
        $this->assertSame(1, HorseEvent::where('horse_id', $dun->id)->count()); // nothing for the catalog lot yet
        $this->assertSame(0, Horse::whereIn('registered_name', ['Dusty', 'Sample Mule', 'Hip'])->where('source', 'published')->count());

        // The catalog lot's result arrives: the next matching run puts it on the record.
        SaleLot::where('status', 'catalog')->sole()->forceFill(['status' => 'sold', 'price_cents' => 1500000])->save();
        app(MatchSaleLots::class)();
        $this->assertSame(2, HorseEvent::where('horse_id', $dun->id)->where('result', 'reported_sale')->count());

        // Run again: nothing new.
        $again = app(CreateRecordsFromSaleLots::class)('billings');
        $this->assertSame(0, $again['created']);
    }

    public function test_a_name_already_on_a_record_goes_to_staff(): void
    {
        $h = new Horse(['registered_name' => 'Sample Known Horse', 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = \App\Actions\Record\BuildRecordsFromResults::system()->id;
        $h->save();
        $this->lot('Sample Known Horse', ['sire_name' => 'Sample Sire', 'foal_year' => 2018]);

        $out = app(CreateRecordsFromSaleLots::class)('billings');
        $this->assertSame([0, 1], [$out['created'], $out['queued']]);
        $this->assertSame(1, Horse::count());
    }

    public function test_a_conflicting_lot_that_identifies_its_horse_gets_its_own_record(): void
    {
        $system = \App\Actions\Record\BuildRecordsFromResults::system();
        $old = new Horse(['registered_name' => 'Sample Twin Name', 'birth_precision' => 'year', 'birth_year' => 2008]);
        $old->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $old->created_by = $system->id;
        $old->save();
        \App\Models\PedigreeEntry::create(['horse_id' => $old->id, 'position' => 's', 'name' => 'Sample Old Sire', 'visibility' => 'public', 'added_by' => $system->id]);
        $withSire = $this->lot('Sample Twin Name', ['sire_name' => 'Sample New Sire', 'foal_year' => 2020, 'status' => 'sold', 'price_cents' => 800000]);
        $bare = $this->lot('Sample Twin Name', ['foal_year' => 2021, 'sex' => 'Gelding']);          // contradicts, but identifies nothing
        $pending = $this->lot('Sample Twin Name', ['registration_number' => 'Pending', 'foal_year' => 2022]); // "Pending" isn't a number
        $unnamed = $this->lot('AQHA Pending', ['sire_name' => 'Sample New Sire', 'foal_year' => 2026, 'status' => 'catalog']); // no name yet
        $unnamed2 = $this->lot('Pending', ['sire_name' => 'Sample New Sire', 'foal_year' => 2026, 'status' => 'sold', 'price_cents' => 300000]);

        app(MatchSaleLots::class)();
        $this->assertSame(['conflict', 'conflict', 'conflict'], [$withSire->fresh()->review_status, $bare->fresh()->review_status, $pending->fresh()->review_status]);
        $this->assertStringStartsWith(MatchSaleLots::DIFFERENT, $withSire->fresh()->review_note);

        $dry = app(CreateRecordsFromSaleLots::class)->splitConflicts(true);
        $this->assertSame(1, $dry['created']);
        $this->assertSame(1, Horse::count());

        $out = app(CreateRecordsFromSaleLots::class)->splitConflicts();
        $this->assertSame([1, 1, 2], [$out['created'], $out['attached'], $out['skipped_unidentified']]);
        $this->assertSame(0, app(CreateRecordsFromSaleLots::class)('billings')['created']); // the unnamed foals stay in the index
        $this->assertSame(0, Horse::whereIn('registered_name', ['AQHA Pending', 'Pending'])->count());
        $this->assertNull($unnamed2->fresh()->horse_id);
        $new = Horse::where('registered_name', 'Sample Twin Name')->where('id', '!=', $old->id)->sole();
        $this->assertSame([$new->id, null], [$withSire->fresh()->horse_id, $withSire->fresh()->review_status]);
        $this->assertSame(2020, (int) $new->birth_year);
        $this->assertSame('Sample New Sire', $new->pedigree()->where('position', 's')->value('name'));
        $this->assertSame('conflict', $bare->fresh()->review_status);
        $this->assertSame(0, $new->identifiers()->count()); // no "Pending" identifier
        // The two records are already known to be different horses: the pair doesn't go on the staff list.
        $this->assertSame('different_horses', \App\Models\NameReview::sole()->status);
        app(\App\Actions\Record\FindNameVariants::class)();
        $this->assertSame(0, \App\Models\NameReview::where('status', 'open')->count());
    }
}
