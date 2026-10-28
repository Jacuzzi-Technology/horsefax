<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\User;
use App\Support\SearchBots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Record-page limits (Oct 28 2026): per minute and per day; verified search crawlers and staff exempt. */
class RecordLimitTest extends TestCase
{
    use RefreshDatabase;

    private function horse(): Horse
    {
        $h = app(CreateHorse::class)(User::factory()->create(), ['registered_name' => 'Sample Limit Horse', 'birth_precision' => 'unknown']);
        $h->forceFill(['listed' => true, 'public_summary' => true])->save();

        return $h;
    }

    protected function tearDown(): void
    {
        SearchBots::$resolver = null;
        parent::tearDown();
    }

    public function test_guests_and_members_hit_their_daily_cap(): void
    {
        Cache::flush();
        config(['plans.guest_record_views_per_day' => 3, 'plans.plans.free.limits.record_views_per_day' => 4]);
        $url = route('horse.public', $this->horse());
        foreach (range(1, 3) as $i) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertStatus(429)->assertSee('today\'s limit')->assertSee('Create a free account');

        $member = User::factory()->create();
        foreach (range(1, 4) as $i) {
            $this->actingAs($member)->get($url)->assertOk();
        }
        $this->actingAs($member)->get($url)->assertStatus(429)->assertSee('your plan');

        $staff = User::factory()->create(['is_staff' => true]);
        foreach (range(1, 6) as $i) {
            $this->actingAs($staff)->get($url)->assertOk();
        }
    }

    public function test_only_verified_crawlers_are_exempt(): void
    {
        Cache::flush();
        config(['plans.guest_record_views_per_day' => 2]);
        $url = route('horse.public', $this->horse());
        SearchBots::$resolver = fn (string $ip) => $ip === '66.249.66.1'
            ? ['host' => 'crawl-66-249-66-1.googlebot.com', 'ips' => ['66.249.66.1']]
            : ['host' => 'scanner.example.net', 'ips' => [$ip]];
        $google = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];

        foreach (range(1, 5) as $i) {
            $this->withServerVariables($google + ['REMOTE_ADDR' => '66.249.66.1'])->get($url)->assertOk();
        }
        // A scraper borrowing Googlebot's name from another address is limited like anyone else.
        $fake = $this->withServerVariables($google + ['REMOTE_ADDR' => '203.0.113.9']);
        $fake->get($url)->assertOk();
        $fake->get($url)->assertOk();
        $fake->get($url)->assertStatus(429);
    }

    public function test_bot_trap_pauses_scrapers_but_not_search_engines_or_members(): void
    {
        Cache::flush();
        $url = route('horse.public', $this->horse());
        $this->get('/')->assertSee('href="/x/records-index"', false);
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/robots.txt')->assertSee('Disallow: /x/');
        $this->app->detectEnvironment(fn () => 'testing');

        $scraper = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'python-requests/2.32']);
        $scraper->get($url)->assertOk();
        $scraper->get('/x/records-index')->assertNotFound();
        $scraper->get($url)->assertStatus(403)->assertSee('Access paused');
        $scraper->get(route('search', ['q' => 'Sample']))->assertStatus(403);
        // Another address is unaffected; a member on the trapped address is too.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get($url)->assertOk();

        // Verified Google is never trapped.
        SearchBots::$resolver = fn (string $ip) => ['host' => 'crawl-66-249-66-2.googlebot.com', 'ips' => [$ip]];
        $google = $this->withServerVariables(['REMOTE_ADDR' => '66.249.66.2', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);
        $google->get('/x/records-index');
        $google->get($url)->assertOk();
    }
}
