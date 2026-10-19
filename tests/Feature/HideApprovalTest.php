<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\HorseEvent;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owners can't hide anything without staff approval, and can't hide sale history at all (Richard, Oct 19 2026). */
class HideApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_hiding_needs_approval_showing_does_not_and_sale_history_cannot_be_hidden(): void
    {
        $owner = User::factory()->create();
        $h = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned', 'birth_precision' => 'unknown']);
        $h->forceFill(['public_summary' => true, 'listed' => true])->save();
        $sys = \App\Actions\Record\BuildRecordsFromResults::system();
        $ev = fn (array $a) => HorseEvent::create($a + ['horse_id' => $h->id, 'contributor_id' => $sys->id, 'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified']);
        $show = $ev(['category' => 'competition', 'type' => 'show', 'title' => 'Sample Open', 'visibility' => 'public']);
        $sale = $ev(['category' => 'ownership', 'type' => 'auction', 'title' => 'Reported auction sale', 'result' => 'reported_sale', 'amount_cents' => 900000, 'amount_visibility' => 'public', 'visibility' => 'public']);
        $note = HorseEvent::create(['horse_id' => $h->id, 'category' => 'training', 'type' => 'program', 'title' => 'Sample note', 'contributor_id' => $owner->id, 'contributor_role' => 'owner', 'source_type' => 'owner_reported', 'status' => 'unverified', 'visibility' => 'private']);
        $this->actingAs($owner);

        // Hiding a show result: a request; it stays public until staff approve.
        $this->post(route('record.visibility', $h), ['type' => 'event', 'id' => $show->id, 'visibility' => 'private'])->assertSessionHas('status');
        $this->assertSame('public', $show->fresh()->visibility);
        $req = ReviewItem::where('kind', 'visibility_change')->sole();

        // Showing more applies at once.
        $this->post(route('record.visibility', $h), ['type' => 'event', 'id' => $note->id, 'visibility' => 'public']);
        $this->assertSame('public', $note->fresh()->visibility);

        // Sale history: refused, no request.
        $this->post(route('record.visibility', $h), ['type' => 'amount', 'id' => $sale->id, 'visibility' => 'private'])->assertSessionHasErrors('visibility');
        $this->post(route('record.visibility', $h), ['type' => 'event', 'id' => $sale->id, 'visibility' => 'private'])->assertSessionHasErrors('visibility');
        $this->assertSame(['public', 'public'], [$sale->fresh()->visibility, $sale->fresh()->amount_visibility]);
        $this->assertSame(1, ReviewItem::where('kind', 'visibility_change')->count());

        // Turning the public record off: a request; it stays on.
        $this->post(route('sharing.public', $h), ['public_summary' => '0', 'listed' => '1']);
        $this->assertTrue($h->fresh()->public_summary);
        $this->assertSame(2, ReviewItem::where('kind', 'visibility_change')->count());

        // Staff approve the show-result request: now it's hidden.
        $staff = User::factory()->create(['is_staff' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($staff)->post(route('admin.review.approve', $req), ['note' => 'Wrong horse: matched by name only'])->assertRedirect();
        $this->assertSame('private', $show->fresh()->visibility);
    }
}
