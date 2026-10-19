<?php

namespace Tests\Feature;

use App\Actions\Claims\IssueClaimCode;
use App\Actions\Record\BuildRecordsFromResults;
use App\Models\ClaimCode;
use App\Models\ClaimConfirmation;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\IdentityCheck;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Claim codes, QR codes, registration matching, seller confirmation and identity checks (fictional people and horses). */
class OwnershipVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function horse(): Horse
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Frankie', 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'published', 'listed' => true, 'public_summary' => true])->save();

        return $h;
    }

    private function staff(): User
    {
        $s = User::factory()->create(['is_staff' => true]);
        $s->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();

        return $s;
    }

    public function test_qr_codes_open_the_record_and_carry_the_mark(): void
    {
        $h = $this->horse();
        $this->get(route('horse.qr', [$h, 'png']))->assertOk()->assertHeader('Content-Type', 'image/png');
        $svg = $this->get(route('horse.qr', [$h, 'svg']))->assertOk()->getContent();
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('#13201A', $svg); // the HF mark is drawn in
        $zip = $this->actingAs($this->staff())->post(route('admin.qr.batch'), ['sale' => 'Sample Sale', 'lines' => "14, {$h->horsefax_id}\nnot a horse"]);
        $zip->assertOk()->assertHeader('Content-Type', 'application/zip');
    }

    public function test_a_claim_code_makes_the_buyer_the_verified_owner_once(): void
    {
        $h = $this->horse();
        $code = app(IssueClaimCode::class)($h, $this->staff(), 'Horse of My Dreams');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code->plain());
        $this->actingAs($this->staff())->get(route('admin.claim-codes.certificate', $code))->assertOk()->assertSee($code->plain())->assertSee('Horse of My Dreams');

        $buyer = User::factory()->create();
        $this->actingAs($buyer)->post(route('claim-code.redeem'), ['code' => strtolower(str_replace('-', ' ', $code->plain()))])->assertRedirect(route('horses.show', $h));
        $this->assertTrue($h->activeStewards()->where('user_id', $buyer->id)->where('role', 'owner')->where('status', 'verified')->exists());
        $this->assertSame('verified_owner', $code->fresh()->redemption);

        // Used once only; a second code for an owned horse goes to staff instead of displacing the owner.
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('claim-code.redeem'), ['code' => $code->plain()])->assertSessionHasErrors('code');
        $second = app(IssueClaimCode::class)($h, $this->staff(), 'Sample Sale Co');
        $this->actingAs($other)->post(route('claim-code.redeem'), ['code' => $second->plain()])->assertRedirect(route('dashboard'));
        $this->assertFalse($h->activeStewards()->where('user_id', $other->id)->exists());
        $this->assertSame(1, ReviewItem::where('kind', 'ownership_claim')->where('submitted_by', $other->id)->count());
    }

    public function test_claims_check_the_registration_number_and_ask_the_seller(): void
    {
        Mail::fake();
        Storage::fake('local');
        $h = $this->horse();
        HorseIdentifier::create(['horse_id' => $h->id, 'kind' => 'registration', 'registry' => 'AQHA', 'value' => '6337081', 'value_normalized' => '6337081', 'visibility' => 'public', 'added_by' => BuildRecordsFromResults::system()->id, 'source_type' => 'historical']);
        $buyer = User::factory()->create(['name' => 'Sample Buyer']);
        $this->actingAs($buyer)->post(route('horses.claim.store', $h), [
            'statement' => 'I bought this horse at the Sample Sale in October 2026.', 'evidence' => [UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf')],
            'registration_number' => '6337081', 'seller_name' => 'Sample Seller', 'seller_email' => 'seller@example.com',
        ])->assertRedirect(route('dashboard'));
        $item = ReviewItem::where('kind', 'ownership_claim')->sole();
        $this->assertTrue($item->details['registration_match']);
        $c = ClaimConfirmation::sole();
        $this->assertNotNull($c->sent_at);

        // The seller answers from the link (the token is only in the email: reset it here to a known one).
        $c->forceFill(['token_hash' => hash('sha256', 'sample-token')])->save();
        $this->post(route('claims.confirm.respond', 'sample-token'), ['response' => 'confirmed'])->assertOk()->assertSee('confirmed this sale');
        $this->assertSame('confirmed', $c->fresh()->response);
        $this->actingAs($this->staff())->get(route('admin.review', $item))->assertOk()->assertSee('matches the record')->assertSee('confirmed the sale')->assertSee('not verified');
    }

    public function test_identity_checks_are_decided_by_staff_and_the_id_image_is_deleted(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('identity.submit'), ['legal_name' => 'Sample Person', 'document' => UploadedFile::fake()->image('id.jpg'), 'consent' => '1'])->assertRedirect(route('account'));
        $check = IdentityCheck::sole();
        Storage::disk('local')->assertExists($check->document_path);
        $path = $check->document_path;
        $item = ReviewItem::where('kind', 'identity_check')->sole();
        $staff = $this->staff();
        $this->actingAs($staff)->get(route('admin.review', $item))->assertOk()->assertSee('Sample Person')->assertSee('View the ID image');
        $this->actingAs($staff)->post(route('admin.review.approve', $item), ['note' => 'Matches the account', 'method' => 'id_document'])->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('Sample Person', $user->fresh()->identity_verified_name);
        $this->assertSame('verified', $check->fresh()->status);
    }
}
