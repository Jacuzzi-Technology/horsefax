<?php

namespace Tests\Feature;

use App\Harvest\AmShowsHarvester;
use App\Harvest\AmShowsParser;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A&M Show Services results PDFs (fictional horses and people). */
class AmShowsTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = <<<'TXT'
                                OVRHA AUG 2025 Results

                         Results for ROOKIE (5300) on 8-23-2025

    Place Entry  Score  Run Off  Payout        Horse/Owner(s)                          Rider/Affil

      1    326    70.5            43.13        SAMPLE SPARKLE N GOLD   (604001)        SAMPLE RIDER ONE    (502924)
                                               1) SAMPLE OWNER ONE   (502924)
      2    313    70.0            32.35        SAMPLE FAVORITE NITE   (606406)         SAMPLE RIDER TWO   (503809)
                                               1) SAMPLE OWNER TWO   (503809)
      7    318     0.0             0.00        SAMPLE PISTOLERO   (563189)             SAMPLE RIDER THREE   (101581)
                                               1) SAMPLE OWNER THREE   (104500)
    TXT;

    public function test_results_keep_the_horse_and_never_people(): void
    {
        $c = AmShowsParser::results(self::TEXT)[0];
        $this->assertSame(['OVRHA AUG 2025', 'Rookie', '5300', '2025-08-23'], [$c['event'], $c['class_name'], $c['class_code'], $c['date']]);
        $this->assertSame(['placing' => '1', 'horse' => 'Sample Sparkle N Gold', 'number' => '604001', 'score' => '70.5', 'payout_cents' => 4313], $c['rows'][0]);
        $this->assertSame([null, null], [$c['rows'][2]['score'], $c['rows'][2]['payout_cents']]);
        $this->assertCount(3, $c['rows']);
        $this->assertStringNotContainsString('OWNER', json_encode($c));
        $this->assertStringNotContainsString('RIDER', json_encode($c));
        $this->assertSame('OVRHA Aug 2025', AmShowsParser::eventName('OVRHA AUG 2025'));
    }

    public function test_an_nrha_result_gets_the_score_instead_of_a_second_copy(): void
    {
        $nrha = DataSource::firstOrCreate(['key' => 'nrha'], ['name' => 'NRHA', 'url' => 'https://nrha.com']);
        $nrhaPage = HarvestPage::create(['url' => 'https://nrha.com/sample', 'data_source_id' => $nrha->id, 'kind' => 'class', 'status' => 'parsed']);
        $rec = ShowRecord::create(['name_key' => ShowRecord::key('Sample Sparkle N Gold'), 'display_name' => 'Sample Sparkle N Gold']);
        ShowAppearance::create(['show_record_id' => $rec->id, 'data_source_id' => $nrha->id, 'harvest_page_id' => $nrhaPage->id, 'horse_name' => 'Sample Sparkle N Gold',
            'event_name' => 'OVRHA Affiliate Show #1', 'event_year' => 2025, 'event_dates' => 'Friday August 22, 2025 — Sunday August 24, 2025',
            'class_name' => 'Rookie', 'placing' => '1', 'earnings_cents' => 4313, 'organization' => 'NRHA', 'source_url' => 'https://nrha.com/sample']);

        $h = new AmShowsHarvester(0);
        $page = HarvestPage::create(['url' => 'https://drive.google.com/file/d/sample/view', 'data_source_id' => DataSource::where('key', 'amshows')->value('id'), 'kind' => 'class']);
        [$stored, $enriched] = $h->import($page, AmShowsParser::results(self::TEXT));

        $this->assertSame([2, 1], [$stored, $enriched]);
        $a = ShowAppearance::where('data_source_id', $nrha->id)->sole();
        $this->assertSame(['70.5', '604001', 'NRHA'], [$a->score, $a->competition_number, $a->competition_registry]);
        $new = ShowAppearance::where('horse_name', 'Sample Favorite Nite')->sole();
        $this->assertSame(['OVRHA Aug 2025', 'OVRHA', '2025-08-23', '606406', 3235], [$new->event_name, $new->organization, $new->event_dates, $new->competition_number, (int) $new->earnings_cents]);
    }

    public function test_a_google_block_pauses_the_harvest_for_a_day(): void
    {
        config(['horsefax.google_drive_key' => 'sample-key']);
        \Illuminate\Support\Facades\Http::fake(['showmypony.com/*' => \Illuminate\Support\Facades\Http::response('<a href="https://drive.google.com/drive/folders/SampleFolder123">Results</a>'),
            'www.googleapis.com/*' => \Illuminate\Support\Facades\Http::response('<html><title>Sorry...</title></html>', 403)]);
        try {
            (new AmShowsHarvester(0))->run();
            $this->fail('expected the run to stop');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sorry', $e->getMessage());
        }
        $this->assertTrue(now()->addHours(23)->lt(\Illuminate\Support\Carbon::parse(\Illuminate\Support\Facades\Cache::get(AmShowsHarvester::BLOCKED))));
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \Exception('no request should be made while paused'));
        $this->assertSame(0, (new AmShowsHarvester(0))->run()['folders']);
    }
}
