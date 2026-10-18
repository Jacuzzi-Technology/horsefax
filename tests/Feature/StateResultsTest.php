<?php

namespace Tests\Feature;

use App\Harvest\StatePdfHarvester;
use App\Harvest\StatePdfParser;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** State QHA results PDFs (fictional text in the two published layouts). */
class StateResultsTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = <<<'TXT'
Show #10001 (SAMPLE JUDGE): SAMPLE SPRING SHOW (4/9/2026-4/12/2026)
                                18. AQHA 110949: L1 Ranch Trail Go: 1 - Shown: 3
PlaceBack# Horse's Name                  Exhibitor's Name                           Owner's Name
   1   824   SAMPLE LOVIN HEART          SAMPLE RIDER ONE                           SAMPLE OWNER
   2   845   SAMPLESPANGLEDWHIZZZRider Two Name                                     SAMPLE OWNER
  DQ   846   SAMPLE DISQUALIFIED         SAMPLE RIDER THREE                         SAMPLE OWNER
                             2. KQHA 14: SMALL FRY RANCH TRAIL Go: 1 - Shown: 1
PlaceBack# Horse's Name                  Exhibitor's Name                           Owner's Name
   1   932   SAMPLE LOVIN HEART          SAMPLE CHILD                               SAMPLE OWNER
                             3. KQHA 15: SMALL FRY RANCH TRAIL Go: 1 - Shown: 1
PlaceBack# Horse's Name                  Exhibitor's Name                           Owner's Name
   1   932   SAMPLE LOVIN HEART          SAMPLE CHILD TWO                           SAMPLE OWNER
TXT;

    private const STANDINGS = <<<'TXT'
                          Sample Quarter Horse Association
                                      Member Standings
105400   YEARLING MARES                                                                      #Judges       Pts
     SAMPLE SECRET DIVA                                SAMPLE MEMBER                             2         5
                                                                NO. SHN.   PLACE   GRAND RESERVE POINTS
          90745   Sample Spring Show - Saturday                       1       1      Yes    No        4
          90746   Sample Spring Show - Sunday                         3       2      No     No         1

Tuesday, September 22, 2026                                                                      Page 1 of 2
     SAMPLE NO MEMBER                                                                            1           0
                                                                NO. SHN.   PLACE   GRAND RESERVE POINTS
          90746   Sample Spring Show - Sunday                         3       3      No     No         0
TXT;

    public function test_publication_report_keeps_horse_and_placing_only(): void
    {
        $rows = StatePdfParser::publicationReport(self::REPORT);
        $this->assertCount(4, $rows);
        $this->assertSame(['10001', 'SAMPLE SPRING SHOW', 2026, 'AQHA', '110949', 'L1 Ranch Trail', '1', 'SAMPLE LOVIN HEART'],
            [$rows[0]['show_no'], $rows[0]['event'], $rows[0]['year'], $rows[0]['organization'], $rows[0]['class_code'], $rows[0]['class_name'], $rows[0]['placing'], $rows[0]['horse']]);
        $this->assertSame('SAMPLESPANGLEDWHIZZZ', $rows[1]['horse']); // 20-character name run into the rider's name
        $this->assertSame('KQHA', $rows[2]['organization']);
        $this->assertStringNotContainsString('RIDER', json_encode($rows));
        $this->assertStringNotContainsString('OWNER', json_encode($rows));
    }

    public function test_same_class_name_under_two_class_numbers_is_two_results(): void
    {
        Storage::fake('local');
        Http::fake([
            'www.nebraskaquarterhorseassociation.com/2026-results' => Http::response('<a href="/s/1PublicationRpt.pdf">1</a>'),
            'www.nebraskaquarterhorseassociation.com/results-archive' => Http::response(''),
            'www.nebraskaquarterhorseassociation.com/s/*' => Http::response('%PDF-sample'),
        ]);
        Process::fake(['*' => Process::result(self::REPORT)]);
        $this->assertSame([1, 4], (new StatePdfHarvester('nqha', 0))->run());
        $this->assertSame(['14', '15'], ShowAppearance::where('organization', 'KQHA')->orderBy('class_code')->pluck('class_code')->all());
        $this->assertSame(0, ShowAppearance::where('organization', 'KQHA')->whereNotNull('registry_inferred')->count());
    }

    public function test_member_standings_reads_each_show_placing(): void
    {
        $rows = StatePdfParser::memberStandings(self::STANDINGS);
        $this->assertSame([['SAMPLE SECRET DIVA', '90745', '1'], ['SAMPLE SECRET DIVA', '90746', '2'], ['SAMPLE NO MEMBER', '90746', '3']],
            array_map(fn ($r) => [$r['horse'], $r['show_no'], $r['placing']], $rows));
        $this->assertArrayNotHasKey('member', $rows[0]);
        $this->assertSame(['105400', 'Yearling Mares', 2026], [$rows[0]['class_code'], $rows[0]['class_name'], $rows[0]['year']]);
    }

    public function test_harvest_stores_results_once_across_files(): void
    {
        Storage::fake('local');
        Http::fake([
            'www.moqha.com/' => Http::response('<a href="/s/Open-Standings.pdf">Open</a><a href="/s/Youth-Standings.pdf">Y</a><a href="/s/Directory.pdf">D</a>'),
            'www.moqha.com/s/*' => Http::response('%PDF-sample'),
        ]);
        Process::fake(['*' => Process::result(self::STANDINGS)]);

        [$files, $stored] = (new StatePdfHarvester('moqha', 0))->run();
        $this->assertSame([2, 3], [$files, $stored]); // the second file repeats the same placings
        $diva = ShowRecord::where('name_key', 'SAMPLESECRETDIVA')->first();
        $this->assertSame(['Sample Secret Diva', 'AQHA', 2], [$diva->display_name, $diva->registry_inferred, $diva->appearances]);
        $this->assertSame('Sample Spring Show - Saturday (show #90745)', ShowAppearance::where('show_record_id', $diva->id)->orderBy('placing')->first()->event_name);
        $this->assertSame(2, HarvestPage::where('status', 'parsed')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Directory'));

        Http::fake(['*' => Http::response('<a href="/s/Open-Standings.pdf">Open</a>')]);
        $this->assertSame([0, 0], (new StatePdfHarvester('moqha', 0))->run()); // nothing refetched
    }
}
