<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowAppearance;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\DB;

/**
 * Washington State Quarter Horse Association results (wsqharesults.com, a Wix site; the PDFs are KDL Equestrian
 * Service "Results by Class Report" files). Approved by Richard, Oct 17 2026: robots.txt is Wix's allow-all, no terms
 * posted, no bot wall. Each result prints the horse's AQHA registration number and foal year, the strongest match
 * key any public results source gives. Stores horse, registration number, foal year, class and placing; never the
 * exhibitor, owner, their numbers or youth birthdates.
 */
class WsqhaHarvester
{
    use PoliteFetch;

    public const INDEX = ['https://www.wsqharesults.com/', 'https://www.wsqharesults.com/nwe-results', 'https://www.wsqharesults.com/finalsshow'];

    private DataSource $source;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'wsqha'], [
            'name' => 'Washington State Quarter Horse Association (results by KDL Equestrian Service)', 'url' => 'https://www.wsqharesults.com/', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 17 2026: Wix default robots.txt (allow all), no terms of use posted, no bot protection. Richard approved collecting (Oct 17 2026).',
        ]);
    }

    /** @return list<string> results PDFs linked from the results pages (index pages are read fresh each run) */
    public function files(): array
    {
        $files = [];
        foreach (self::INDEX as $index) {
            $body = $this->fetch($index)?->body() ?? '';
            preg_match_all('#https://www\.wsqharesults\.com/_files/ugd/[a-z0-9_]+\.pdf#i', $body, $m);
            foreach ($m[0] as $u) {
                $files[$u] = true;
            }
        }

        return array_keys($files);
    }

    /** Returns [files read, results stored]. */
    public function run(?int $maxFiles = null): array
    {
        $files = 0;
        $stored = 0;
        foreach ($this->files() as $url) {
            if ($maxFiles !== null && $files >= $maxFiles) {
                break;
            }
            if (in_array(HarvestPage::where('url', $url)->value('status'), ['parsed', 'skipped'], true)) {
                continue;
            }
            $pdf = $this->page($url, 'class', null, 'pdf');
            if ($pdf === null) {
                continue;
            }
            $stored += $this->import($this->harvestPage($url), KdlResultsParser::parse(StatePdfHarvester::text($pdf)));
            $files++;
        }

        return [$files, $stored];
    }

    private function import(HarvestPage $page, array $rows): int
    {
        if (! $rows) {
            $page->fill(['status' => 'skipped', 'error' => 'Not a results file, or no placed results read'])->save();

            return 0;
        }

        return DB::transaction(function () use ($page, $rows) {
            $n = 0;
            $touched = [];
            foreach ($rows as $row) {
                $name = NrchaHarvester::display($row['horse']);
                $key = ShowRecord::key((string) $name);
                if ($key === '' || ! $row['year']) {
                    continue;
                }
                $record = ShowRecord::firstOrCreate(['name_key' => $key], ['display_name' => $name]);
                $aqha = $row['organization'] === 'AQHA';
                $app = ShowAppearance::firstOrCreate(
                    [
                        'harvest_page_id' => $page->id, 'show_record_id' => $record->id, 'event_name' => $row['event'].' (show #'.$row['show_no'].')',
                        'class_code' => $row['class_code'], 'class_name' => $row['class_name'], 'round' => $row['round'], 'placing' => $row['placing'],
                    ],
                    [
                        'data_source_id' => $this->source->id, 'horse_name' => $name, 'event_year' => $row['year'], 'event_dates' => $row['dates'],
                        'organization' => $aqha ? 'AQHA' : $row['organization'], 'registry_inferred' => $aqha ? 'AQHA' : null,
                        'registration_number' => $row['registration_number'], 'foal_year' => $row['foal_year'], 'earnings_cents' => $row['earnings_cents'],
                        'source_url' => $page->url,
                    ]
                );
                if ($app->wasRecentlyCreated) {
                    $n++;
                }
                $touched[$record->id] = true;
            }
            foreach (array_keys($touched) as $id) {
                CongressHarvester::refresh($id);
            }
            $page->fill(['status' => 'parsed', 'rows' => count($rows), 'year' => $rows[0]['year']])->save();

            return $n;
        });
    }
}
