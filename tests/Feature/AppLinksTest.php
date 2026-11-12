<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Universal links: Apple's CDN fetches the association file and must get JSON straight back (no redirect, no login). */
class AppLinksTest extends TestCase
{
    public function test_apple_app_site_association_is_json_for_the_horsefax_app(): void
    {
        $response = $this->withHeader('User-Agent', 'AASA-Bot/1.0.0')->get('/.well-known/apple-app-site-association');

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        $details = $response->json('applinks.details.0');
        $this->assertSame(['622XZU3TAL.com.horsefax.app'], $details['appIDs']);
        $this->assertSame(['/' => '/horse/*'], end($details['components']));
        $this->assertTrue($details['components'][0]['exclude']);
    }
}
