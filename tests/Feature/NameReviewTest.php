<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Actions\Record\FindNameVariants;
use App\Actions\Record\ImportPublishedHorse;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\NameReview;
use App\Models\PedigreeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Possible duplicate names: the staff list, "different horses", and merging a misspelling. */
class NameReviewTest extends TestCase
{
    use RefreshDatabase;

    private function unowned(string $name, string $source): Horse
    {
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = User::factory()->create()->id;
        $h->forceFill(['source' => $source, 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();

        return $h;
    }

    public function test_pairs_are_found_prioritised_decided_and_merged(): void
    {
        $right = $this->unowned('Sample Gota Lota Potential', 'results');
        $typo = $this->unowned('Sample Gotta Lotta Potential', 'pedigree');      // two letters off, only from a chart
        $spaced = $this->unowned('Sample GotaLota Potential', 'results');        // same letters
        $twinA = $this->unowned('Sample Smart Spark', 'results');
        $twinB = $this->unowned('Sample Smart Sparx', 'results');
        HorseEvent::create(['horse_id' => $typo->id, 'category' => 'competition', 'type' => 'show', 'title' => 'Sample Class', 'contributor_id' => $typo->created_by,
            'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public', 'occurred_on' => '2020-10-01', 'date_precision' => 'year']);
        $foal = $this->unowned('Sample Foal', 'published');
        PedigreeEntry::create(['horse_id' => $foal->id, 'position' => 'd', 'name' => 'Sample Gotta Lotta Potential', 'linked_horse_id' => $typo->id, 'visibility' => 'public', 'added_by' => $foal->created_by]);

        $this->assertGreaterThan(0, app(FindNameVariants::class)());
        $this->assertSame(0, app(FindNameVariants::class)()); // nothing new on a rerun
        $pair = fn ($x, $y) => NameReview::whereIn('horse_a_id', [$x->id, $y->id])->whereIn('horse_b_id', [$x->id, $y->id])->first();
        $this->assertSame(1, $pair($right, $spaced)->priority);
        $this->assertSame(2, $pair($right, $typo)->priority);
        $this->assertSame(3, $pair($twinA, $twinB)->priority);

        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->get(route('admin.names'))->assertOk()->assertSee('Sample Gotta Lotta Potential');

        $this->actingAs($staff)->post(route('admin.names.decide', $pair($twinA, $twinB)), ['decision' => 'different'])->assertRedirect();
        $this->assertSame('different_horses', $pair($twinA, $twinB)->status);

        $r = $pair($right, $typo);
        $this->actingAs($staff)->post(route('admin.names.decide', $r), [
            'decision' => $r->horse_a_id === $right->id ? 'keep_a' : 'keep_b',
            ($r->horse_a_id === $right->id ? 'reg_a' : 'reg_b') => '3491928', 'note' => 'AQHA name search',
        ])->assertRedirect();

        $typo->refresh();
        $this->assertSame($right->id, $typo->merged_into_id);
        $this->assertSame(1, $right->events()->count());
        $this->assertSame('3491928', $right->identifiers()->value('value'));
        $line = PedigreeEntry::where('horse_id', $foal->id)->where('position', 'd')->first();
        $this->assertSame([$right->id, 'Sample Gota Lota Potential'], [$line->linked_horse_id, $line->name]);
        $this->assertTrue($right->names()->where('name', 'Sample Gotta Lotta Potential')->exists());
        $this->get(route('horse.public', $typo))->assertRedirect(route('horse.public', $right));
        $this->assertSame([], array_column($this->getJson(route('search.suggest', ['q' => 'gotta lotta']))->json(), 'name'));

        // A later source that still uses the misspelling lands on the right record.
        app(ImportPublishedHorse::class)(['name' => 'Sample Gotta Lotta Potential', 'foal_year' => null, 'registry' => null, 'registration_number' => null,
            'color' => 'Bay', 'sex' => 'mare', 'birth_date' => null, 'pedigree' => [], 'results' => []], 'https://example.test');
        $this->assertSame('Bay', $right->fresh()->color);
        $this->assertSame(0, Horse::where('registered_name', 'Sample Gotta Lotta Potential')->whereNull('merged_into_id')->count());
    }

    public function test_an_owned_misspelling_is_never_merged(): void
    {
        $owner = User::factory()->create();
        $owned = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned Lark', 'birth_precision' => 'unknown']);
        $other = $this->unowned('Sample Owned Larke', 'results');
        app(FindNameVariants::class)();
        $r = NameReview::firstOrFail();
        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->post(route('admin.names.decide', $r), ['decision' => $r->horse_a_id === $owned->id ? 'keep_b' : 'keep_a'])->assertStatus(422);
        $this->assertNull($owned->fresh()->merged_into_id);
    }
}
