<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Actions\Record\OffspringEarnings;
use App\Models\Horse;
use App\Models\OffspringEarning;
use App\Models\PedigreeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The sire and dam earnings rebuild runs end to end (Nov 1 2026). All names fictional. */
class OffspringEarningsTest extends TestCase
{
    use RefreshDatabase;

    private function horse(string $name): Horse
    {
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = BuildRecordsFromResults::system()->id;
        $h->save();

        return $h;
    }

    public function test_a_sire_line_that_names_one_record_counts_for_that_record(): void
    {
        $sire = $this->horse('Sample Earnings Sire');
        $foal = $this->horse('Sample Earnings Foal');
        PedigreeEntry::create(['horse_id' => $foal->id, 'position' => 's', 'name' => 'Sample Earnings Sire', 'visibility' => 'public',
            'added_by' => BuildRecordsFromResults::system()->id]);

        $stats = app(OffspringEarnings::class)();

        $this->assertSame(1, $stats['parents_matched_by_name']);
        $row = OffspringEarning::where('horse_id', $sire->id)->where('role', 'sire')->where('organization', 'all')->sole();
        $this->assertSame(1, (int) $row->offspring);
    }
}
