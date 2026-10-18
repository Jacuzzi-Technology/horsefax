<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** As-you-type suggestions under the search box. */
class SearchSuggestTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_list_searchable_horses_prefix_matches_first(): void
    {
        $u = User::factory()->create();
        $make = function (string $name, bool $listed) use ($u) {
            $h = app(CreateHorse::class)($u, ['registered_name' => $name, 'birth_precision' => 'year', 'birth_year' => 2015, 'breed' => 'Quarter Horse']);
            $h->forceFill(['listed' => $listed])->save();

            return $h;
        };
        $make('Juniper Creek Lark', true);
        $make('Larkspur Gold', true);
        $make('Private Lark', false);
        \App\Models\PedigreeEntry::create(['horse_id' => Horse::where('registered_name', 'Larkspur Gold')->value('id'), 'position' => 's', 'name' => 'Sample Sire', 'visibility' => 'public', 'added_by' => $u->id]);

        \App\Models\ShowRecord::create(['name_key' => 'JUNIPERCREEKLARK', 'display_name' => 'Juniper Creek Lark', 'first_year' => 2005, 'last_year' => 2018,
            'horse_id' => Horse::where('registered_name', 'Juniper Creek Lark')->value('id')]);

        $r = $this->getJson(route('search.suggest', ['q' => 'lark']))->assertOk()->json();
        $this->assertSame(['Larkspur Gold', 'Juniper Creek Lark'], array_column($r, 'name'));
        $this->assertSame('2015 · Quarter Horse · by Sample Sire', $r[0]['detail']);
        $this->assertStringContainsString('/horse/HF-', $r[0]['url']);
        $this->assertSame('2015 · Quarter Horse · shown 2005–2018', $r[1]['detail']);
        $this->assertSame([], $this->getJson(route('search.suggest', ['q' => 'la']))->json());
        $this->assertSame([], $this->getJson(route('search.suggest', ['q' => '%_']))->json());
        $this->get('/')->assertSee('data-suggest', false);
    }
}
