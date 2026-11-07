<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API Équidés bulk file: parents and breeders for French (IFCE) records (fictional horses in the file's shape). */
class ApiEquidesTest extends TestCase
{
    use RefreshDatabase;

    private function ifce(string $name, ?int $year, string $sex): Horse
    {
        $h = new Horse(['registered_name' => $name, 'sex' => $sex, 'birth_year' => $year, 'birth_precision' => $year ? 'year' : 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = BuildRecordsFromResults::system()->id;
        $h->forceFill(['source' => 'ifce', 'identity_status' => 'unverified', 'listed' => true, 'public_summary' => true])->save();

        return $h;
    }

    private function line(string $id, string $nom, int $year, string $sexe, ?array $pere = null, ?array $mere = null, ?array $pdm = null, ?string $naisseur = null): string
    {
        return json_encode(['id' => $id, 'nom' => $nom, 'annee_naissance' => $year, 'sexe' => $sexe, 'race' => 'Quarter Horse', 'robe' => 'Bai',
            'pere' => $pere, 'mere' => $mere, 'pere_de_mere' => $pdm, 'naisseur' => $naisseur])."\n";
    }

    public function test_parents_are_linked_and_breeders_filled(): void
    {
        $sire = $this->ifce('Sample Hot Blu (Usa)', 2010, 'stallion');
        $dam = $this->ifce('Sample Shakira', 2012, 'mare');
        $foal = $this->ifce('Sample Aristo', 2020, 'stallion');
        $twinA = $this->ifce('Sample Twin', 2018, 'mare');
        $twinB = $this->ifce('Sample Twin', 2018, 'mare');   // two records share the key: neither is touched
        $kept = $this->ifce('Sample Already Bred', 2019, 'mare');
        $kept->forceFill(['breeder' => 'Sample Earlier Breeder'])->save();
        $owner = User::factory()->create();
        $owned = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned French', 'birth_precision' => 'unknown']);

        $file = tempnam(sys_get_temp_dir(), 'ae').'.jsonl';
        file_put_contents($file,
            $this->line('S1', 'SAMPLE HOT BLU (USA)', 2010, 'Male')
            .$this->line('D1', 'SAMPLE SHAKIRA', 2012, 'Femelle', null, null, null, 'Sample Haras')
            .$this->line('F1', 'SAMPLE ARISTO', 2020, 'Male', ['id' => 'S1', 'nom' => 'SAMPLE HOT BLU (USA)'], ['id' => 'D1', 'nom' => 'SAMPLE SHAKIRA'], ['id' => 'X9', 'nom' => 'SAMPLE SNAP'], 'Sample Élevage du Pré')
            .$this->line('T1', 'SAMPLE TWIN', 2018, 'Femelle', ['id' => 'S1', 'nom' => 'SAMPLE HOT BLU (USA)'], null, null, 'Sample Twin Breeder')
            .$this->line('K1', 'SAMPLE ALREADY BRED', 2019, 'Femelle', null, ['id' => 'NOFILE', 'nom' => 'SAMPLE LOST MARE'], ['id' => 'X8', 'nom' => 'SAMPLE OLD SIRE'], 'Sample New Breeder'));

        $this->artisan('hf:import-apiequides', ['file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, PedigreeEntry::count());

        $this->artisan('hf:import-apiequides', ['file' => $file])->assertSuccessful();
        // The foal: sire and dam linked to their records; no dam's sire line, since the dam's record carries it.
        $lines = PedigreeEntry::where('horse_id', $foal->id)->get()->keyBy('position');
        $this->assertSame(['d', 's'], $lines->keys()->sort()->values()->all());
        $this->assertSame([$sire->id, $dam->id], [$lines['s']->linked_horse_id, $lines['d']->linked_horse_id]);
        $this->assertSame(['Sample Hot Blu (USA)', 'historical', 'public'], [$lines['s']->name, $lines['s']->source_type, $lines['s']->visibility]);
        $this->assertSame('Sample Élevage du Pré', $foal->fresh()->breeder);
        $this->assertSame('Sample Haras', $dam->fresh()->breeder);
        // Dam without a record: her name and her sire stay as unlinked lines.
        $k = PedigreeEntry::where('horse_id', $kept->id)->pluck('linked_horse_id', 'position');
        $this->assertSame(['d' => null, 'ds' => null], $k->sortKeys()->all());
        $this->assertSame('Sample Earlier Breeder', $kept->fresh()->breeder);   // never overwritten
        // Ambiguous and owned records are untouched.
        $this->assertSame(0, PedigreeEntry::whereIn('horse_id', [$twinA->id, $twinB->id, $owned->id])->count());
        $this->assertNull($twinA->fresh()->breeder);

        // Shown on the record, with the chart built from the links.
        $this->actingAs(User::factory()->create())->get(route('horse.public', $foal))->assertOk()->assertSee('Sample Élevage du Pré')->assertSee('Sample Hot Blu (USA)');

        // Rerun: nothing added.
        $count = PedigreeEntry::count();
        $this->artisan('hf:import-apiequides', ['file' => $file])->assertSuccessful();
        $this->assertSame($count, PedigreeEntry::count());
        unlink($file);
    }

    public function test_new_horses_become_records_and_later_extractions_match_by_ifce_id(): void
    {
        $dam = $this->ifce('Sample French Mare', 2005, 'mare');
        $file = tempnam(sys_get_temp_dir(), 'ae').'.jsonl';
        $line = fn ($id, $nom, $y, $sexe, $race, $extra = []) => json_encode($extra + ['id' => $id, 'nom' => $nom, 'annee_naissance' => $y, 'sexe' => $sexe, 'race' => $race, 'robe' => 'Bai Fonce'])."\n";
        file_put_contents($file,
            $line('M1', 'SAMPLE FRENCH MARE', 2005, 'Femelle', 'Trotteur Francais')
            .$line('N1', 'SAMPLE NEW TROTTER', 2026, 'Male', 'Trotteur Francais', ['naisseur' => 'Sample Haras', 'mere' => ['id' => 'M1', 'nom' => 'SAMPLE FRENCH MARE']])
            .$line('A1', 'SAMPLE BAUDET', 2020, 'Male', 'Baudet Du Poitou')
            .$line('P1', 'SAMPLE MULASSIER', 2015, 'Femelle', 'Poitevin Mulassier'));

        $this->artisan('hf:import-apiequides', ['file' => $file])->expectsOutputToContain('"skipped_donkey_mule":1')->assertSuccessful();
        $new = Horse::where('registered_name', 'Sample New Trotter')->sole();
        $this->assertSame(['ifce', 'N1', 'French Trotter', 'Dark Bay', 'stallion', 2026, 'Sample Haras'],
            [$new->source, $new->ifce_id, $new->breed, $new->color, $new->sex, $new->birth_year, $new->breeder]);
        $this->assertSame($dam->id, PedigreeEntry::where('horse_id', $new->id)->where('position', 'd')->value('linked_horse_id'));
        $this->assertSame('M1', $dam->fresh()->ifce_id);
        $this->assertSame(1, Horse::where('registered_name', 'Sample Mulassier')->count());   // a horse breed, kept
        $this->assertSame(0, Horse::where('registered_name', 'Sample Baudet')->count());

        // Next extraction: the mare was renamed at IFCE; found by id, so no duplicate.
        file_put_contents($file, $line('M1', 'SAMPLE FRENCH MARE II', 2005, 'Femelle', 'Trotteur Francais').$line('N1', 'SAMPLE NEW TROTTER', 2026, 'Male', 'Trotteur Francais'));
        $count = Horse::count();
        $this->artisan('hf:import-apiequides', ['file' => $file])->expectsOutputToContain('"matched_by_id":2')->assertSuccessful();
        $this->assertSame($count, Horse::count());
        unlink($file);
    }
}
