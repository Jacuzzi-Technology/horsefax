<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\User;
use App\Support\Plans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Staff Members page and the plan groundwork (everyone Free; staff can set a plan by hand). */
class MembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_accounts_are_free_and_features_follow_the_plan(): void
    {
        $u = User::factory()->create();
        $this->assertSame(['free', 'Free'], [Plans::key($u->fresh()), Plans::name($u)]);
        $this->assertTrue($u->canUse('create_records'));
        $this->assertFalse($u->canUse('report_downloads'));
        $this->assertTrue(Gate::forUser($u)->allows('feature', 'share_reports'));
        $this->assertTrue(Plans::allows(null, 'search'));          // visitors get Free
        $this->assertFalse(Plans::allows(null, 'api_access'));

        $u->forceFill(['plan' => 'pro', 'plan_ends_at' => now()->addMonth()])->save();
        $this->assertTrue($u->canUse('report_downloads'));
        $u->forceFill(['plan_ends_at' => now()->subDay()])->save();
        $this->assertFalse($u->fresh()->canUse('report_downloads')); // an ended plan falls back to Free
    }

    public function test_staff_see_members_and_can_set_a_plan_but_members_cannot(): void
    {
        $member = User::factory()->create(['name' => 'Sample Member', 'email' => 'member@example.test']);
        app(CreateHorse::class)($member, ['registered_name' => 'Sample Members Horse', 'birth_precision' => 'unknown']);
        User::factory()->create(['name' => 'Horsefax Records', 'email' => 'records@system.horsefax.invalid']);
        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);

        $this->actingAs($member)->get(route('admin.members'))->assertNotFound(); // staff pages don't reveal themselves
        $this->actingAs($staff)->get(route('admin.members'))->assertOk()->assertSee('Sample Member')->assertSee('member@example.test')->assertDontSee('records@system');
        $this->actingAs($staff)->get(route('admin.member', $member))->assertOk()->assertSee('Sample Members Horse')->assertSee('Free');

        $this->actingAs($staff)->post(route('admin.member.plan', $member), ['plan' => 'pro', 'note' => 'Test comp'])->assertRedirect();
        $member->refresh();
        $this->assertSame(['pro', 'staff'], [$member->plan, $member->plan_source]);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('audit_log')->where('action', 'member.plan_changed')->exists());
        $this->assertContains($this->actingAs($member)->post(route('admin.member.plan', $member), ['plan' => 'business', 'note' => 'x'])->status(), [403, 404]);
        $this->assertSame('pro', $member->fresh()->plan);
    }

    public function test_sign_in_is_recorded(): void
    {
        $u = User::factory()->create(['password' => 'correct horse battery']);
        $this->post('/login', ['email' => $u->email, 'password' => 'correct horse battery']);
        $this->assertNotNull($u->fresh()->last_login_at);
    }

    /** Sign-up and sign-in addresses (Nov 10 2026): kept per account, shown to staff with other accounts on the same address. */
    public function test_sign_up_and_sign_in_addresses_are_kept_for_staff(): void
    {
        $form = ['name' => 'Sample Person', 'email' => 'person@example.com', 'password' => 'a-Long-password-123', 'password_confirmation' => 'a-Long-password-123',
            'terms' => '1', 'form_started' => encrypt(now()->subSeconds(10)->timestamp), 'website' => ''];
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->post('/register', $form)->assertRedirect();
        $a = User::where('email', 'person@example.com')->first();
        $this->assertSame(['203.0.113.5', '203.0.113.5'], [$a->signup_ip, $a->last_login_ip]);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('account_ips')->where('user_id', $a->id)->count()); // sign-up only, not its auto sign-in
        auth()->logout();

        $b = User::factory()->create(['email' => 'second@example.test', 'password' => 'correct horse battery']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->post('/login', ['email' => $b->email, 'password' => 'correct horse battery']);
        $this->assertSame('203.0.113.5', $b->fresh()->last_login_ip);
        $this->assertDatabaseHas('account_ips', ['user_id' => $b->id, 'ip' => '203.0.113.5', 'event' => 'login']);

        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->get(route('admin.member', $a))->assertOk()->assertSee('Sign-in addresses')
            ->assertSee('203.0.113.5 (sign-up)')->assertSee('second@example.test');
        $this->assertSame(1, \App\Support\AccountIps::sharing(collect([$a->id]))->count());

        \Illuminate\Support\Facades\DB::table('account_ips')->where('user_id', $a->id)->update(['created_at' => now()->subMonths(13)]);
        $this->assertSame(1, \App\Support\AccountIps::prune());
    }
}
