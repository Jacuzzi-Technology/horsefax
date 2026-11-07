<?php

namespace App\Harvest;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\HorseEvent;
use App\Models\StallionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * FTBOA, the Florida Thoroughbred Breeders' and Owners' Association (ftboa.com). Checked Oct 27 2026: robots.txt
 * allows everything; only a privacy policy is posted (no terms, no scraping clause); no bot wall. Richard asked for it
 * built and loaded (Oct 27 2026).
 *
 *  - The Florida-bred / Florida-sired registry search loads one public file, FB_FSS_Lookup_LIVE.csv (about 25,000
 *    horses, no header): name, dam, sire, foal crop, sex, then five Y/N flags, mapped against the on-screen table:
 *    registered Florida-bred, sire is an FTBOA stallion, fully registered for Florida Sire incentives, yearling paid,
 *    2-year-old paid. Named horses with sire and dam become or fill records (Thoroughbred, unverified); unnamed foals
 *    can't. Florida-bred and Florida Sire registration are kept as program entries.
 *  - The stallion pages (/wp-json/wp/v2/stallion lists them): sire - dam, by damsire; farm; Florida foal crops; the
 *    "Farm Website" link, which FarmSiteHarvester follows.
 * HorsefaxBot, at least 3 s apart.
 */
class FtboaHarvester
{
    public const BASE = 'https://www.ftboa.com';
    public const CSV = self::BASE.'/wp-content/fb-fss-lookup-csv/FB_FSS_Lookup_LIVE.csv';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'ftboa'], [
            'name' => 'FTBOA – Florida Thoroughbred Breeders\' and Owners\' Association (ftboa.com)', 'url' => self::BASE.'/registrysearch', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 27 2026: robots.txt allows everything; only a privacy policy is posted, with no scraping or reuse clause; no bot wall. Richard approved building and loading it.',
        ]);
    }

    /** Download the registry file and the stallion pages (all small). Returns counts. */
    public function collect(): array
    {
        $out = ['registry' => false, 'stallions' => 0];
        $body = $this->get(self::CSV);
        if ($body !== null) {
            $this->keep(self::CSV, 'registry_csv', 'harvest/ftboa/FB_FSS_Lookup_'.now()->format('Ymd').'.csv.gz', $body);
            $out['registry'] = true;
        }
        $list = json_decode((string) $this->get(self::BASE.'/wp-json/wp/v2/stallion?per_page=100&_fields=id,slug,link'), true) ?: [];
        foreach ($list as $s) {
            if (! empty($s['link']) && ($html = $this->get($s['link'])) !== null) {
                $this->keep($s['link'], 'stallion', 'harvest/ftboa/stallion/'.$s['slug'].'.html.gz', $html);
                $out['stallions']++;
            }
        }

        return $out;
    }

    /** Load what was kept and not loaded yet. Returns counts. */
    public function load(): array
    {
        $out = ['rows' => 0, 'named' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'stallions' => 0, 'farm_links' => 0];
        $system = BuildRecordsFromResults::system();
        $import = new ImportPublishedHorse;
        foreach (HarvestPage::where('data_source_id', $this->source->id)->where('status', 'fetched')->whereNotNull('path')->orderBy('kind')->get() as $page) {
            $body = gzdecode(Storage::disk('local')->get($page->path));
            if ($page->kind === 'registry_csv') {
                foreach (self::rows($body) as $r) {
                    $out['rows']++;
                    if (! $r['name'] || ! $r['sire'] || ! $r['dam']) {
                        continue;
                    }
                    $out['named']++;
                    $status = $import(['name' => $r['name'], 'registry' => null, 'registration_number' => null, 'foal_year' => $r['crop'], 'birth_date' => null,
                        'sex' => $r['sex'], 'color' => null, 'breed' => 'Thoroughbred', 'pedigree' => ['s' => ['name' => $r['sire']], 'd' => ['name' => $r['dam']]],
                        'parentage_must_agree' => true], self::BASE.'/registrysearch');
                    $out[$status] = ($out[$status] ?? 0) + 1;
                    if (($h = $import->last) && ! $h->activeStewards()->exists()) {
                        foreach (array_filter(['Registered Florida-bred (FTBOA)' => $r['fl_bred'], 'Registered for Florida Sire Stakes incentives (FTBOA)' => $r['fl_sire']]) as $title => $yes) {
                            HorseEvent::firstOrCreate(['horse_id' => $h->id, 'type' => 'enrollment', 'title' => $title], [
                                'category' => 'achievements', 'occurred_on' => $r['crop'] ? $r['crop'].'-01-01' : null, 'date_precision' => $r['crop'] ? 'year' : 'unknown',
                                'details' => 'Florida-bred and Florida-sired registry, FTBOA: '.self::BASE.'/registrysearch', 'contributor_id' => $system->id,
                                'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public']);
                        }
                    }
                    if ($out['named'] % 1000 === 0) {
                        $this->say(json_encode($out));
                    }
                }
            } elseif ($page->kind === 'stallion' && ($s = self::stallion($body))) {
                $status = $import(['name' => $s['name'], 'registry' => null, 'registration_number' => null, 'foal_year' => null, 'birth_date' => null, 'sex' => 'stallion',
                    'color' => null, 'breed' => 'Thoroughbred', 'pedigree' => array_map(fn ($n) => ['name' => $n], array_filter(['s' => $s['sire'], 'd' => $s['dam'], 'ds' => $s['damsire']])),
                    'parentage_must_agree' => true], $page->url);
                $out[$status] = ($out[$status] ?? 0) + 1;
                $out['stallions']++;
                if (($h = $import->last) && ! $h->activeStewards()->exists() && ($s['farm'] || $s['website'])) {
                    $service = StallionService::firstOrNew(['horse_id' => $h->id, 'data_source_id' => $this->source->id]);
                    $service->fill(['standing_at' => $s['farm'], 'region' => 'FL', 'country' => 'United States', 'website' => $s['website'],
                        'breeding' => $s['crops'] ? 'Florida foal crops '.$s['crops'] : null, 'source_url' => $page->url, 'observed_on' => now()->toDateString()])->save();
                    $out['farm_links'] += $s['website'] ? 1 : 0;
                }
            }
            $page->forceFill(['status' => 'parsed'])->save();
        }

        return $out;
    }

    /** The registry file's rows (NUL bytes removed). */
    public static function rows(string $csv): \Generator
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, str_replace("\0", '', $csv));
        rewind($fh);
        $year = (int) now()->format('Y');
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($r) < 10) {
                continue;
            }
            $r = array_map(fn ($v) => trim((string) $v), $r);
            $crop = ctype_digit($r[3]) ? (int) $r[3] : null;
            $young = $crop && $year - $crop <= 3;
            $sex = match (strtolower($r[4])) {
                'filly', 'f' => $young ? 'filly' : 'mare',
                'mare', 'm' => 'mare',
                'colt', 'c' => $young ? 'colt' : null, // colts registered years ago may since have been gelded
                'gelding', 'g' => 'gelding',
                'horse', 'h', 'stallion' => 'stallion',
                default => null,
            };
            yield ['name' => $r[0] ?: null, 'dam' => $r[1] ?: null, 'sire' => $r[2] ?: null, 'crop' => $crop, 'sex' => $sex,
                'fl_bred' => $r[5] === 'Y', 'ftboa_sire' => $r[6] === 'Y', 'fl_sire' => $r[7] === 'Y'];
        }
        fclose($fh);
    }

    /**
     * An FTBOA stallion page: name (the heading after "Stallion Profile"), then one paragraph each: "Sire - ",
     * "Dam, ", "by Damsire", the farm, "Florida Foal Crops: 2013 - 2027"; and the "Farm Website" button.
     */
    public static function stallion(string $html): ?array
    {
        $at = strpos($html, 'Stallion Profile');
        if ($at === false || ! preg_match('#<h3[^>]*>(.*?)</h3>#is', $html, $h, 0, $at)) {
            return null;
        }
        $clean = fn ($v) => trim(html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($v)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $out = ['name' => $clean($h[1]), 'sire' => null, 'dam' => null, 'damsire' => null, 'farm' => null, 'crops' => null, 'website' => null];
        preg_match_all('#<p[^>]*>(.*?)</p>#is', substr($html, $at), $ps);
        foreach (array_map($clean, $ps[1]) as $p) {
            if ($p === '') {
                continue;
            }
            if (! $out['sire'] && preg_match('/^(.+?)\s*-$/u', $p, $m)) {
                $out['sire'] = $m[1];
            } elseif ($out['sire'] && ! $out['dam'] && preg_match('/^(.+?),$/u', $p, $m)) {
                $out['dam'] = $m[1];
            } elseif ($out['dam'] && ! $out['damsire'] && preg_match('/^by\s+(.+)$/iu', $p, $m)) {
                $out['damsire'] = $m[1];
            } elseif (preg_match('/^Florida Foal Crops:\s*(.+)$/iu', $p, $m)) {
                $out['crops'] = preg_replace('/\s+/', '', $m[1]);
                break;
            } elseif ($out['damsire'] && ! $out['farm']) {
                $out['farm'] = $p;
            }
        }
        if (preg_match('#<a[^>]+href="(https?://(?!www\.ftboa\.com)[^"]+)"[^>]*>(?:(?!</a>).)*Farm Website#is', $html, $w)) {
            $out['website'] = $w[1];
        }

        return $out['name'] !== '' && $out['sire'] && $out['dam'] ? $out : null;
    }

    private function keep(string $url, string $kind, string $file, string $body): void
    {
        $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => $kind]);
        $sha = hash('sha256', $body);
        if ($page->exists && $page->sha256 === $sha) {
            $page->fill(['fetched_at' => now()])->save();

            return;
        }
        Storage::disk('local')->put($file, gzencode($body, 9));
        $page->fill(['path' => $file, 'sha256' => $sha, 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();
    }

    private function get(string $url): ?string
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(90)->get($url);
        $this->say($r->status().' '.$url);
        if (in_array($r->status(), [403, 429], true)) {
            throw new HarvestBlocked('FTBOA answered '.$r->status().'; stopped.');
        }

        return $r->successful() ? $r->body() : null;
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
