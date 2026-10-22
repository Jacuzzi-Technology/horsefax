<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Actions\Record\ShareLinks;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\ReviewItem;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Acceptance criteria from the Horsefax build sheet, §11. */
class RecordTest extends TestCase
{
    use RefreshDatabase;

    private function horse(User $owner, array $facts = [], array $ids = []): Horse
    {
        return app(CreateHorse::class)($owner, $facts + ['registered_name' => 'Test Lark', 'barn_name' => 'Lark', 'birth_precision' => 'year', 'birth_year' => 2016], $ids);
    }

    private function staff(): User
    {
        return User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
    }

    public function test_creating_a_horse_gives_a_stable_horsefax_id_and_owner(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/horses', ['registered_name' => 'Juniper Creek Lark', 'birth_precision' => 'unknown', 'attest' => '1'])->assertRedirect();

        $horse = Horse::first();
        $this->assertMatchesRegularExpression('/^HF-[2-9A-HJKMNP-TV-Z]{4}-[2-9A-HJKMNP-TV-Z]{4}$/', $horse->horsefax_id);
        $this->assertSame('unverified', $horse->identity_status);
        $this->assertTrue($horse->canBeEditedBy($owner));
        $this->assertDatabaseHas('audit_log', ['horse_id' => $horse->id, 'action' => 'horse.created', 'actor_id' => $owner->id]);
    }

    public function test_same_registered_name_does_not_collide_but_same_identifier_goes_to_review(): void
    {
        $a = $this->horse(User::factory()->create(), [], [['kind' => 'registration', 'registry' => 'AQHA', 'value' => '1234567']]);
        $b = $this->horse(User::factory()->create());
        $this->assertNotSame($a->horsefax_id, $b->horsefax_id);
        $this->assertSame('unverified', $b->identity_status);

        $c = $this->horse(User::factory()->create(), [], [['kind' => 'registration', 'registry' => 'AQHA', 'value' => '1234-567']]);
        $this->assertSame('under_review', $c->identity_status);
        $this->assertSame(1, ReviewItem::where('kind', 'identifier_conflict')->where('horse_id', $c->id)->count());
        $this->assertTrue($a->identifiers()->first()->in_conflict);

        $chip1 = $this->horse(User::factory()->create(), [], [['kind' => 'microchip', 'value' => '985000000012345']]);
        $chip2 = $this->horse(User::factory()->create(), [], [['kind' => 'microchip', 'value' => '985 000 000 012 345']]);
        $this->assertSame('under_review', $chip2->identity_status);
    }

    public function test_creating_a_horse_with_a_known_number_offers_a_claim_first(): void
    {
        $this->horse(User::factory()->create(), [], [['kind' => 'microchip', 'value' => '985000000012345']]);
        $this->actingAs(User::factory()->create())
            ->post('/horses', ['barn_name' => 'Other', 'microchip' => '985000000012345', 'birth_precision' => 'unknown', 'attest' => '1'])
            ->assertOk()->assertSee('may already be on Horsefax');
        $this->assertSame(1, Horse::count());
    }

