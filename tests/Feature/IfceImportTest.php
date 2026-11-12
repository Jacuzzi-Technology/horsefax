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

        $this->actingAs($this->reader())->get(route('horse.public', $qh))->assertOk()->assertSee('French national equine database')->assertSee('Quarter Horse')->assertSee('United States');
        // Never matched by name alone.
        $this->assertSame(0, Horse::named('Sample Gun It')->count());

        // A new edition of the file adds only horses not already there.
        $again = (new IfceImporter)->run($this->file([...$rows, ['ARABE', 'F', 'GRIS', '01/05/2020', 'FRANCE', 'SAMPLE NEW FOAL', 'N', '']]), 'https://example.test/ifce-2027.csv');
        $this->assertSame([1, 4], [$again['created'], $again['already_there']]);
    }

    public function test_older_editions_in_other_layouts_add_only_missing_horses(): void
    {
        (new IfceImporter)->run($this->file([['QUARTER HORSE', 'H', 'ALEZAN', '14/03/2009', 'ETATS-UNIS', 'SAMPLE GUN IT', 'N', '']]), 'https://example.test/2026.csv');

        // 2014 style: no header, Windows-1252, trailing spaces; the same horse with an older color, plus one dropped later.
        $f = tempnam(sys_get_temp_dir(), 'ifce');
        file_put_contents($f, mb_convert_encoding(implode("\n", [
            '"QUARTER HORSE";"H";"BAI";"14/03/2009";"ETATS-UNIS";"SAMPLE GUN IT";"";""        ',
            '"SELLE FRANCAIS";"F";"GRIS";"02/05/1990";"FRANCE";"SAMPLE ÉTOILE";"";"01/01/2010"        ',
        ])."\n", 'Windows-1252', 'UTF-8'));
        $out = (new IfceImporter)->run($f, 'https://example.test/2014.csv', loose: true);
        $this->assertSame([1, 1, 1], [$out['created'], $out['already_there'], $out['deaths']]);
        $this->assertEqualsCanonicalizing(['Sample Gun It', 'Sample Étoile'], Horse::pluck('registered_name')->all());

        // 2018 style: commas, header without quotes.
        $g = tempnam(sys_get_temp_dir(), 'ifce');
        file_put_contents($g, "RACE,SEXE,ROBE,DATE_DE_NAISSANCE,PAYS_DE_NAISSANCE,NOM,CHE_COCONSO,DATE_DE_DECES\nPUR SANG,M,BAI,01/04/2001,FRANCE,SAMPLE COMMA,O,\n");
        $this->assertSame(1, (new IfceImporter)->run($g, 'https://example.test/2018.csv', loose: true)['created']);
        $this->assertSame('Thoroughbred', Horse::where('registered_name', 'Sample Comma')->value('breed'));
        // A record from API Équidés (foal year only) is the same horse as a dated row of a later edition.
        (new Horse(['registered_name' => 'Sample Year Only', 'sex' => 'mare', 'birth_year' => 2024, 'birth_precision' => 'year']))
            ->forceFill(['horsefax_id' => \App\Actions\Record\CreateHorse::newId(), 'created_by' => \App\Actions\Record\BuildRecordsFromResults::system()->id, 'source' => 'ifce'])->save();
        $k = tempnam(sys_get_temp_dir(), 'ifce');
        file_put_contents($k, "RACE;SEXE;ROBE;DATE_DE_NAISSANCE;PAYS_DE_NAISSANCE;NOM;CHE_COCONSO;DATE_DE_DECES\nPUR SANG;F;BAI;03/03/2024;FRANCE;SAMPLE YEAR ONLY;O;\n");
        $this->assertSame(1, (new IfceImporter)->run($k, 'https://example.test/2027.csv', loose: true)['already_there']);
        array_map('unlink', [$f, $g, $k]);
    }
}
