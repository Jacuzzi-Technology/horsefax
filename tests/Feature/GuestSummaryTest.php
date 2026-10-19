<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\PedigreeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Signed-out visitors see a horse's summary and an invitation to a free account; signing up brings them back. */
class GuestSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_out_visitors_see_a_summary_and_sign_up_returns_them_to_the_full_record(): void
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Frankie', 'birth_precision' => 'year', 'birth_year' => now()->year - 4, 'color' => 'Buckskin', 'sex' => 'gelding', 'height_hands' => 15.0]);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true])->save();
        PedigreeEntry::create(['horse_id' => $h->id, 'position' => 's', 'name' => 'Sample Spooks', 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);
        HorseEvent::create(['horse_id' => $h->id, 'category' => 'competition', 'type' => 'show', 'title' => 'Sample Futurity Open', 'contributor_id' => $system->id,
            'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public']);

        $this->get(route('horse.public', $h))->assertOk()
            ->assertSee('Sample Frankie')->assertSee('4 years old')->assertSee('Buckskin')->assertSee('15 hands')->assertSee('Gelding')
            ->assertSee('Create a free account')->assertSee('1 show result')
            ->assertDontSee('Sample Spooks')->assertDontSee('Sample Futurity Open');
        $this->assertSame(route('horse.public', $h), session('url.intended'));

        $this->post(route('register'), ['name' => 'New Reader', 'email' => 'reader@example.com', 'password' => 'a-Long-password-123', 'password_confirmation' => 'a-Long-password-123', 'terms' => '1'])
            ->assertRedirect(route('horse.public', $h));
        $this->get(route('horse.public', $h))->assertSee('Sample Spooks')->assertSee('Sample Futurity Open');
    }
}
