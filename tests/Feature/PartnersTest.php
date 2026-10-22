<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Partners page and its inquiry form (Oct 19 2026): inquiries land in the staff inbox with the organization's details. */
class PartnersTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_inquiry_reaches_the_staff_inbox(): void
    {
        $this->get(route('partners'))->assertOk()->assertSee('Help build a more complete history')->assertSee('No partner can buy favorable treatment');
        $this->get('/')->assertSee(route('partners'));

        $this->post(route('partners.send'), ['organization' => 'Sample Sale Co', 'name' => 'Sample Person', 'email' => 'sample@example.com',
            'interest' => 'nope', 'message' => 'Hi'])->assertSessionHasErrors('interest');
        $this->post(route('partners.send'), ['organization' => 'Sample Sale Co', 'name' => 'Sample Person', 'email' => 'sample@example.com',
            'website' => 'example.com', 'interest' => 'both', 'record_count' => 'about 5,000 a year', 'message' => 'We run two sales a year.'])
            ->assertRedirect(route('partners'));

        $m = ContactMessage::sole();
        $this->assertSame('partnership', $m->topic);
        $this->assertSame(['organization' => 'Sample Sale Co', 'website' => 'example.com', 'interest' => 'Both / integrated partnership', 'records' => 'about 5,000 a year'], $m->details);

        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->get(route('admin.messages'))->assertSee('Sample Sale Co')->assertSee('about 5,000 a year');
    }

    public function test_honeypot_stores_nothing(): void
    {
        $this->post(route('partners.send'), ['website_url' => 'spam', 'organization' => 'X', 'name' => 'X', 'email' => 'x@example.com', 'interest' => 'both', 'message' => 'x'])
            ->assertRedirect(route('partners'));
        $this->assertSame(0, ContactMessage::count());
    }
}
