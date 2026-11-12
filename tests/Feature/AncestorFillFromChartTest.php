<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Actions\Record\ImportPublishedHorse;
use App\Models\Horse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A chart that links to an ancestor record that already existed fills what the record lacks (Richard, Nov 12 2026):
 * numbers from any chart; colors and foal years only from registration papers. Fictional horses.
 */
class AncestorFillFromChartTest extends TestCase
{
    use RefreshDatabase;

    private function unowned(string $name, array $extra = []): Horse
    {
        $h = app(CreateHorse::class)(User::factory()->create(), ['registered_name' => $name, 'birth_precision' => 'unknown'] + $extra);
        $h->stewardships()->delete();

        return $h->fresh();
    }

    private function chart(string $name, array $sire, ?string $source = 'official_source'): void
    {
        app(ImportPublishedHorse::class)(['name' => $name, 'registry' => 'APHA', 'registration_number' => null, 'foal_year' => 2021,
            'birth_date' => null, 'sex' => 'mare', 'color' => null, 'pedigree' => ['s' => $sire], 'results' => [], 'source_type' => $source], 'test papers');
    }

    private function reg(Horse $h): ?string
    {
        return $h->identifiers()->where('kind', 'registration')->value('value');
    }

    public function test_papers_give_an_existing_ancestor_its_number_color_and_foal_year(): void
    {
        $sire = $this->unowned('Sample Diamondcutter');
        $this->chart('Sample Daughter', ['name' => 'Sample Diamondcutter', 'registry' => 'APHA', 'registration_number' => '01000001', 'foal_year' => 2015, 'color' => 'Buckskin']);

        $sire->refresh();
        $this->assertSame(['01000001', 'Buckskin', 'year', 2015], [$this->reg($sire), $sire->color, $sire->birth_precision, (int) $sire->birth_year]);
        $this->assertSame('official_source', $sire->identifiers()->value('source_type'));
        $this->assertSame(1, Horse::where('registered_name', 'Sample Diamondcutter')->count());
    }

    public function test_a_scraped_chart_gives_the_number_only(): void
    {
        $sire = $this->unowned('Sample Scraped Sire');
        $this->chart('Sample Foal', ['name' => 'Sample Scraped Sire', 'registry' => 'APHA', 'registration_number' => '01000002', 'foal_year' => 2015, 'color' => 'Bay'], 'historical');

        $sire->refresh();
        $this->assertSame(['01000002', null, 'unknown'], [$this->reg($sire), $sire->color, $sire->birth_precision]);
    }

    public function test_papers_color_replaces_a_different_scraped_color_but_keeps_a_fuller_one(): void
    {
        $a = $this->unowned('Sample Wrong Color', ['color' => 'Sorrel']);
        $b = $this->unowned('Sample Full Color', ['color' => 'Bay Tobiano']);
        $this->chart('Sample One', ['name' => 'Sample Wrong Color', 'color' => 'Palomino']);
        $this->chart('Sample Two', ['name' => 'Sample Full Color', 'color' => 'Bay']);

        $this->assertSame('Palomino', $a->fresh()->color);
        $this->assertSame('Bay Tobiano', $b->fresh()->color);
    }

    public function test_an_owned_ancestor_and_an_existing_number_are_never_changed(): void
    {
        $owned = app(CreateHorse::class)(User::factory()->create(), ['registered_name' => 'Sample Owned Sire', 'birth_precision' => 'unknown']);
        $this->chart('Sample Three', ['name' => 'Sample Owned Sire', 'registry' => 'APHA', 'registration_number' => '01000003', 'color' => 'Black']);
        $this->assertSame([null, null], [$this->reg($owned->fresh()), $owned->fresh()->color]);

        $numbered = $this->unowned('Sample Numbered Sire');
        app(\App\Actions\Record\AddIdentifier::class)($numbered, User::factory()->create(), ['kind' => 'registration', 'registry' => 'APHA', 'value' => '01000004']);
        $this->chart('Sample Four', ['name' => 'Sample Numbered Sire', 'registry' => 'APHA', 'registration_number' => '01000005']);
        $this->assertSame(['01000004'], $numbered->identifiers()->where('kind', 'registration')->pluck('value')->all());
    }

    public function test_backfill_fills_ancestors_linked_before_the_rule(): void
    {
        $sire = $this->unowned('Sample Old Link');
        $this->chart('Sample Five', ['name' => 'Sample Old Link', 'registry' => 'APHA', 'registration_number' => '01000006', 'color' => 'Dun'], 'historical');
        // Undo what the new rule did, as if linked before it existed, and mark the line as from papers via the audit.
        $sire->identifiers()->delete();
        $this->assertNull($this->reg($sire->fresh()));

        $this->artisan('hf:fill-ancestors-from-charts')->assertSuccessful();
        $this->assertSame('01000006', $this->reg($sire->fresh()));
        $this->assertNull($sire->fresh()->color); // the line was not from papers
    }
}
