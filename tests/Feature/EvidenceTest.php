<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseFact;
use App\Models\ReviewItem;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kate's evidence model: source separate from verification, verification details, identity facts, three dates, summary. */
class EvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
    }

    private function horse(User $owner): Horse
    {
        return app(CreateHorse::class)($owner, ['registered_name' => 'Evidence Horse', 'breed' => 'Quarter Horse', 'color' => 'Bay', 'height_hands' => '15.1', 'birth_precision' => 'year', 'birth_year' => 2018],
            [['kind' => 'registration', 'registry' => 'AQHA', 'value' => '5550001', 'visibility' => 'public']]);
    }

    public function test_claims_start_owner_reported_and_unverified_and_identity_facts_are_recorded(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $reg = $horse->identifiers()->first();
        $this->assertSame(['owner_reported', 'unverified'], [$reg->source_type, $reg->status]);
        $facts = $horse->currentFacts()->get()->keyBy('field');
        $this->assertSame('15.1', $facts['height']->value);
        $this->assertSame('owner_reported', $facts['breed']->source_type);
        $this->assertSame('2018', $facts['birth']->value);
    }

    public function test_staff_verification_records_how_and_what_it_covers_and_readers_can_see_it(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $horse->forceFill(['public_summary' => true])->save();
        $reg = $horse->identifiers()->first();

        $this->actingAs($this->staff())->post("/admin/verify/identifier/{$reg->id}", [
            'method' => 'registry_record', 'scope' => 'Registered name and number matched against the AQHA record. Does not establish ownership.',
            'recheck_on' => now()->addYear()->toDateString(), 'note' => 'Checked AQHA online',
        ])->assertRedirect();

        $reg->refresh();
        $this->assertSame('verified', $reg->status);
        $this->assertNotNull($reg->verified_at);
        $this->assertSame('owner_reported', $reg->source_type); // source stays: owner uploaded, Horsefax verified
        auth()->logout();
        $this->actingAs($this->reader())->get("/horse/{$horse->horsefax_id}")->assertOk()
            ->assertSee('Horsefax Verified')->assertSee('matched against the AQHA record')->assertSee('Horsefax Verification Team')
            ->assertSee('Owner reported')->assertDontSee('Checked AQHA online'); // internal note never shown

        // Withdrawing keeps the verification row with its reason.
        $this->actingAs($this->staff())->post("/admin/withdraw/identifier/{$reg->id}", ['reason' => 'Registry record changed', 'status' => 'unverified'])->assertRedirect();
        $this->assertSame('unverified', $reg->fresh()->status);
        $this->assertSame(1, Verification::whereNotNull('revoked_at')->count());
    }

    public function test_an_owner_cannot_silently_overwrite_a_verified_identity_value(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $height = $horse->currentFacts()->where('field', 'height')->first();
        $this->actingAs($this->staff())->post("/admin/verify/fact/{$height->id}", ['method' => 'physical_inspection', 'scope' => 'Height measured by Horsefax at inspection.'])->assertRedirect();

        // Owner changes height and color: color (unverified) changes, height is held for review.
        $this->actingAs($owner)->put("/horses/{$horse->horsefax_id}", [
            'registered_name' => 'Evidence Horse', 'breed_choice' => 'Quarter Horse', 'color_choice' => 'Dun', 'height_hands' => '16.0', 'birth_precision' => 'year', 'birth_year' => 2018,
        ])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'height') && str_contains($s, 'staff for review'));
        $horse->refresh();
        $this->assertSame('15.1', (string) $horse->height_hands);
        $this->assertSame('Dun', $horse->color);
        $this->assertSame('Bay', HorseFact::where('horse_id', $horse->id)->where('field', 'color')->whereNotNull('superseded_by')->value('value'));

        $item = ReviewItem::where('kind', 'fact_conflict')->first();
        $this->assertNotNull($item);
        $this->actingAs($this->staff())->post("/admin/reviews/{$item->id}/approve", ['note' => 'Remeasured at 16.0'])->assertRedirect();
        $this->assertSame('16.0', (string) $horse->fresh()->height_hands);
        $this->assertSame('superseded', $height->fresh()->status);
    }

    public function test_professional_entries_are_professional_attested_and_summary_states_what_is_established(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        HorseEvent::create(['horse_id' => $horse->id, 'category' => 'health', 'title' => 'Exam', 'contributor_id' => $owner->id, 'contributor_role' => 'veterinarian', 'source_type' => 'professional_attested', 'visibility' => 'public', 'date_precision' => 'unknown']);
        HorseEvent::create(['horse_id' => $horse->id, 'category' => 'training', 'title' => 'Started under saddle', 'contributor_id' => $owner->id, 'contributor_role' => 'owner', 'source_type' => 'owner_reported', 'visibility' => 'public', 'date_precision' => 'unknown']);
        $this->actingAs($this->staff())->post("/admin/verify/horse/{$horse->id}", ['method' => 'microchip_scan', 'scope' => 'Identity confirmed by chip scan and papers.']);
        $horse->forceFill(['public_summary' => true])->save();
        auth()->logout();

        $this->actingAs($this->reader())->get("/horse/{$horse->horsefax_id}")->assertOk()
            ->assertSee('Identity verified')->assertSee('1 professional record')->assertSee('1 owner-reported entry')
            ->assertSee('Professional attested')->assertSee('Reported '.now()->format('M j, Y'));
    }

    public function test_upholding_a_dispute_withdraws_the_verification(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $reg = $horse->identifiers()->first();
        $staff = $this->staff();
        $this->actingAs($staff)->post("/admin/verify/identifier/{$reg->id}", ['method' => 'registry_record', 'scope' => 'Matched against the registry record.']);
        $item = ReviewItem::create(['kind' => 'dispute', 'horse_id' => $horse->id, 'subject_type' => 'HorseIdentifier', 'subject_id' => $reg->id, 'statement' => 'Wrong number, see papers.']);
        $this->actingAs($staff)->post("/admin/reviews/{$item->id}/approve", ['note' => 'Papers show a different number'])->assertRedirect();
        $this->assertSame('disputed', $reg->fresh()->status);
        $this->assertNull($reg->fresh()->verification);
    }

    public function test_role_labels_distinguish_creator_claimed_and_verified_owner(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $this->actingAs($owner)->get("/horses/{$horse->horsefax_id}?tab=ownership")->assertOk()->assertSee('Claimed owner')->assertSee('Record created by you');
        $horse->stewardships()->update(['status' => 'verified']);
        $this->actingAs($owner)->get("/horses/{$horse->horsefax_id}?tab=ownership")->assertSee('Verified owner');
    }

    public function test_nothing_about_trusted_horse_appears_on_public_pages(): void
    {
        foreach (['/', '/how-it-works', '/about', '/sample-report', '/terms', '/privacy', '/for-owners'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Trusted Horse');
        }
    }
}
