<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Horsefax phone app adds HorsefaxApp/ to its user agent: pages show a Back/Share bar instead of the site menu and footer. */
class AppModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_gets_back_and_share_bar_without_site_menu_or_footer(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 HorsefaxApp/1.0')->get('/')
            ->assertOk()
            ->assertSee('id="app-back"', false)
            ->assertSee('id="app-share"', false)
            ->assertSee('viewport-fit=cover', false)
            ->assertDontSee('Record corrections')
            ->assertDontSee('aria-label="Main"', false);
    }

    public function test_browsers_get_the_normal_site(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 Safari/604.1')->get('/')
            ->assertOk()
            ->assertDontSee('id="app-share"', false)
            ->assertSee('Record corrections')
            ->assertSee('aria-label="Main"', false);
    }
}
