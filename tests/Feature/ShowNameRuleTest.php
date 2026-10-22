<?php

namespace Tests\Feature;

use App\Actions\Record\AttachShowResults;
use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Actions\Record\Reviews;
use App\Actions\Record\ShowNameRule;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseName;
use App\Models\ReviewItem;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A horse can be renamed with AQHA before it's shown, never after (Richard, Oct 20 2026). Fictional names.
 */
class ShowNameRuleTest extends TestCase
{
    use RefreshDatabase;

    private function published(string $name, int $year): ShowRecord
    {
        $ds = DataSource::firstOrCreate(['key' => 'nqha'], ['name' => 'nqha', 'url' => 'https://example.test/nqha']);
        $page = HarvestPage::firstOrCreate(['url' => 'https://example.test/nqha/'.$year], ['data_source_id' => $ds->id, 'kind' => 'class', 'status' => 'parsed']);
        $rec = ShowRecord::firstOrCreate(['name_key' => ShowRecord::key($name)], ['display_name' => $name]);
        ShowAppearance::create([
            'show_record_id' => $rec->id, 'data_source_id' => $ds->id, 'harvest_page_id' => $page->id, 'horse_name' => $name,
            'event_name' => 'Sample Show '.$year.' '.uniqid(), 'event_year' => $year, 'class_name' => 'Sample Class', 'placing' => '1',
            'organization' => 'AQHA', 'registry_inferred' => 'AQHA', 'source_url' => $page->url,
        ]);
        \App\Harvest\CongressHarvester::refresh($rec->id);

        return $rec->fresh();
    }

    /** A horse renamed before it showed: its old name is a previous name. */
    private function renamedHorse(User $owner): Horse
    {
        $horse = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Spark For Gold', 'birth_precision' => 'unknown']);
        HorseName::create(['horse_id' => $horse->id, 'name' => 'Sample Seizing The Day', 'kind' => 'previous', 'added_by' => $owner->id]);

        return $horse;
    }

    private function staff(): User
    {
        return User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
    }

    public function test_results_under_the_current_name_attach_as_usual(): void
    {
        $horse = $this->renamedHorse(User::factory()->create());
        $rec = $this->published('Sample Spark For Gold', 2024);

        $this->assertSame(1, app(AttachShowResults::class)($rec, $horse, $this->staff()));
        $this->assertSame(0, ReviewItem::where('kind', ShowNameRule::KIND)->count());
    }

    public function test_results_under_a_previous_name_are_held_for_staff_not_attached(): void
    {
        $horse = $this->renamedHorse(User::factory()->create());
        $rec = $this->published('Sample Seizing The Day', 2025);
        $staff = $this->staff();

        $this->assertSame(0, app(AttachShowResults::class)($rec, $horse, $staff));
        $this->assertSame(0, $horse->events()->count());
        $this->assertNull($rec->fresh()->horse_id);
        $item = ReviewItem::where('kind', ShowNameRule::KIND)->sole();
        $this->assertSame(['former_name_results', $horse->id, $rec->id], [$item->details['case'], $item->horse_id, $item->subject_id]);
        $this->assertStringContainsString('previous name', $item->statement);

        // Asking again doesn't pile up duplicate review items.
        app(AttachShowResults::class)($rec, $horse, $staff);
        $this->assertSame(1, ReviewItem::where('kind', ShowNameRule::KIND)->count());

        $this->actingAs($staff)->get(route('admin.review', $item))->assertOk()
            ->assertSee('Name change vs show record')->assertSee('Attach them anyway');

        // Staff who confirm from the papers can still attach them.
        app(Reviews::class)->approve($item, $staff, 'Checked the show entry: same horse.');
        $this->assertSame(1, $horse->events()->count());
        $this->assertSame($horse->id, $rec->fresh()->horse_id);
    }

    public function test_building_records_never_makes_a_duplicate_horse_from_a_former_name(): void
    {
        $horse = $this->renamedHorse(User::factory()->create());
        $this->published('Sample Seizing The Day', 2025);

        $out = app(BuildRecordsFromResults::class)();

        $this->assertSame([0, 1], [$out['created'], $out['held_former_name']]);
        $this->assertNull(Horse::where('registered_name', 'Sample Seizing The Day')->first());
        $this->assertSame($horse->id, ReviewItem::where('kind', ShowNameRule::KIND)->sole()->horse_id);
    }

    public function test_renaming_a_horse_that_has_shown_goes_to_staff(): void
    {
        $owner = User::factory()->create();
        $horse = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Shown Name', 'birth_precision' => 'unknown']);
        app(AttachShowResults::class)($this->published('Sample Shown Name', 2024), $horse, $this->staff());

        $this->actingAs($owner)->put(route('horses.update', $horse), ['registered_name' => 'Sample New Name', 'birth_precision' => 'unknown'])
            ->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'staff will check the new name'));

        $item = ReviewItem::where('kind', ShowNameRule::KIND)->sole();
        $this->assertSame(['renamed_after_shows', 'Sample Shown Name', 'Sample New Name'], [$item->details['case'], $item->details['old_name'], $item->details['new_name']]);
    }

    public function test_renaming_a_horse_that_has_not_shown_is_not_flagged(): void
    {
        $owner = User::factory()->create();
        $horse = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Young Horse', 'birth_precision' => 'unknown']);

        $this->actingAs($owner)->put(route('horses.update', $horse), ['registered_name' => 'Sample Catchy Name', 'birth_precision' => 'unknown'])->assertRedirect();
        $this->actingAs($owner)->post(route('record.names', $horse), ['name' => 'Sample Young Horse', 'kind' => 'previous', 'visibility' => 'shared'])->assertRedirect();

        $this->assertSame(0, ReviewItem::where('kind', ShowNameRule::KIND)->count());
    }

    public function test_adding_a_previous_name_the_horse_showed_under_goes_to_staff(): void
    {
        $owner = User::factory()->create();
        $horse = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Current', 'birth_precision' => 'unknown']);
        $old = $this->published('Sample Shown Before', 2023);
        app(AttachShowResults::class)($old, $horse, $this->staff()); // attached before the previous name was recorded

        $this->actingAs($owner)->post(route('record.names', $horse), ['name' => 'Sample Shown Before', 'kind' => 'previous', 'visibility' => 'shared'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'staff will check'));
        $this->assertSame('renamed_after_shows', ReviewItem::where('kind', ShowNameRule::KIND)->sole()->details['case']);
    }
}
