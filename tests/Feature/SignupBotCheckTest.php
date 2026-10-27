<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The sign-up bot check: honeypot, minimum time on the form, no links in names, no throwaway inboxes. */
class SignupBotCheckTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $over = []): array
    {
        return $over + ['name' => 'Sample Person', 'email' => 'person@example.com', 'password' => 'a-Long-password-123', 'password_confirmation' => 'a-Long-password-123',
            'terms' => '1', 'form_started' => encrypt(now()->subSeconds(10)->timestamp), 'website' => ''];
    }

    public function test_a_person_can_sign_up(): void
    {
        $this->get('/register')->assertOk()->assertSee('name="website"', false)->assertSee('name="form_started"', false);
        $this->post('/register', $this->form())->assertRedirect();
        $this->assertSame(1, User::where('email', 'person@example.com')->count());
    }

    public function test_bots_are_stopped(): void
    {
        $cases = [
            'honeypot filled' => ['website' => 'http://spam.example'],
            'submitted instantly' => ['form_started' => encrypt(now()->timestamp)],
            'no timer' => ['form_started' => ''],
            'link as name' => ['name' => '* * * <a href="http://advodocs.ru/go">$3,222 deposit available</a> * * *'],
            'throwaway inbox' => ['email' => 'bgg-671@fextemp.com'],
        ];
        foreach ($cases as $why => $over) {
            $this->post('/register', $this->form($over + ['email' => 'bot'.crc32($why).'@example.com']))->assertSessionHasErrors();
        }
        $this->post('/register', $this->form(['email' => 'bgg-671@fextemp.com']))->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
    }
}
