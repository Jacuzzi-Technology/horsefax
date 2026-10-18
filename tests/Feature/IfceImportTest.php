<?php

namespace Tests\Feature;

use App\Harvest\IfceImporter;
use App\Models\Horse;
use App\Models\HorseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** French national registry open-data file (fictional rows in the published layout). */
class IfceImportTest extends TestCase
{
    use RefreshDatabase;

    private function file(array $rows): string
    {
        $f = tempnam(sys_get_temp_dir(), 'ifce');
        $lines = ["\xEF\xBB\xBF\"RACE\";\"SEXE\";\"ROBE\";\"DATE_DE_NAISSANCE\";\"PAYS_DE_NAISSANCE\";\"NOM\";\"CHE_COCONSO\";\"DATE_DE_DECES\""];
        foreach ($rows as $r) {
            $lines[] = '"'.implode('";"', $r).'"';
        }
        file_put_contents($f, implode("\n", $lines)."\n");

        return $f;
    }

    public function test_horses_import_once_with_english_labels_deaths_and_credit(): void
    {
        $rows = [
            ['QUARTER HORSE', 'H', 'ALEZAN', '14/03/2009', 'ETATS-UNIS', 'SAMPLE GUN IT', 'N', ''],
            ['KON. WARMB. NED', 'F', 'BAI FONCE', '27/04/1987', 'PAYS-BAS', 'SAMPLE FELIX', 'O', '20/10/2014'],
            ['OR. INCONNUE', 'H', 'BAI', '', '', 'SAMPLE GITAN', 'O', ''],
            ['OR. INCONNUE', 'H', 'BAI', '', '', 'SAMPLE GITAN', 'O', ''],      // a second, identical undated horse
            ['ANE NON CONST', 'F', 'GRIS', '', '', 'SAMPLE DONKEY', 'O', ''],
            ['PONEY', 'M', '', '', '', '', 'O', ''],
        ];
        $file = $this->file($rows);
        $out = (new IfceImporter)->run($file, 'https://example.test/ifce.csv', 2, 2);
        $this->assertSame(2, $out['created']); // stopped at the limit
        $out = (new IfceImporter)->run($file, 'https://example.test/ifce.csv');
        $this->assertSame([2, 1, 1, 0], [$out['created'], $out['skipped_donkey_mule'], $out['skipped_no_name'], $out['already_there']]);
        $this->assertSame(4, Horse::where('source', 'ifce')->count());
        $this->assertSame(2, Horse::where('registered_name', 'Sample Gitan')->count());
        $this->assertNull(Horse::where('registered_name', 'Sample Donkey')->first());

        $qh = Horse::where('registered_name', 'Sample Gun It')->sole();
        $this->assertSame(['Quarter Horse', 'gelding', 'Chestnut', 'United States', '2009-03-14'], [$qh->breed, $qh->sex, $qh->color, $qh->birth_country, $qh->birth_date->toDateString()]);
        $felix = Horse::where('registered_name', 'Sample Felix')->sole();
        $this->assertSame(['KWPN (Dutch Warmblood)', 'Dark Bay'], [$felix->breed, $felix->color]);
        $this->assertSame('2014-10-20', HorseEvent::where('horse_id', $felix->id)->where('type', 'death')->sole()->occurred_on->toDateString());

        $this->get(route('horse.public', $qh))->assertOk()->assertSee('French national equine database')->assertSee('Quarter Horse')->assertSee('United States');
        // Never matched by name alone.
        $this->assertSame(0, Horse::named('Sample Gun It')->count());

        // A new edition of the file adds only horses not already there.
        $again = (new IfceImporter)->run($this->file([...$rows, ['ARABE', 'F', 'GRIS', '01/05/2020', 'FRANCE', 'SAMPLE NEW FOAL', 'N', '']]), 'https://example.test/ifce-2027.csv');
        $this->assertSame([1, 4], [$again['created'], $again['already_there']]);
    }
}
