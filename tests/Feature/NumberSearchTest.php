<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The home search box takes a registration or microchip number as well as a name (fictional horse). */
class NumberSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_number_in_the_search_box_finds_the_horse_exactly(): void
    {
        $sys = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => 'Sample Numbered', 'birth_precision' => 'unknown']);
        $h->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $h->created_by = $sys->id;
        $h->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true])->save();
        foreach ([['registration', 'AQHA', '6337081'], ['microchip', null, '985112003456789']] as [$kind, $reg, $v]) {
            HorseIdentifier::create(['horse_id' => $h->id, 'kind' => $kind, 'registry' => $reg, 'value' => $v, 'value_normalized' => $v, 'visibility' => 'public', 'added_by' => $sys->id, 'source_type' => 'historical']);
        }
        $this->get(route('search', ['q' => '6337081']))->assertRedirect(route('horse.public', $h));
        $this->get(route('search', ['q' => '985 112 003 456 789']))->assertRedirect(route('horse.public', $h));
        $this->get(route('search', ['q' => '6337080']))->assertOk(); // no match: searched as a name, no redirect
        $this->get('/')->assertSee('Find a horse by name, registration number or microchip');
    }
}
