<?php

namespace App\Harvest;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseMention;
use App\Models\StallionService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Thoroughbred stallion-farm websites built on Horse Hosting (horsehosting.com), the platform behind sites like Ocala
 * Stud. Richard found them through FTBOA's "Farm website" buttons (Oct 27 2026) and asked for them built and loaded.
 * Every farm's stallion page uses one template: name, "Sire - Dam, by Damsire", fee ("Fee: $3,000 LFSN"), a 4-generation
 * pedigree table (each ancestor's country, color and foal year), a race-record table with totals, and the farm's news.
 *
 * Farms come from the "Farm website" links FTBOA publishes (stallion_services.website) plus any added to SITES. Before
 * collecting a farm, each is checked: robots.txt is read first and must allow /horses/; the site must be Horse Hosting
 * (the parser fits nothing else); and a site that links a terms page is held for a person to read it (status
 * "review"). Every check is recorded on the farm's harvest page. HorsefaxBot, at least 3 s apart.
 *
 * Loaded through ImportPublishedHorse (Thoroughbred, unverified, "(USA)" dropped from names): identity, the pedigree to
 * four generations, the race record (Achievements & earnings), the stallion service (farm, fee, website) and each news
 * story as an "In the news" link to the farm's article. Photos, descriptions and article text are not kept.
 */
class FarmSiteHarvester
{
    /** Horse Hosting farm sites to check besides those FTBOA links to. */
    public const SITES = ['https://www.ocalastud.com/'];

    private const COLORS = ['b' => 'Bay', 'br' => 'Brown', 'dkb/br' => 'Dark Bay/Brown', 'ch' => 'Chestnut', 'gr' => 'Gray', 'ro' => 'Roan', 'gr/ro' => 'Gray/Roan',
        'blk' => 'Black', 'bl' => 'Black', 'wh' => 'White', 'pal' => 'Palomino'];

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'farmsites'], [
            'name' => 'Thoroughbred farm websites (Horse Hosting)', 'url' => 'https://www.horsehosting.com', 'permission' => 'no_terms_posted',
            'terms_note' => 'Each farm checked before collecting: robots.txt must allow /horses/, Horse Hosting template, no terms page (a site with one is held for review). Richard approved Oct 27 2026.',
        ]);
    }

    /** @return list<string> farm home pages (scheme + host + /) */
    public function sites(): array
    {
        $from = StallionService::whereNotNull('website')->pluck('website')->all();
        $hosts = [];
        foreach (array_merge(self::SITES, $from) as $u) {
            if (($h = parse_url($u, PHP_URL_HOST)) && ! str_contains($h, 'ftboa.com') && ! str_contains($h, 'stallioncompare.com')) {
                $hosts[strtolower($h)] = (parse_url($u, PHP_URL_SCHEME) ?: 'https').'://'.strtolower($h).'/';
            }
        }

        return array_values($hosts);
    }

    /** Check each farm and fetch its stallion pages. Returns counts. */
    public function collect(?array $only = null): array
    {
        $out = ['sites' => 0, 'allowed' => 0, 'robots_blocked' => 0, 'not_horse_hosting' => 0, 'review' => 0, 'pages' => 0];
        foreach ($only ?? $this->sites() as $home) {
            $out['sites']++;
            $site = HarvestPage::firstOrNew(['url' => $home], ['data_source_id' => $this->source->id, 'kind' => 'farm_site']);
            if ($site->exists && in_array($site->status, ['review', 'skipped'], true)) {
                $out[$site->status === 'review' ? 'review' : 'not_horse_hosting']++;
                continue;
            }
            $robots = (string) $this->get(rtrim($home, '/').'/robots.txt', true);
            if (self::disallowed($robots, '/horses/x.html') || self::disallowed($robots, '/')) {
                $site->fill(['status' => 'skipped', 'error' => 'robots.txt disallows /horses/', 'fetched_at' => now()])->save();
                $out['robots_blocked']++;
                continue;
            }
            $html = (string) $this->get($home);
            if (! str_contains($html, 'horsehosting.com') && ! str_contains($html, '/Domains/')) {
                $site->fill(['status' => 'skipped', 'error' => 'Not a Horse Hosting site', 'fetched_at' => now()])->save();
                $out['not_horse_hosting']++;
                continue;
            }
            if (preg_match('#href="([^"]*(?:terms|conditions|legal)[^"]*)"#i', $html, $t)) {
                $site->fill(['status' => 'review', 'error' => 'Terms page to read before collecting: '.$t[1], 'fetched_at' => now()])->save();
                $out['review']++;
                continue;
            }
            $site->fill(['status' => 'parsed', 'error' => null, 'fetched_at' => now()])->save();
            $out['allowed']++;
            preg_match_all('#href="((?:https?://[^/"]+)?/horses/[a-z0-9-]+\.html)"#i', $html, $m);
            foreach (array_unique($m[1]) as $href) {
                $url = str_starts_with($href, 'http') ? $href : rtrim($home, '/').$href;
                if (parse_url($url, PHP_URL_HOST) !== parse_url($home, PHP_URL_HOST) || self::disallowed($robots, parse_url($url, PHP_URL_PATH))) {
                    continue;
                }
                $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'farm_stallion']);
                if ($page->exists && $page->path) {
                    continue;
                }
                if (($body = $this->get($url)) !== null) {
                    $file = 'harvest/farmsites/'.parse_url($home, PHP_URL_HOST).'/'.basename(parse_url($url, PHP_URL_PATH)).'.gz';
                    Storage::disk('local')->put($file, gzencode($body, 9));
                    $page->fill(['path' => $file, 'sha256' => hash('sha256', $body), 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();
                    $out['pages']++;
                }
            }
        }

        return $out;
    }

    /** Load stallion pages fetched since they were last loaded. Returns counts. */
    public function load(): array
    {
        $out = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'news' => 0, 'services' => 0];
        $system = BuildRecordsFromResults::system();
        $import = new ImportPublishedHorse;
        foreach (HarvestPage::where('data_source_id', $this->source->id)->where('kind', 'farm_stallion')->where('status', 'fetched')->whereNotNull('path')->get() as $page) {
            $p = self::stallion(gzdecode(Storage::disk('local')->get($page->path)), $page->url);
            if ($p) {
                $status = $import(['name' => $p['name'], 'registry' => null, 'registration_number' => null, 'foal_year' => $p['foal_year'], 'birth_date' => null,
                    'sex' => 'stallion', 'color' => $p['color'], 'breed' => 'Thoroughbred', 'pedigree' => $p['pedigree'], 'parentage_must_agree' => true], $page->url);
                $out[$status] = ($out[$status] ?? 0) + 1;
                if (($h = $import->last) && ! $h->activeStewards()->exists()) {
                    DB::transaction(fn () => $this->extras($h, $p, $page->url, $system, $out));
                }
            }
            $page->forceFill(['status' => $p ? 'parsed' : 'skipped'])->save();
            $out['pages']++;
        }

        return $out;
    }

    private function extras(Horse $horse, array $p, string $url, User $system, array &$out): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $farm = StallionService::where('horse_id', $horse->id)->whereNotNull('standing_at')->value('standing_at')
            ?? StallionService::where('website', 'like', '%'.$host.'%')->whereNotNull('standing_at')->value('standing_at');
        StallionService::updateOrCreate(['horse_id' => $horse->id, 'data_source_id' => $this->source->id], [
            'standing_at' => $farm, 'website' => 'https://'.$host.'/', 'fee_cents' => $p['fee_cents'], 'fee_text' => $p['fee_cents'] ? null : $p['fee_text'],
            'currency' => $p['fee_cents'] ? 'USD' : null, 'breeding' => $p['fee_terms'], 'source_url' => $url, 'observed_on' => now()->toDateString(),
        ]);
        $out['services']++;
        if ($p['record'] && ! HorseEvent::where('horse_id', $horse->id)->where('type', 'earnings')->where('details', 'like', '%'.$host.'%')->exists()) {
            HorseEvent::create(['horse_id' => $horse->id, 'category' => 'achievements', 'type' => 'earnings', 'title' => 'Race record', 'result' => $p['record'],
                'earnings_cents' => $p['earnings_cents'], 'occurred_on' => now()->toDateString(), 'date_precision' => 'day', 'details' => 'Published by the stallion\'s farm: '.$url,
                'contributor_id' => $system->id, 'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public']);
        }
        foreach ($p['news'] as $n) {
            $m = HorseMention::firstOrCreate(['horse_id' => $horse->id, 'url' => mb_substr($n['url'], 0, 500)],
                ['data_source_id' => $this->source->id, 'title' => mb_substr($n['title'], 0, 500), 'publisher' => $farm ?: $host, 'published_on' => $n['date']]);
            $out['news'] += $m->wasRecentlyCreated ? 1 : 0;
        }
    }

    /** A Horse Hosting stallion page. */
    public static function stallion(string $html, string $url = ''): ?array
    {
        $one = fn (string $re) => preg_match($re, $html, $m) ? trim(html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
        $name = $one('#<div class="StallionNameBig">(.*?)</div>#is');
        $line = $one('#<div class="Breedline">(.*?)</div>#is');
        if (! $name || ! $line || ! preg_match('/^(.+?)\s+-\s+(.+?),\s+by\s+(.+)$/u', $line, $b)) {
            return null;
        }
        $out = ['name' => $name, 'foal_year' => null, 'color' => null, 'pedigree' => ['s' => ['name' => trim($b[1])], 'd' => ['name' => trim($b[2])], 'ds' => ['name' => trim($b[3])]],
            'fee_cents' => null, 'fee_text' => null, 'fee_terms' => null, 'record' => null, 'earnings_cents' => null, 'news' => []];

        $fee = $one('#<span class="sexDetails">(.*?)</span>#is');
        if ($fee && preg_match('/Fee:\s*(.+)$/i', $fee, $f)) {
            if (preg_match('/\$\s*([\d,]+)\s*(.*)$/', $f[1], $fm)) {
                $out['fee_cents'] = (int) str_replace(',', '', $fm[1]) * 100;
                $terms = trim($fm[2], " \u{a0}");
                $out['fee_terms'] = $terms !== '' ? str_replace(['LFSN', 'S&N'], ['Live foal, stands and nurses', 'Stands and nurses'], $terms) : null;
            } else {
                $out['fee_text'] = mb_substr(trim($f[1]), 0, 255);
            }
        }

        // Pedigree table: cells in tree order; a cell's rowspan gives its generation (the first cell's rowspan is generation 1).
        if (preg_match('#<table class="pedigreetable[^"]*".*?</table>#is', $html, $tbl) && preg_match_all('#<td([^>]*)>(.*?)</td>#is', $tbl[0], $cells, PREG_SET_ORDER)) {
            $top = null;
            $last = [0 => ''];
            $kids = [];
            foreach ($cells as $c) {
                $rs = preg_match('/rowspan="(\d+)"/', $c[1], $r) ? (int) $r[1] : 1;
                $top ??= $rs;
                $g = (int) round(log($top / max(1, $rs), 2)) + 1;
                $parent = $last[$g - 1] ?? null;
                if ($parent === null) {
                    continue;
                }
                $pos = $parent.(($kids[$parent] = ($kids[$parent] ?? 0) + 1) === 1 ? 's' : 'd');
                $last[$g] = $pos;
                $last = array_slice($last, 0, $g + 1, true);
                $text = trim(html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags(str_ireplace('<br>', ' | ', $c[2]))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (! preg_match('/^(.+?)(?:\s*\(([A-Z]{2,3})\))?(?:\s*\|\s*(.*))?$/u', $text, $t)) {
                    continue;
                }
                $line = ['name' => self::name($t[1], $t[2] ?? null)];
                if (! empty($t[3]) && preg_match('#^([a-z/]+)\.?\s+(\d{4})#i', trim($t[3]), $cy)) {
                    $line['color'] = self::COLORS[strtolower($cy[1])] ?? null;
                    $line['foal_year'] = (int) $cy[2];
                }
                $out['pedigree'][$pos] = array_filter($line) + ($out['pedigree'][$pos] ?? []);
            }
        }

        // Race record totals: "Totals | 7 | 3 (1) | 3 (2) | 0 | $251,090" (stakes in brackets).
        if (preg_match('#<tr>\s*<td>\s*Totals?\s*</td>(.*?)</tr>#is', $html, $tot) && preg_match_all('#<td>(.*?)</td>#is', $tot[1], $tds)) {
            $v = array_map(fn ($x) => trim(strip_tags($x)), $tds[1]);
            if (count($v) >= 5) {
                $num = fn ($s) => (int) preg_replace('/\D.*$/', '', $s);
                $out['record'] = $num($v[0]).' starts, '.$num($v[1]).' wins, '.$num($v[2]).' seconds, '.$num($v[3]).' thirds, '.trim(preg_replace('/\s+/', '', $v[4]));
                $out['earnings_cents'] = preg_match('/\$\s*([\d,]+)/', $v[4], $e) ? (int) str_replace(',', '', $e[1]) * 100 : null;
            }
        }

        // News stories: title, link, date.
        $base = $url ? (parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST)) : '';
        preg_match_all('#<div class="newsBlockHeader">\s*<a href="([^"]+)"[^>]*>(.*?)</a>.*?<div class="catTitle">.*?\|\s*(\d{1,2}/\d{1,2}/\d{4})#is', $html, $news, PREG_SET_ORDER);
        foreach ($news as $n) {
            $title = trim(html_entity_decode(strip_tags($n[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') {
                $out['news'][] = ['title' => $title, 'url' => str_starts_with($n[1], 'http') ? $n[1] : $base.$n[1], 'date' => date('Y-m-d', strtotime($n[3]))];
            }
        }
        $out['news'] = array_values(array_column($out['news'], null, 'url'));
        $out['foal_year'] = null;

        return $out;
    }

    /** "INDIAN CHARLIE", "IRE" → "Indian Charlie (IRE)"; USA dropped. */
    public static function name(string $n, ?string $country): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $n));
        $n = $n === mb_strtoupper($n) ? NrchaHarvester::display($n) : $n;

        return $country && $country !== 'USA' ? $n.' ('.$country.')' : $n;
    }

    /** Whether robots.txt (User-agent: * or HorsefaxBot) disallows a path. */
    public static function disallowed(string $robots, string $path): bool
    {
        $applies = false;
        $rules = [];
        foreach (preg_split('/\R/', $robots) as $l) {
            $l = trim(preg_replace('/#.*/', '', $l));
            if (preg_match('/^User-agent:\s*(.+)$/i', $l, $m)) {
                $applies = in_array(strtolower(trim($m[1])), ['*', 'horsefaxbot'], true);
            } elseif ($applies && preg_match('/^Disallow:\s*(\S*)/i', $l, $m) && $m[1] !== '') {
                $rules[] = $m[1];
            }
        }
        foreach ($rules as $r) {
            if (str_starts_with(strtolower($path), strtolower($r))) {
                return true;
            }
        }

        return false;
    }

    private function get(string $url, bool $quiet404 = false): ?string
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(45)->get($url);
        } catch (\Throwable $e) {
            $this->say('failed '.$url.': '.$e->getMessage());

            return null;
        }
        $this->say($r->status().' '.$url);
        if (in_array($r->status(), [403, 429], true)) {
            return null; // that farm is skipped; others continue
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
