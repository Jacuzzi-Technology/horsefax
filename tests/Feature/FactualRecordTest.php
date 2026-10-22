<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Factual record policy: opinion entry types are retired and the policy is published (Oct 19 2026). */
class FactualRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_opinion_types_are_refused_and_the_policy_is_published(): void
    {
        $owner = User::factory()->create();
        $h = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Factual', 'birth_precision' => 'unknown']);
        $base = ['category' => 'training', 'title' => 'Sample', 'visibility' => 'private', 'date_precision' => 'unknown'];
        $this->actingAs($owner)->post(route('record.events', $h), $base + ['type' => 'temperament'])->assertSessionHasErrors('type');
        $this->actingAs($owner)->post(route('record.events', $h), $base + ['type' => 'evaluation'])->assertSessionHasErrors('type');
        $this->actingAs($owner)->post(route('record.events', $h), $base + ['type' => 'program'])->assertSessionHasNoErrors();
        $this->get(route('factual-record-policy'))->assertOk()->assertSee('to help you understand what can be documented');
        $this->get('/')->assertDontSee('temperament');
        $this->get(route('for-professionals'))->assertOk()->assertDontSee('dated opinion');
    }
}
