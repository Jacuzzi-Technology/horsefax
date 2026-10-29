<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Support\PedigreeDepth;
use App\Support\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Traced N generations": following linked sire/dam records, loop-safe, placeholders ignored (fictional horses). */
class PedigreeDepthTest extends TestCase
{
    use RefreshDatabase;

    private function horse(string $name): Horse
    {
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = BuildRecordsFromResults::system()->id;
        $h->forceFill(['source' => 'published', 'identity_status' => 'unverified'])->save();

        return $h;
    }

    private function line(Horse $h, string $pos, string $name, ?Horse $linked = null): void
    {
        PedigreeEntry::create(['horse_id' => $h->id, 'position' => $pos, 'name' => $name, 'linked_horse_id' => $linked?->id, 'visibility' => 'public',
            'added_by' => BuildRecordsFromResults::system()->id, 'source_type' => 'historical', 'status' => 'unverified']);
    }

    public function test_depth_follows_links_once_and_ignores_placeholders(): void
    {
        $a = $this->horse('Sample Gen Zero');
        $b = $this->horse('Sample Gen One');
        $c = $this->horse('Sample Gen Two');
        $unknown = $this->horse('Ismeretlen (Unknown)');
        $this->line($a, 's', 'Sample Gen One', $b);
        $this->line($b, 's', 'Sample Gen Two', $c);
        $this->line($c, 's', 'Sample Gen Three');
        $this->line($c, 'ss', 'Sample Gen Four');          // unlinked deeper line on the last record
        $this->line($c, 'd', 'Ismeretlen (Unknown)', $unknown);
        $this->line($unknown, 's', 'Ismeretlen (Unknown)', $unknown); // its own parent: a loop
        $this->line($b, 'd', 'Sample Gen Zero', $a);         // a bad link back down the tree

        $this->assertSame(4, PedigreeDepth::compute($a->id));
        $this->assertTrue(Record::placeholderName('Ismeretlen'));
        $this->assertTrue(Record::placeholderName('N.N.'));
        $this->assertTrue(Record::placeholderName('Desconocido'));
        $this->assertFalse(Record::placeholderName('Unknown Legend'));
    }
}
