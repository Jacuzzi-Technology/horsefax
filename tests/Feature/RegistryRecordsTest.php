<?php

namespace Tests\Feature;

use App\Actions\Record\AddIdentifier;
use App\Actions\Record\CreateHorse;
use App\Models\RegistryOwner;
use App\Models\RegistryRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** hf:import-registry-records: owner history and other registry details, recorded but never shown (fictional data). */
class RegistryRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function import(array $items): void
    {
        $file = tempnam(sys_get_temp_dir(), 'reg');
        file_put_contents($file, json_encode($items));
        $this->artisan('hf:import-registry-records', ['file' => $file])->assertSuccessful();
        unlink($file);
    }

    private function doc(string $reg, string $ref, array $owners, array $data = []): array
    {
        return ['registration_number' => $reg, 'registry' => 'AQHA', 'document_kind' => 'AQHA Records report', 'document_ref' => $ref,
            'document_date' => '2024-04-24', 'source' => 'Order_1.pdf', 'data' => $data, 'owners' => $owners, 'raw_text' => 'SAMPLE TEXT'];
    }

    public function test_owner_history_is_recorded_by_registration_number_and_never_shown(): void
    {
        $owner = User::factory()->create();
        $horse = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Smoking Cash', 'birth_precision' => 'unknown']);
        app(AddIdentifier::class)($horse, $owner, ['kind' => 'registration', 'registry' => 'AQHA', 'value' => '6000101', 'visibility' => 'public', 'source_type' => 'owner_reported']);
        $horse->forceFill(['listed' => true, 'public_summary' => true])->save();

        $owners = [['role' => 'current', 'name' => 'SAMPLE CURRENT OWNER', 'city' => 'Elizabeth', 'state' => 'AR', 'date_acquired' => '2022-01-20'],
            ['role' => 'previous', 'name' => 'SAMPLE PREVIOUS OWNER', 'city' => 'Marmaduke', 'state' => 'AR', 'date_acquired' => '2020-09-01']];
        $this->import([$this->doc('6000101', 'order:1', $owners, ['parentage_verified' => true, 'health_panel' => ['HYPP' => 'N/N']]),
            $this->doc('6999999', 'order:2', $owners)]);   // no horse with that number: skipped

        $rec = RegistryRecord::sole();
        $this->assertSame([$horse->id, true, ['HYPP' => 'N/N']], [$rec->horse_id, $rec->data['parentage_verified'], $rec->data['health_panel']]);
        $this->assertSame(['SAMPLE CURRENT OWNER', 'SAMPLE PREVIOUS OWNER'], RegistryOwner::orderByDesc('date_acquired')->pluck('name')->all());

        // Rerun: replaced, not duplicated.
        $this->import([$this->doc('6000101', 'order:1', $owners)]);
        $this->assertSame([1, 2], [RegistryRecord::count(), RegistryOwner::count()]);

        // Nowhere on the site: the public page, the owner's own record and its history page.
        foreach ([route('horse.public', $horse), route('horses.show', $horse), route('horses.audit', $horse)] as $url) {
            $this->actingAs($owner)->get($url)->assertOk()->assertDontSee('SAMPLE CURRENT OWNER')->assertDontSee('SAMPLE PREVIOUS OWNER')
                ->assertDontSee('Marmaduke')->assertDontSee('SAMPLE TEXT');
        }
    }
}
