<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\Contributor;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 2: professional contributors (vet, farrier, trainer, breeder) invited by the owner. */
class ContributorTest extends TestCase
{
    use RefreshDatabase;

    private function horse(User $owner, string $name = 'Test Lark'): Horse
    {
        return app(CreateHorse::class)($owner, ['registered_name' => $name, 'barn_name' => $name, 'birth_precision' => 'unknown']);
    }

    /** Owner invites $email as $role; the vet accepts from their dashboard. */
    private function invitedVet(User $owner, Horse $horse, string $role = 'veterinarian'): User
    {
        $vet = User::factory()->create(['name' => 'Dr. Holt', 'email' => 'vet@example.com']);
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/contributors", ['email' => 'VET@example.com', 'role' => $role])->assertRedirect();
        $invite = Contributor::firstOrFail();
        $this->actingAs($vet)->get('/dashboard')->assertOk()->assertSee('invited you to contribute')->assertSee($horse->registered_name);
        $this->actingAs($vet)->post("/contributors/{$invite->id}/accept")->assertRedirect("/horses/{$horse->horsefax_id}/contribute");

        return $vet;
    }

    public function test_an_invited_professional_can_add_entries_and_documents_in_their_own_name(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'health', 'title' => 'Owner private colic note', 'date_precision' => 'unknown', 'visibility' => 'private']);

        // Someone else can't accept an invitation addressed to the vet.
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/contributors", ['email' => 'vet@example.com', 'role' => 'veterinarian']);
        $this->actingAs(User::factory()->create())->post('/contributors/'.Contributor::first()->id.'/accept')->assertNotFound();
        Contributor::query()->delete();

        $vet = $this->invitedVet($owner, $horse);
        $this->actingAs($vet)->get("/horses/{$horse->horsefax_id}")->assertRedirect("/horses/{$horse->horsefax_id}/contribute");
        $this->actingAs($vet)->get("/horses/{$horse->horsefax_id}/contribute")->assertOk()->assertDontSee('Owner private colic note');

        $this->actingAs($vet)->post("/horses/{$horse->horsefax_id}/contribute/events", [
            'category' => 'health', 'type' => 'coggins', 'title' => 'Coggins drawn', 'result' => 'Negative', 'date_precision' => 'unknown',
            'visibility' => 'public', 'amount' => '5000', // ignored: the owner controls visibility, and prices are the owner's
            'document' => UploadedFile::fake()->create('coggins.pdf', 30, 'application/pdf'), 'document_kind' => 'coggins',
        ])->assertRedirect();
        $this->actingAs($vet)->post("/horses/{$horse->horsefax_id}/contribute/documents", ['file' => UploadedFile::fake()->create('xray.pdf', 30, 'application/pdf'), 'kind' => 'vet_record', 'title' => 'Hock radiographs'])->assertRedirect();

        $event = HorseEvent::where('title', 'Coggins drawn')->firstOrFail();
        $this->assertSame($vet->id, $event->contributor_id);
        $this->assertSame('veterinarian', $event->contributor_role);
        $this->assertSame('private', $event->visibility);
        $this->assertSame('document_supplied', $event->status);
        $this->assertNull($event->amount_cents);
        $doc = $horse->documents()->where('title', 'Hock radiographs')->firstOrFail();
        $this->assertSame([$vet->id, 'veterinarian', 'private'], [$doc->uploaded_by, $doc->contributor_role, $doc->visibility]);

        $this->actingAs($vet)->get("/horses/{$horse->horsefax_id}/contribute")->assertOk()->assertSee('Coggins drawn')->assertSee('Hock radiographs');
        $this->actingAs($vet)->get('/dashboard')->assertOk()->assertSee('Horses you contribute to');

        // The owner sees it attributed to the vet and controls who else sees it.
        $this->actingAs($owner)->get("/horses/{$horse->horsefax_id}?tab=history")->assertOk()->assertSee('Dr. Holt (Veterinarian)');
        $this->actingAs($owner)->get("/horses/{$horse->horsefax_id}?tab=contributors")->assertOk()->assertSee('Dr. Holt')->assertSee('Active');
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/visibility", ['type' => 'event', 'id' => $event->id, 'visibility' => 'shared'])->assertRedirect();
        $this->assertSame('shared', $event->fresh()->visibility);

        foreach (['contributor.invited', 'contributor.accepted', 'event.added', 'document.uploaded', 'event.visibility_changed'] as $action) {
            $this->assertDatabaseHas('audit_log', ['horse_id' => $horse->id, 'action' => $action]);
        }
        $this->assertDatabaseHas('audit_log', ['action' => 'event.added', 'actor_id' => $vet->id, 'subject_id' => $event->id]);
    }

    public function test_a_professional_cannot_read_or_add_to_other_horses(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $other = $this->horse(User::factory()->create(), 'Other Horse');
        $vet = $this->invitedVet($owner, $horse);

        $h = $other->horsefax_id;
        $this->actingAs($vet)->get("/horses/$h")->assertRedirect("/horses/$h/claim");
        $this->actingAs($vet)->get("/horses/$h/contribute")->assertForbidden();
        $this->actingAs($vet)->get("/horses/$h/history")->assertForbidden();
        $this->actingAs($vet)->post("/horses/$h/contribute/events", ['category' => 'health', 'title' => 'x', 'date_precision' => 'unknown'])->assertForbidden();

        // Nor can they act as an owner on the horse they were invited to.
        $mine = $horse->horsefax_id;
        $this->actingAs($vet)->get("/horses/$mine/history")->assertForbidden();
        $this->actingAs($vet)->post("/horses/$mine/events", ['category' => 'health', 'title' => 'x', 'date_precision' => 'unknown', 'visibility' => 'public'])->assertForbidden();
        $this->actingAs($vet)->post("/horses/$mine/contributors", ['email' => 'friend@example.com', 'role' => 'farrier'])->assertForbidden();
        $this->assertSame(0, HorseEvent::count());
    }

    public function test_revoking_removes_access_but_keeps_past_entries(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $vet = $this->invitedVet($owner, $horse, 'farrier');
        $h = $horse->horsefax_id;
        $this->actingAs($vet)->post("/horses/$h/contribute/events", ['category' => 'care', 'type' => 'farrier', 'title' => 'Reset front shoes', 'date_precision' => 'unknown'])->assertRedirect();

        $contributor = Contributor::firstOrFail();
        $this->actingAs(User::factory()->create())->post("/horses/$h/contributors/{$contributor->id}/revoke")->assertForbidden();
        $this->actingAs($owner)->post("/horses/$h/contributors/{$contributor->id}/revoke")->assertRedirect();
        $this->assertSame('revoked', $contributor->fresh()->status);

        $this->actingAs($vet)->get("/horses/$h")->assertRedirect("/horses/$h/claim");
        $this->actingAs($vet)->get("/horses/$h/contribute")->assertForbidden();
        $this->actingAs($vet)->post("/horses/$h/contribute/events", ['category' => 'care', 'title' => 'After revoke', 'date_precision' => 'unknown'])->assertForbidden();
        $this->actingAs($vet)->get('/dashboard')->assertOk()->assertDontSee('Horses you contribute to');

        $kept = HorseEvent::where('title', 'Reset front shoes')->firstOrFail();
        $this->assertNull($kept->superseded_by);
        $this->assertSame([$vet->id, 'farrier'], [$kept->contributor_id, $kept->contributor_role]);
        $this->actingAs($owner)->get("/horses/$h?tab=history")->assertSee('Reset front shoes');
        $this->assertDatabaseHas('audit_log', ['horse_id' => $horse->id, 'action' => 'contributor.revoked', 'actor_id' => $owner->id]);
    }

    public function test_the_owner_cannot_rewrite_a_professionals_entry_but_can_dispute_it(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $vet = $this->invitedVet($owner, $horse);
        $h = $horse->horsefax_id;
        $this->actingAs($vet)->post("/horses/$h/contribute/events", ['category' => 'health', 'type' => 'exam', 'title' => 'Grade 2 lameness left fore', 'date_precision' => 'unknown'])->assertRedirect();
        $event = HorseEvent::firstOrFail();

        $this->actingAs($owner)->get("/horses/$h/events/{$event->id}/correct")->assertForbidden();
        $this->actingAs($owner)->post("/horses/$h/events/{$event->id}/correct", ['category' => 'health', 'title' => 'Sound', 'date_precision' => 'unknown', 'visibility' => 'public'])->assertForbidden();
        $this->assertSame(1, HorseEvent::count());
        $this->assertSame('Grade 2 lameness left fore', $event->fresh()->title);
        $this->assertNull($event->fresh()->superseded_by);

        $this->actingAs($owner)->get("/horses/$h?tab=history")->assertOk()->assertSee('Dispute')->assertSee('Send to Horsefax staff');
        $this->actingAs($owner)->post("/horse/$h/corrections", ['subject' => 'event:'.$event->id, 'statement' => 'Second opinion found her sound the next week.'])->assertRedirect();
        $item = ReviewItem::where('kind', 'dispute')->firstOrFail();
        $this->assertSame(['HorseEvent', $event->id, $owner->id], [$item->subject_type, $item->subject_id, $item->submitted_by]);
        $this->assertSame('self_reported', $event->fresh()->status); // nothing changes until staff decide
    }

    public function test_invitation_rules(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $horse = $this->horse($owner);
        $h = $horse->horsefax_id;
        $this->actingAs($owner)->post("/horses/$h/contributors", ['email' => 'owner@example.com', 'role' => 'trainer'])->assertSessionHasErrors('email');
        $this->actingAs($owner)->post("/horses/$h/contributors", ['email' => 'pro@example.com', 'role' => 'dentist'])->assertSessionHasErrors('role');
        $this->actingAs($owner)->post("/horses/$h/contributors", ['email' => 'pro@example.com', 'role' => 'trainer'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/horses/$h/contributors", ['email' => 'PRO@example.com', 'role' => 'breeder'])->assertSessionHasErrors('email');

        // Declining is recorded; a cancelled invitation can't be accepted.
        $pro = User::factory()->create(['email' => 'pro@example.com']);
        $invite = Contributor::firstOrFail();
        $this->actingAs($pro)->post("/contributors/{$invite->id}/decline")->assertRedirect();
        $this->assertSame('declined', $invite->fresh()->status);
        $this->actingAs($pro)->post("/contributors/{$invite->id}/accept")->assertNotFound();
        $this->assertDatabaseHas('audit_log', ['action' => 'contributor.declined', 'actor_id' => $pro->id]);

        $this->actingAs($owner)->post("/horses/$h/contributors", ['email' => 'pro@example.com', 'role' => 'trainer'])->assertSessionHasNoErrors();
        $second = Contributor::where('status', 'pending')->firstOrFail();
        $this->actingAs($owner)->post("/horses/$h/contributors/{$second->id}/revoke")->assertRedirect();
        $this->actingAs($pro)->post("/contributors/{$second->id}/accept")->assertNotFound();
        $this->assertFalse($horse->fresh()->canBeContributedToBy($pro));
        $this->assertSame(0, DB::table('contributors')->where('status', 'active')->count());
    }
}
