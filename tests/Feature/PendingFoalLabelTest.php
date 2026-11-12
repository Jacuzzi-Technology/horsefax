<?php

namespace Tests\Feature;

use App\Actions\Record\CleanPendingFoalRecords;
use App\Actions\Record\CreateRecordsFromSaleLots;
use App\Harvest\RegistrationOnlyMatch;
use App\Harvest\RobinGlennHarvester;
use App\Models\Horse;
use App\Models\SaleLot;
use App\Support\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Unnamed-foal labels in sale catalogs ("Pending/<dam>") are not registered names (all names fictional). */
class PendingFoalLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_unnamed_foal_labels_are_placeholders_but_names_are_not(): void
    {
        foreach (['Pending/Sample Mare', 'pending/sample mare', 'Pending A/Sample Mare', 'Pending Ab/Sample Mare', 'PendingB/Sample Mare',
            'PENDING22/Sample Mare', 'Pending (P)/Sample Mare', 'Pending (AP)/Sample Mare (Ap)', 'Pending - Sample Mare', 'Pending-Sample Mare',
            'Pending – Sample Mare', 'Pending: Sample Mare', 'Pending 3', 'Pending B', 'AQHA Name Pending/Sample Mare', 'Registered Name Pending',
            'Pending', 'Name Pending', 'Unnamed Filly'] as $label) {
            $this->assertTrue(Record::placeholderName($label), $label);
        }
        foreach (['Sample Spending Spree', 'Spending Spree', 'Pending Sample Win', 'Pending FHH', 'Sample Mare (pending)', 'Sample Mare/Pending', 'Sample Pending Star'] as $name) {
            $this->assertFalse(Record::placeholderName($name), $name);
        }
    }

    public function test_catalog_loader_makes_no_record_from_a_label(): void
    {
        $source = (new RobinGlennHarvester(0, null, sys_get_temp_dir().'/rg-pending-'.uniqid()))->source();
        $lot = SaleLot::record($source->id, '9:1', ['sale_name' => 'Sample Fall Sale', 'sale_date' => '2024-10-05', 'lot' => '1', 'horse_name' => 'Pending/Sample Mare',
            'foal_year' => 2024, 'sire_name' => 'Sample Sire', 'dam_name' => 'Sample Mare', 'dam_sire_name' => 'Sample Damsire', 'status' => 'offered', 'source_url' => 'https://example.com/9']);

        $this->assertSame('unmatched', RegistrationOnlyMatch::place($lot, 'Robin Glenn QData'));
        $this->assertSame(0, Horse::where('source', 'published')->count());
        $lot->refresh();
        $this->assertNull($lot->horse_id);
        $this->assertSame([RegistrationOnlyMatch::UNMATCHED, 'Sample Mare'], [$lot->review_status, $lot->dam_name]); // the dam stays where it already was
    }

    public function test_cleanup_removes_records_made_from_labels_and_keeps_anything_else(): void
    {
        $source = (new RobinGlennHarvester(0, null, sys_get_temp_dir().'/rg-pending-'.uniqid()))->source();
        $make = function (string $id, string $name, string $dam) use ($source) {
            $lot = SaleLot::record($source->id, $id, ['sale_name' => 'Sample Fall Sale', 'sale_date' => '2024-10-05', 'horse_name' => $name,
                'sire_name' => 'Sample Sire', 'dam_name' => $dam, 'status' => 'offered', 'source_url' => 'https://example.com/9']);

            return [app(CreateRecordsFromSaleLots::class)->createForAdvertised($lot), $lot]; // what the load did before the label was known
        };
        [$bogus, $bogusLot] = $make('9:1', 'Pending/Sample Mare', 'Sample Mare');
        [$claimed] = $make('9:2', 'Pending A/Sample Other Mare', 'Sample Other Mare');
        [$real, $realLot] = $make('9:3', 'Sample Real Name', 'Sample Third Mare');
        \App\Models\Stewardship::forceCreate(['horse_id' => $claimed->id, 'user_id' => \App\Models\User::factory()->create()->id, 'role' => 'owner', 'status' => 'active']);
        $dam = Horse::where('source', 'pedigree')->where('registered_name', 'Sample Mare')->first();
        $sire = Horse::where('source', 'pedigree')->where('registered_name', 'Sample Sire')->first();
        $this->assertNotNull($dam);
        $before = Horse::count();

        $clean = app(CleanPendingFoalRecords::class);
        [$dry, $list] = $clean(true);
        $this->assertSame([2, 1, 1, ['has_stewardships' => 1]], [$dry['label_records'], $dry['removed'], $dry['lots_detached'], $dry['kept']]);
        $this->assertSame([['horsefax_id' => $bogus->horsefax_id, 'name' => 'Pending/Sample Mare', 'sale' => 'Sample Fall Sale']], $list);
        $this->assertSame($before, Horse::count()); // rolled back

        [$out] = $clean();
        $this->assertSame($dry, $out); // the dry run's counts were real
        $this->assertNull(Horse::find($bogus->id));
        $this->assertNotNull(Horse::find($claimed->id)); // a person looks after it: kept
        $this->assertNotNull(Horse::find($real->id));
        $this->assertSame($real->id, $realLot->fresh()->horse_id);
        $bogusLot->refresh();
        $this->assertSame([null, null, 'unmatched', 'Sample Mare'], [$bogusLot->horse_id, $bogusLot->match_tier, $bogusLot->review_status, $bogusLot->dam_name]);
        $this->assertNull(Horse::find($dam->id)); // the dam ancestor only that chart needed is pruned
        $this->assertNotNull(Horse::find($sire->id)); // the sire is still in the other charts
        $this->assertSame(0, $clean()[0]['removed']); // a second run finds nothing to do
    }
}
