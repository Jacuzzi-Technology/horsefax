<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\HarvestPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** hf:check-france: new or missed French editions are downloaded once and imported (fictional files). */
class CheckFranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_editions_are_imported_once(): void
    {
        $csv = "\"RACE\";\"SEXE\";\"ROBE\";\"DATE_DE_NAISSANCE\";\"PAYS_DE_NAISSANCE\";\"NOM\";\"CHE_COCONSO\";\"DATE_DE_DECES\"\n\"PUR SANG\";\"F\";\"BAI\";\"01/04/2001\";\"FRANCE\";\"SAMPLE CHECK MARE\";\"O\";\"\"\n";
        $jsonl = json_encode(['id' => 'C1', 'nom' => 'SAMPLE CHECK FOAL', 'annee_naissance' => 2026, 'sexe' => 'Male', 'race' => 'Pur Sang', 'robe' => 'Bai',
            'mere' => ['id' => 'X', 'nom' => 'SAMPLE CHECK MARE'], 'naisseur' => 'Sample Breeder'])."\n";
        $dir = storage_path('app/private/harvest');
        @unlink($dir.'/ifce/ifce-20270101.csv');
        @unlink($dir.'/apiequides/chevaux-20270102.jsonl');
        Http::fake([
            'www.data.gouv.fr/api/1/datasets/*' => Http::response(['resources' => [['format' => 'csv', 'url' => 'https://static.example.test/ifce-2027.csv', 'last_modified' => '2027-01-01T00:00:00', 'filesize' => strlen($csv)]]]),
            'static.example.test/*' => Http::response($csv),
            'api-equides.org/v1/extractions' => Http::response(['donnees' => [['ingere_le' => '2027-01-02', 'octets' => strlen($jsonl), 'servie' => true, 'telechargement' => '/v1/extractions/abc']]]),
            'api-equides.org/v1/extractions/abc' => Http::response($jsonl),
        ]);

        $this->artisan('hf:check-france', ['--dry-run' => true])->expectsOutputToContain('not imported')->assertSuccessful();
        $this->assertSame(0, Horse::count());

        $this->artisan('hf:check-france')->assertSuccessful();
        $this->assertSame(['Sample Check Foal', 'Sample Check Mare'], Horse::orderBy('registered_name')->pluck('registered_name')->all());
        $this->assertSame('Sample Breeder', Horse::where('registered_name', 'Sample Check Foal')->value('breeder'));
        $this->assertSame(2, HarvestPage::where('status', 'parsed')->count());

        Http::fake(['www.data.gouv.fr/*' => Http::response(['resources' => [['format' => 'csv', 'url' => 'https://static.example.test/ifce-2027.csv', 'last_modified' => '2027-01-01T00:00:00']]]),
            'api-equides.org/v1/extractions' => Http::response(['donnees' => [['ingere_le' => '2027-01-02', 'servie' => true, 'telechargement' => '/v1/extractions/abc']]]),
            'static.example.test/*' => fn () => throw new \RuntimeException('no download expected')]);
        $this->artisan('hf:check-france')->expectsOutputToContain('already imported')->assertSuccessful();
        @unlink($dir.'/ifce/ifce-20270101.csv');
        @unlink($dir.'/apiequides/chevaux-20270102.jsonl.zst');
    }
}