    public function test_private_items_never_reach_public_pages_search_or_reports(): void
    {
        $owner = User::factory()->create(['name' => 'Secret Owner', 'email' => 'secret@example.com']);
        $horse = $this->horse($owner, [], [['kind' => 'microchip', 'value' => '985000000099999']]);
        $horse->forceFill(['public_summary' => true])->save();
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'health', 'title' => 'Colic surgery', 'date_precision' => 'unknown', 'visibility' => 'private'])->assertRedirect();
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'training', 'title' => 'Reining program', 'date_precision' => 'unknown', 'visibility' => 'public'])->assertRedirect();
        auth()->logout();

        $page = $this->actingAs($this->reader())->get("/horse/{$horse->horsefax_id}")->assertOk();
        $page->assertSee('Reining program')->assertDontSee('Colic surgery')->assertDontSee('985000000099999')->assertDontSee('Secret Owner')->assertDontSee('secret@example.com');

        $link = app(ShareLinks::class)->create($horse, $owner, ['health', 'identifiers'], null, null);
        $this->get('/report/'.$link->token)->assertOk()->assertDontSee('Colic surgery')->assertDontSee('985000000099999')->assertDontSee('Secret Owner');

        // The number someone typed is echoed back in their own search box, but never shown from the record.
        $this->get('/search?microchip=985000000099999')->assertOk()->assertSee($horse->horsefax_id)->assertSee('Microchip number');
        $this->get('/search?name=Test+Lark')->assertOk()->assertSee($horse->horsefax_id)->assertDontSee('985000000099999');
    }

    public function test_report_link_sections_and_revocation(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'health', 'title' => 'Coggins negative', 'date_precision' => 'unknown', 'visibility' => 'shared']);
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'competition', 'title' => 'Futurity', 'date_precision' => 'unknown', 'visibility' => 'shared', 'event_name' => 'Big Futurity', 'earnings' => '1500']);

        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/sharing/links", ['sections' => ['competition'], 'days' => '7'])->assertRedirect();
        $link = ShareLink::first();
        auth()->logout();
        $this->get('/report/'.$link->token)->assertOk()->assertSee('Big Futurity')->assertSee('$1,500')->assertDontSee('Coggins negative');

        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/sharing/links/{$link->id}/revoke")->assertRedirect();
        auth()->logout();
        $this->get('/report/'.$link->token)->assertStatus(410);

        $expired = app(ShareLinks::class)->create($horse, $owner, [], null, 7);
        $this->travel(8)->days();
        $this->get('/report/'.$expired->token)->assertStatus(410);
    }

    public function test_strangers_cannot_read_or_edit_a_record_or_its_files(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/documents", ['file' => UploadedFile::fake()->create('coggins.pdf', 50, 'application/pdf'), 'kind' => 'coggins', 'visibility' => 'shared'])->assertRedirect();
        $doc = $horse->documents()->first();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/horses/{$horse->horsefax_id}")->assertRedirect("/horses/{$horse->horsefax_id}/claim");
        $this->actingAs($stranger)->get("/horses/{$horse->horsefax_id}/edit")->assertForbidden();
        $this->actingAs($stranger)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'other', 'title' => 'x', 'date_precision' => 'unknown', 'visibility' => 'public'])->assertForbidden();
        $this->actingAs($stranger)->get("/files/{$doc->id}")->assertNotFound();
        auth()->logout();
        $this->get("/files/{$doc->id}")->assertNotFound();
        $this->actingAs($this->reader())->get("/horse/{$horse->horsefax_id}")->assertOk()->assertSee('This record is private'); // listed, not published
        $horse->forceFill(['listed' => false])->save();
        $this->actingAs($this->reader())->get("/horse/{$horse->horsefax_id}")->assertNotFound();
    }

    public function test_claims_need_staff_and_never_displace_the_owner_automatically(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $claimant = User::factory()->create();
        $this->actingAs($claimant)->post("/horses/{$horse->horsefax_id}/claim", ['statement' => 'I bought her at the spring sale in 2026.', 'evidence' => [UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf')]])->assertRedirect();
        $this->assertFalse($horse->fresh()->isStewardedBy($claimant));
        $this->assertTrue($horse->fresh()->isStewardedBy($owner));

        $item = ReviewItem::where('kind', 'ownership_claim')->first();
        $this->actingAs($this->staff())->post("/admin/reviews/{$item->id}/approve", ['note' => 'Bill of sale checked', 'end_current_owner' => '1'])->assertRedirect();
        $horse->refresh();
        $this->assertTrue($horse->isStewardedBy($claimant));
        $this->assertFalse($horse->isStewardedBy($owner));
        $this->assertSame(2, $horse->stewardships()->count()); // history kept
    }

    public function test_transfer_needs_the_new_owner_to_accept(): void
    {
        $owner = User::factory()->create();
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $horse = $this->horse($owner);
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'care', 'title' => 'Farrier', 'date_precision' => 'unknown', 'visibility' => 'private']);
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/transfer", ['email' => 'BUYER@example.com', 'confirm' => '1'])->assertRedirect();
        $this->assertTrue($horse->fresh()->isStewardedBy($owner));

        $transfer = \App\Models\Transfer::first();
        $this->actingAs(User::factory()->create())->post("/transfers/{$transfer->id}/accept")->assertNotFound();
        $this->actingAs($buyer)->post("/transfers/{$transfer->id}/accept")->assertRedirect();
        $horse->refresh();
        $this->assertTrue($horse->canBeEditedBy($buyer));
        $this->assertFalse($horse->canBeEditedBy($owner));
        $this->assertSame($owner->id, HorseEvent::where('title', 'Farrier')->value('contributor_id'));
    }

    public function test_changes_are_audited_and_the_audit_log_is_append_only(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $this->actingAs($owner)->put("/horses/{$horse->horsefax_id}", ['registered_name' => 'Test Lark II', 'birth_precision' => 'year', 'birth_year' => 2016])->assertRedirect();
        $row = DB::table('audit_log')->where('action', 'horse.identity_updated')->first();
        $this->assertStringContainsString('Test Lark', $row->before);
        $this->assertStringContainsString('Test Lark II', $row->after);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('audit_log')->where('id', $row->id)->delete();
    }

    public function test_corrections_supersede_and_disputes_label_without_deleting(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $horse->forceFill(['public_summary' => true])->save();
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events", ['category' => 'competition', 'title' => 'Won the futurity', 'date_precision' => 'unknown', 'visibility' => 'public']);
        $event = HorseEvent::first();
        auth()->logout();

        $this->post("/horse/{$horse->horsefax_id}/corrections", ['name' => 'A Judge', 'email' => 'judge@example.com', 'subject' => 'event:'.$event->id, 'statement' => 'She placed 3rd, not 1st; see the official results.'])->assertRedirect();
        $item = ReviewItem::where('kind', 'dispute')->first();
        $this->assertSame('unverified', $event->fresh()->status);

        $this->actingAs($this->staff())->post("/admin/reviews/{$item->id}/approve", ['note' => 'Checked association results'])->assertRedirect();
        $this->assertSame('disputed', $event->fresh()->status);

        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/events/{$event->id}/correct", ['category' => 'competition', 'title' => 'Placed 3rd at the futurity', 'date_precision' => 'unknown', 'visibility' => 'public'])->assertRedirect();
        $this->assertSame(2, HorseEvent::count());
        $this->assertNotNull($event->fresh()->superseded_by);
        auth()->logout();
        $this->actingAs($this->reader())->get("/horse/{$horse->horsefax_id}")->assertSee('Placed 3rd at the futurity')->assertDontSee('Won the futurity');
    }

    public function test_search_narrows_until_ten_or_fewer_match(): void
    {
        $owner = User::factory()->create();
        foreach (range(1, 12) as $i) {
            $this->horse($owner, ['registered_name' => 'Smart Lena '.$i, 'birth_year' => 2010 + $i]);
        }
        $this->get('/search?name=smart+lena')->assertOk()->assertSee('12 horses match')->assertDontSee('Smart Lena 3');
        $this->get('/search?name=smart+lena&foal_year=2013')->assertOk()->assertSee('Smart Lena 3')->assertDontSee('Smart Lena 4');
        $this->get('/search?name=nobody+here')->assertOk()->assertSee('Add this horse');

        Horse::where('registered_name', 'Smart Lena 3')->update(['listed' => false]);
        $this->get('/search?name=smart+lena&foal_year=2013')->assertDontSee('Smart Lena 3');
    }

    public function test_media_without_a_release_stays_private(): void
    {
        $owner = User::factory()->create();
        $horse = $this->horse($owner);
        $horse->forceFill(['public_summary' => true])->save();
        $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/media", ['url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'caption' => 'Reining pattern', 'visibility' => 'public'])->assertRedirect();
        $this->assertSame('private', $horse->media()->first()->visibility);
        auth()->logout();
        $this->actingAs($this->reader())->get("/horse/{$horse->horsefax_id}")->assertDontSee('Reining pattern');
    }

    public function test_staff_pages_need_staff_with_two_factor(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertNotFound();
        $this->actingAs(User::factory()->create(['is_staff' => true]))->get('/admin')->assertRedirect('/account');
        $this->actingAs($this->staff())->get('/admin')->assertOk();
    }

    public function test_public_pages_render(): void
    {
        foreach (['/', '/search', '/how-it-works', '/for-owners', '/for-professionals', '/about', '/privacy', '/terms', '/record-corrections', '/contact', '/sample-report', '/login', '/register', '/robots.txt', '/sitemap.xml'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_signed_in_and_staff_pages_render(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $horse = $this->horse($owner, [], [['kind' => 'microchip', 'value' => '985000000011111'], ['kind' => 'registration', 'registry' => 'AQHA', 'value' => '5551234']]);
        $h = $horse->horsefax_id;
        $this->actingAs($owner)->post("/horses/$h/events", ['category' => 'competition', 'type' => 'show', 'title' => 'Reining', 'event_name' => 'NRHA Futurity', 'placing' => '3T', 'earnings' => '318.83', 'rider' => 'A Rider', 'date_precision' => 'day', 'occurred_on' => '2025-03-01', 'visibility' => 'public']);
        $this->actingAs($owner)->post("/horses/$h/events", ['category' => 'ownership', 'type' => 'sale', 'title' => 'Sale', 'amount' => '15000', 'amount_visibility' => 'private', 'date_precision' => 'month', 'visibility' => 'shared']);
        $this->actingAs($owner)->post("/horses/$h/pedigree", ['lines' => ['s' => ['name' => 'Wimpys High Bid'], 'd' => ['name' => 'Sheza Royal Horizon'], 'ds' => ['name' => 'Lenas Horizon']], 'visibility' => 'public'])->assertRedirect();
        $this->actingAs($owner)->post("/horses/$h/photo", ['photo' => UploadedFile::fake()->image('lark.jpg'), 'visibility' => 'public'])->assertRedirect();
        $this->actingAs($owner)->post("/horses/$h/media", ['file' => UploadedFile::fake()->image('ride.jpg'), 'caption' => 'At the futurity', 'rights_attested' => '1', 'visibility' => 'public'])->assertRedirect();
        $this->actingAs($owner)->post("/horses/$h/names", ['name' => 'JC Lark', 'kind' => 'alias', 'visibility' => 'public'])->assertRedirect();

        foreach (['', '?tab=overview', '?tab=pedigree', '?tab=history', '?tab=documents', '?tab=media', '?tab=ownership', '?tab=contributors', '/edit', '/sharing', '/history'] as $suffix) {
            $this->actingAs($owner)->get("/horses/$h$suffix")->assertOk();
        }
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee($horse->registered_name);
        $this->actingAs($owner)->get('/account')->assertOk();
        $this->actingAs($owner)->get('/horses/new?registered_name=Foo&microchip=123')->assertOk()->assertSee('Foo');

        $horse->forceFill(['public_summary' => true])->save();
        auth()->logout();
        $this->actingAs($this->reader())->get("/horse/$h")->assertOk()->assertSee('NRHA Futurity')->assertSee('$318.83')->assertSee('Wimpys High Bid')->assertSee('At the futurity')->assertDontSee('$15,000');

        $staff = $this->staff();
        $this->actingAs($staff)->get('/admin')->assertOk();
        $this->actingAs($staff)->get('/admin/horses?q=lark')->assertOk()->assertSee($h);
        $this->actingAs($staff)->get("/admin/horses/$h")->assertOk();
        $this->actingAs($staff)->get('/admin/messages')->assertOk();
        $this->actingAs($staff)->post("/admin/verify/horse/{$horse->id}", ['method' => 'microchip_scan', 'scope' => 'Identity confirmed from papers and a chip scan.', 'note' => 'Papers and chip scan checked'])->assertRedirect();
        $this->assertSame('verified', $horse->fresh()->identity_status);
        $this->post('/contact', ['name' => 'A', 'email' => 'a@example.com', 'topic' => 'general', 'message' => 'Hello'])->assertRedirect();
    }

    public function test_a_horse_can_have_several_registrations(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/horses', [
            'registered_name' => 'Vinnie', 'birth_precision' => 'unknown', 'attest' => '1',
            'registrations' => [
                ['registry' => 'American Quarter Horse Association (AQHA)', 'number' => '5551111'],
                ['registry' => 'APHA', 'number' => '1234567'],
                ['registry' => 'American Ranch Horse Association (ARHA)', 'number' => 'R-998'],
                ['registry' => '', 'number' => ''],
            ],
        ])->assertRedirect();
        $horse = Horse::first();
        $this->assertEqualsCanonicalizing(['AQHA', 'APHA', 'ARHA'], $horse->identifiers()->where('kind', 'registration')->pluck('registry')->all());
        $this->actingAs($owner)->get('/horses/new')->assertOk()->assertSee('Add another registration');
    }

    public function test_breed_color_and_height_come_from_lists_with_an_other_option(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->get('/horses/new')->assertOk()->assertSee('Quarter Horse')->assertSee('Buckskin')->assertSee('15.3 hh');
        $this->actingAs($owner)->post('/horses', [
            'registered_name' => 'Vinnie', 'birth_precision' => 'unknown', 'attest' => '1',
            'breed_choice' => 'Quarter Horse', 'color_choice' => '__other', 'color_other' => 'Bay Roan Tobiano', 'height_hands' => '15.1',
        ])->assertRedirect();
        $horse = Horse::first();
        $this->assertSame(['Quarter Horse', 'Bay Roan Tobiano', '15.1'], [$horse->breed, $horse->color, (string) $horse->height_hands]);
        $this->actingAs($owner)->get("/horses/{$horse->horsefax_id}/edit")->assertOk()->assertSee('Bay Roan Tobiano');
        $this->actingAs($owner)->put("/horses/{$horse->horsefax_id}", ['registered_name' => 'Vinnie', 'birth_precision' => 'unknown', 'breed_choice' => 'Paint', 'color_choice' => 'Bay', 'height_hands' => '15.2'])->assertRedirect();
        $this->assertSame(['Paint', 'Bay'], [$horse->fresh()->breed, $horse->fresh()->color]);
    }
}
