<?php

namespace Tests\Feature;

use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PedigreeTest extends TestCase
{
    use RefreshDatabase;

    private function horse(User $owner, string $name): Horse
    {
        return app(CreateHorse::class)($owner, ['registered_name' => $name, 'birth_precision' => 'unknown']);
    }

    private function savePedigree(User $owner, Horse $horse, array $lines, string $visibility = 'public')
    {
        return $this->actingAs($owner)->post("/horses/{$horse->horsefax_id}/pedigree", ['lines' => $lines, 'visibility' => $visibility]);
    }

    public function test_every_horse_in_a_pedigree_becomes_an_unverified_record_with_its_own_parents(): void
    {
        $owner = User::factory()->create();
        $vinnie = $this->horse($owner, 'Vinnie');
        $this->savePedigree($owner, $vinnie, [
            's' => ['name' => 'Wimpys High Bid', 'registry' => 'AQHA', 'registration_number' => '4400001', 'foal_year' => 2008],
            'd' => ['name' => 'Sheza Royal Horizon'],
            'ss' => ['name' => 'Wimpys Little Step'],
            'ds' => ['name' => 'Lenas Horizon'],
        ])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, '4 horses in the pedigree were new'));

        $sire = Horse::where('registered_name', 'Wimpys High Bid')->first();
        $this->assertSame(['pedigree', 'unverified', 'stallion', 2008, true], [$sire->source, $sire->identity_status, $sire->sex, $sire->birth_year, $sire->listed]);
        $this->assertFalse($sire->activeStewards()->exists());
        $this->assertSame('4400001', $sire->identifiers()->first()->value);
        $this->assertSame('Wimpys Little Step', $sire->pedigree()->where('position', 's')->value('name'));
        $this->assertSame('mare', Horse::where('registered_name', 'Sheza Royal Horizon')->value('sex'));
        $this->assertSame('Lenas Horizon', Horse::where('registered_name', 'Sheza Royal Horizon')->first()->pedigree()->where('position', 's')->value('name'));
        // Vinnie is now the sire's offspring.
        $this->assertTrue($sire->offspring()->where('horses.id', $vinnie->id)->exists());

        // Saving again does not duplicate anything.
        $this->savePedigree($owner, $vinnie, [
            's' => ['name' => 'Wimpys High Bid', 'registry' => 'AQHA', 'registration_number' => '4400001', 'foal_year' => 2008],
            'd' => ['name' => 'Sheza Royal Horizon'], 'ss' => ['name' => 'Wimpys Little Step'], 'ds' => ['name' => 'Lenas Horizon'],
        ]);
        $this->assertSame(5, Horse::count());
    }

    public function test_a_chart_never_edits_a_record_that_has_an_owner_and_private_charts_stay_unlisted(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sire = $this->horse($other, 'Owned Sire');
        $foal = $this->horse($owner, 'Foal');
        $this->savePedigree($owner, $foal, ['s' => ['name' => 'Owned Sire'], 'ss' => ['name' => 'Made Up Grandsire']], 'private');

        $this->assertSame($sire->id, $foal->pedigree()->where('position', 's')->value('linked_horse_id'));
        $this->assertSame(0, $sire->pedigree()->count());
        $this->assertFalse(Horse::where('registered_name', 'Made Up Grandsire')->value('listed'));
    }

    public function test_lookup_finds_records_and_pedigree_names_and_autofills_their_ancestors(): void
    {
        $owner = User::factory()->create();
        $a = $this->horse($owner, 'Horse A');
        $this->savePedigree($owner, $a, ['s' => ['name' => 'Smart Little Lena'], 'ss' => ['name' => 'Doc O Lena'], 'sd' => ['name' => 'Smart Peppy'], 'sss' => ['name' => 'Doc Bar']]);

        $user = User::factory()->create();
        $found = $this->actingAs($user)->getJson('/pedigree/lookup?pos=s&q=smart+li')->assertOk()->json('results');
        $this->assertSame('Smart Little Lena', $found[0]['name']);
        $this->assertSame('record', $found[0]['kind']);

        $anc = $this->actingAs($user)->getJson('/pedigree/ancestry?kind=record&id='.$found[0]['id'])->assertOk()->json('ancestors');
        $this->assertSame('Doc O Lena', $anc['s']['name']);
        $this->assertSame('Smart Peppy', $anc['d']['name']);
        $this->assertSame('Doc Bar', $anc['ss']['name']);

        // Private lines are never offered to anyone else.
        PedigreeEntry::where('name', 'Doc Bar')->update(['visibility' => 'private']);
        $anc = $this->actingAs($user)->getJson('/pedigree/ancestry?kind=record&id='.$found[0]['id'])->json('ancestors');
        $this->assertArrayNotHasKey('ss', $anc);
        auth()->logout();
        $this->getJson('/pedigree/lookup?pos=s&q=smart')->assertUnauthorized();
    }

    public function test_picking_a_record_links_it(): void
    {
        $owner = User::factory()->create();
        $sire = $this->horse(User::factory()->create(), 'Picked Sire');
        $foal = $this->horse($owner, 'Foal');
        $this->savePedigree($owner, $foal, ['s' => ['name' => 'Picked Sire', 'linked_horse_id' => $sire->id]]);
        $this->assertSame($sire->id, $foal->pedigree()->where('position', 's')->value('linked_horse_id'));
    }

    public function test_pedigrees_go_five_generations_with_a_tab_per_generation(): void
    {
        $owner = User::factory()->create();
        $foal = $this->horse($owner, 'Deep Foal');
        $this->savePedigree($owner, $foal, ['s' => ['name' => 'Gen One'], 'ss' => ['name' => 'Gen Two'], 'sss' => ['name' => 'Gen Three'], 'ssss' => ['name' => 'Gen Four'], 'sssss' => ['name' => 'Gen Five']])->assertRedirect();
        $this->assertSame('Gen Five', $foal->pedigree()->where('position', 'sssss')->value('name'));
        // The new record for the sire carries its own four generations behind it.
        $this->assertSame('Gen Five', Horse::where('registered_name', 'Gen One')->first()->pedigree()->where('position', 'ssss')->value('name'));

        $page = $this->actingAs($owner)->get("/horses/{$foal->horsefax_id}?tab=pedigree")->assertOk();
        foreach (['Sire &amp; dam', 'Grandparents', 'Great-grandparents', 'Great-great-grandparents', '3rd great-grandparents', 'Gen Five'] as $text) {
            $page->assertSee($text, false);
        }
        $foal->forceFill(['public_summary' => true])->save();
        auth()->logout();
        $this->get("/horse/{$foal->horsefax_id}")->assertOk()->assertSee('Gen Five')->assertSee('5 generations');
    }
}
