<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteProtectionTest extends TestCase
{
    public function test_clients_cannot_fake_their_ip_with_forwarded_headers(): void
    {
        Route::get('/_ip', fn () => request()->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-For', '198.51.100.1')
            ->get('/_ip')->assertSee('203.0.113.9');
    }

    public function test_cloudflare_may_pass_the_visitor_ip(): void
    {
        Route::get('/_ip', fn () => request()->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '172.64.1.1'])
            ->withHeader('X-Forwarded-For', '198.51.100.1')
            ->get('/_ip')->assertSee('198.51.100.1');
    }

    public function test_terms_forbid_scraping(): void
    {
        $this->get('/terms')->assertOk()->assertSee('data-mine')->assertSee('partner API');
    }
}
