<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\SaleLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A record's classified ads show in its sale history: site, ad and date, never the asking price; linked only while live. */
class ListingSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ads_show_as_listed_for_sale_linked_while_live_and_text_once_gone(): void
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Peppy Chex', 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => 'published', 'listed' => true, 'public_summary' => true])->save();
        $src = DataSource::create(['key' => 'sample-ads', 'name' => 'SampleAds.com (classified ads)', 'url' => 'https://sampleads.example', 'permission' => 'terms_allow']);
        $live = SaleLot::record($src->id, '111', ['sale_name' => 'SampleAds classified ad', 'sale_date' => '2026-10-15', 'horse_name' => 'Sample Peppy Chex',
            'status' => 'asking', 'price_cents' => 1234500, 'buyers_premium' => 'none', 'source_url' => 'https://sampleads.example/ad/111.html']);
        $gone = SaleLot::record($src->id, '222', ['sale_name' => 'SampleAds classified ad', 'sale_date' => '2025-03-02', 'horse_name' => 'Sample Peppy Chex',
            'status' => 'asking', 'price_cents' => 999900, 'buyers_premium' => 'none', 'source_url' => 'https://sampleads.example/ad/222.html', 'listing_ended_on' => '2025-06-01']);
        SaleLot::whereIn('id', [$live->id, $gone->id])->update(['horse_id' => $h->id, 'match_tier' => 'created', 'review_status' => 'held']);

        // Signed out (what search engines see): no ads, no links.
        $this->get(route('horse.public', $h))->assertOk()->assertDontSee('sampleads.example/ad/111.html');

        $this->actingAs(User::factory()->create())->get(route('horse.public', $h))->assertOk()
            ->assertSee('Listed for sale')->assertSee('SampleAds.com ad #111')->assertSee('Oct 2026')
            ->assertSee('href="https://sampleads.example/ad/111.html"', false)->assertSee('rel="nofollow noopener"', false)
            ->assertSee('SampleAds.com ad #222 · no longer online')->assertDontSee('sampleads.example/ad/222.html')
            ->assertDontSee('12,345')->assertDontSee('9,999');

        // Staff audit log names the source instead of a bare lot id.
        \App\Support\Audit::record('identity.filled_from_published', $h, null, ['fields' => ['registration'], 'from' => 'sale lot '.$live->id], $h->id, $system->id);
        $staff = User::factory()->create(['is_staff' => true, 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->get(route('admin.horse', $h))->assertOk()->assertSee('From: SampleAds.com (classified ads)');
    }
}
