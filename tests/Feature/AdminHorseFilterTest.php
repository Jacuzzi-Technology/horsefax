<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Staff Horses page: registry, breed, sex, color and source filters with a matching total (fictional horses). */
class AdminHorseFilterTest extends TestCase
{
    use RefreshDatabase;

    private function horse(string $name, array $cols, ?string $registry = null): Horse
    {
        $system = BuildRecordsFromResults::system();
        $h = new Horse(['registered_name' => $name, 'birth_precision' => 'unknown'] + $cols);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = $system->id;
        $h->forceFill(['source' => $cols['source'] ?? 'results'])->save();
        if ($registry) {
            HorseIdentifier::create(['horse_id' => $h->id, 'kind' => 'registration', 'registry' => $registry, 'value' => uniqid(), 'value_normalized' => strtoupper(uniqid()),
                'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical']);
        }

        return $h;
    }

    public function test_staff_filter_horses_and_see_the_total(): void
    {
        Cache::flush();
        $staff = User::factory()->create(['is_staff' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $this->horse('Sample Sorrel Mare', ['sex' => 'mare', 'color' => 'Sorrel', 'breed' => 'Quarter Horse'], 'AQHA');
        $this->horse('Sample Bay Gelding', ['sex' => 'gelding', 'color' => 'Bay', 'breed' => 'Quarter Horse'], 'AQHA');
        $this->horse('Sample Paint', ['sex' => 'mare', 'color' => 'Bay', 'breed' => 'Paint'], 'APHA');
        $this->horse('Sample Trotteur', ['sex' => 'mare', 'color' => 'Bay', 'breed' => 'French Trotter', 'source' => 'ifce']);

        $this->actingAs($staff)->get(route('admin.horses', ['registry' => 'AQHA']))->assertOk()
            ->assertSee('2 horses match')->assertSee('Sample Sorrel Mare')->assertDontSee('Sample Paint')->assertSee('AQHA (2)');
        $this->actingAs($staff)->get(route('admin.horses', ['registry' => 'AQHA', 'sex' => 'mare']))->assertSee('1 horse match')->assertSee('Sample Sorrel Mare');
        $this->actingAs($staff)->get(route('admin.horses', ['color' => 'Bay', 'source' => 'not_ifce']))->assertSee('2 horses match')->assertDontSee('Sample Trotteur');
        $this->actingAs($staff)->get(route('admin.horses'))->assertSee('4 horses in all');
    }
}
