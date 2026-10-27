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
 * StallionCompare (stallioncompare.com, Kingston ON): about 400 stock-horse stallions with 4-generation pedigrees,
 * genetics, stud fees, the farm they stand at, lifetime earnings, highlights, program nominations, outstanding progeny
 * and article mentions, plus a page for every linked horse (ancestors, progeny, retired sires). Checked Oct 27 2026:
 * robots.txt blocks only admin/private paths; the terms (Ontario law) have no scraping, database or reuse clause; the
 * copyright page claims no copyright on stallion listing content; no bot wall. Richard asked for it to be built and
 * loaded the same day, with highlights, genetic testing, breeding fee, standing location, earnings and article mentions.
 *
 * Crawl: the listing pages (sitemap.xml is broken; only results after the filter panel count, the featured carousel
 * rotates), every stallion page, then each horse page those link to (pedigree
 * and progeny): one level deep, so it doesn't wander through every ancestor. One request at a time, at least 3 s
 * apart, as HorsefaxBot; a 403/429 stops the run. Pages are kept gzipped; a page is refetched after 25 days with
 * --refresh and reloaded only when it changed.
 *
 * Records go through ImportPublishedHorse (source "published", unverified; same-named records with different parents
 * stay apart). Extras: height, death year ("In loving memory … 1997 – 2019"), genetic test (Health, public: the owner
 * publishes it), lifetime earnings, highlights and nominations (Achievements & earnings), the stallion service (fee,
 * chute fee, breeding terms, farm, town, website, and the breeding contacts: farm and breeding-manager phone and email)
 * and article mentions (title and link only). Progeny with sire and dam and a name of two or more words become records
 * too. Never stored: the owner card (names and contacts), street addresses, photos and article text. A record someone owns is left alone.
 */
class StallionCompareHarvester
{
    public const BASE = StallionCompareParser::BASE;

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'stallioncompare'], [
            'name' => 'StallionCompare (stallioncompare.com)', 'url' => self::BASE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 27 2026: robots.txt blocks only admin/private paths; terms have no scraping, database or reuse clause; the copyright page claims no copyright on stallion listing content; no bot wall. Richard approved building and loading it.',
        ]);
    }

    /** Fetch listing pages, stallion pages, then the horse pages they link to. Returns counts. */
    public function collect(bool $refresh = false, ?int $maxPages = null): array
    {
        $out = ['requests' => 0, 'stallions' => 0, 'horses' => 0, 'changed' => 0];
        $slugs = [];
        for ($n = 1; $n <= 200; $n++) {
            $html = (string) $this->keep(self::BASE.'/listing/'.$n.'/name_az'.str_repeat('/0', 31), 'listing', true, $out);
            // The results (25 a page) follow the filter panel; the banner and "Featured" carousel above it rotate.
            $grid = ($at = strpos($html, 'Refine Search')) !== false ? substr($html, $at) : $html;
            preg_match_all('#href="/stallion/([a-z0-9-]+)"#', $grid, $m);
            $new = array_diff(array_unique($m[1]), $slugs);
            if (! $new) {
                break;
            }
            array_push($slugs, ...$new);
        }
        $links = [];
        foreach (array_values(array_unique($slugs)) as $slug) {
            if ($maxPages !== null && $out['requests'] >= $maxPages) {
                break;
            }
            $html = $this->keep(self::BASE.'/stallion/'.$slug, 'stallion', $refresh, $out);
            $out['stallions']++;
            if ($html && ($p = StallionCompareParser::page($html))) {
                array_push($links, ...$p['links']);
            }
        }
        foreach (array_values(array_unique($links)) as $path) {
            if ($maxPages !== null && $out['requests'] >= $maxPages) {
                break;
            }
            if (str_starts_with($path, '/stallion/') && in_array(substr($path, 10), $slugs, true)) {
                continue;
            }
            $this->keep(self::BASE.$path, str_starts_with($path, '/stallion/') ? 'stallion' : 'horse', $refresh, $out);
            $out['horses']++;
        }

        return $out;
    }

    /** Load pages fetched since they were last loaded (or all with $all). Stallion pages first. Returns counts. */
    public function load(bool $all = false): array
    {
        $out = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'progeny' => 0, 'services' => 0, 'mentions' => 0, 'genetics' => 0,
            'titles' => 0, 'earnings' => 0, 'deaths' => 0];
        $system = BuildRecordsFromResults::system();
        $import = new ImportPublishedHorse;
        HarvestPage::where('data_source_id', $this->source->id)->whereIn('kind', ['stallion', 'horse'])->whereNotNull('path')
            ->when(! $all, fn ($q) => $q->where('status', 'fetched'))->orderByRaw("kind = 'horse'")->orderBy('url')
            ->each(function (HarvestPage $page) use ($system, $import, &$out) {
                $p = StallionCompareParser::page(gzdecode(Storage::disk('local')->get($page->path)));
                if ($p) {
                    $this->loadPage($p, $page->url, $system, $import, $out);
                }
                $page->forceFill(['status' => 'parsed'])->save();
                if (++$out['pages'] % 50 === 0) {
                    $this->say(json_encode($out));
                }
            });

        return $out;
    }

    private function loadPage(array $p, string $url, User $system, ImportPublishedHorse $import, array &$out): void
    {
        $status = $import(self::input($p), $url);
        $out[$status] = ($out[$status] ?? 0) + 1;
        $horse = $import->last;
        if ($horse && ! $horse->activeStewards()->exists()) {
            DB::transaction(fn () => $this->extras($horse, $p, $url, $system, $out));
        }
        foreach ($p['progeny'] as $k) {
            $words = count(preg_split('/\s+/', trim($k['name'])));
            if (! $k['sire'] || ! $k['dam'] || $words < 2) {
                continue; // registered names only: sire and dam named, two or more words
            }
            $sire = ['name' => $k['sire']] + (\App\Models\ShowRecord::key($k['sire']) === \App\Models\ShowRecord::key($p['name']) ? array_filter(['foal_year' => $p['foal_year']]) : []);
            $s = $import(['name' => $k['name'], 'registry' => $p['registry'], 'registration_number' => null, 'foal_year' => $k['foal_year'], 'birth_date' => null,
                'sex' => $k['sex'], 'color' => $k['color'], 'breed' => $p['breed'], 'pedigree' => ['s' => $sire, 'd' => ['name' => $k['dam']]],
                'parentage_must_agree' => true], $k['slug'] ? self::BASE.'/horse/'.$k['slug'] : $url);
            $out['progeny'] += $s === 'created' ? 1 : 0;
        }
    }

    /** The page's horse as ImportPublishedHorse input. */
    public static function input(array $p): array
    {
        return ['name' => $p['name'], 'registry' => $p['registry'], 'registration_number' => null, 'foal_year' => $p['foal_year'], 'birth_date' => null,
            'sex' => $p['sex'], 'color' => $p['color'], 'breed' => $p['breed'], 'height' => $p['height'],
            'pedigree' => array_map(fn ($l) => array_intersect_key($l, array_flip(['name', 'foal_year', 'color'])), $p['pedigree']),
            'parentage_must_agree' => true];
    }

    private function extras(Horse $horse, array $p, string $url, User $system, array &$out): void
    {
        $credit = 'Published on StallionCompare: '.$url;
        $event = function (array $attrs, array $match) use ($horse, $system, $credit) {
            $e = HorseEvent::where('horse_id', $horse->id)->where($match)->first();
            $data = $attrs + ['details' => $credit, 'contributor_id' => $system->id, 'contributor_role' => 'staff', 'source_type' => 'historical',
                'status' => 'unverified', 'visibility' => 'public', 'date_precision' => $attrs['date_precision'] ?? 'unknown'];
            if ($e) {
                $e->forceFill($data)->save();

                return false;
            }
            HorseEvent::create($data + ['horse_id' => $horse->id]);

            return true;
        };

        if ($p['died_year'] && ! HorseEvent::where('horse_id', $horse->id)->where('type', 'death')->exists()) {
            $event(['category' => 'other', 'type' => 'death', 'title' => 'Death', 'occurred_on' => $p['died_year'].'-01-01', 'date_precision' => 'year'], ['type' => 'death']);
            $out['deaths']++;
        }
        if ($p['genetics']) {
            $result = implode(', ', $p['genetics']['tests']) ?: null;
            $title = 'Genetic test'.($p['genetics']['summary'] ? ': '.$p['genetics']['summary'] : '');
            $out['genetics'] += $event(['category' => 'health', 'type' => 'genetic_test', 'title' => mb_substr($title, 0, 255), 'result' => $result ? mb_substr($result, 0, 255) : null],
                ['type' => 'genetic_test', 'details' => $credit]) ? 1 : 0;
        }
        if ($p['earnings_cents']) {
            $out['earnings'] += $event(['category' => 'achievements', 'type' => 'earnings', 'title' => 'Lifetime earnings (reported)', 'earnings_cents' => $p['earnings_cents'],
                'result' => '$'.number_format($p['earnings_cents'] / 100), 'occurred_on' => now()->toDateString(), 'date_precision' => 'day'],
                ['type' => 'earnings', 'details' => $credit]) ? 1 : 0;
        }
        foreach ($p['highlights'] as $h) {
            $year = preg_match('/^(\d{4})\b|\((\d{4})\)/', $h, $m) ? (int) ($m[1] ?: $m[2]) : null;
            $out['titles'] += $event(['category' => 'achievements', 'type' => 'title', 'title' => mb_substr($h, 0, 255),
                'occurred_on' => $year ? $year.'-01-01' : null, 'date_precision' => $year ? 'year' : 'unknown'], ['type' => 'title', 'title' => mb_substr($h, 0, 255)]) ? 1 : 0;
        }
        foreach ($p['nominations'] as $n) {
            $event(['category' => 'achievements', 'type' => 'enrollment', 'title' => mb_substr('Nominated to '.$n, 0, 255)], ['type' => 'enrollment', 'title' => mb_substr('Nominated to '.$n, 0, 255)]);
        }
        if ($p['fees'] || $p['standing'] || $p['manager']) {
            StallionService::updateOrCreate(['horse_id' => $horse->id, 'data_source_id' => $this->source->id], [
                'standing_at' => $p['standing']['name'] ?? null, 'city' => $p['standing']['city'] ?? null, 'region' => $p['standing']['region'] ?? null,
                'country' => $p['standing']['country'] ?? null, 'website' => $p['standing']['website'] ?? null,
                'phone' => $p['standing']['phone'] ?? null, 'email' => $p['standing']['email'] ?? null,
                'manager_name' => $p['manager']['name'] ?? null, 'manager_phone' => $p['manager']['phone'] ?? null, 'manager_email' => $p['manager']['email'] ?? null,
                'fee_cents' => $p['fees']['fee_cents'] ?? null, 'fee_text' => $p['fees']['fee_text'] ?? null, 'currency' => $p['fees']['currency'] ?? null,
                'chute_fee_cents' => $p['fees']['chute_fee_cents'] ?? null, 'breeding' => isset($p['fees']['breeding']) ? mb_substr($p['fees']['breeding'], 0, 500) : null,
                'source_url' => $url, 'observed_on' => now()->toDateString(),
            ]);
            $out['services']++;
        }
        foreach ($p['articles'] as $a) {
            $m = HorseMention::firstOrCreate(['horse_id' => $horse->id, 'url' => mb_substr($a['url'], 0, 500)],
                ['data_source_id' => $this->source->id, 'title' => mb_substr($a['title'], 0, 500), 'publisher' => 'Stallion Compare']);
            $out['mentions'] += $m->wasRecentlyCreated ? 1 : 0;
        }
        // Earnings printed beside a parent in the chart belong to that parent's record.
        foreach ($p['pedigree'] as $pos => $line) {
            if (! empty($line['earnings_cents'])) {
                $linked = $horse->pedigree()->where('position', $pos)->value('linked_horse_id');
                $ancestor = $linked ? Horse::find($linked) : null;
                if ($ancestor && ! $ancestor->activeStewards()->exists() && ! HorseEvent::where('horse_id', $ancestor->id)->where('type', 'earnings')->where('details', 'like', 'Published on StallionCompare%')->exists()) {
                    HorseEvent::create(['horse_id' => $ancestor->id, 'category' => 'achievements', 'type' => 'earnings', 'title' => 'Lifetime earnings (reported)',
                        'earnings_cents' => $line['earnings_cents'], 'result' => '$'.number_format($line['earnings_cents'] / 100), 'occurred_on' => now()->toDateString(),
                        'date_precision' => 'day', 'details' => $credit, 'contributor_id' => $system->id, 'contributor_role' => 'staff', 'source_type' => 'historical',
                        'status' => 'unverified', 'visibility' => 'public']);
                    $out['earnings']++;
                }
            }
        }
    }

    /** A page's body, kept gzipped; refetched with $refresh when older than 25 days (listing pages always). */
    private function keep(string $url, string $kind, bool $refresh, array &$out): ?string
    {
        $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => $kind]);
        $stale = $kind === 'listing' || ($refresh && (! $page->fetched_at || $page->fetched_at->lt(now()->subDays(25))));
        if ($page->exists && $page->path && Storage::disk('local')->exists($page->path) && ! $stale) {
            return gzdecode(Storage::disk('local')->get($page->path));
        }
        if ($page->exists && $page->status === 'skipped' && ! $stale) {
            return null;
        }
        $body = $this->fetch($url, $out);
        if ($body === null) {
            $page->fill(['status' => 'skipped', 'fetched_at' => now()])->save();

            return null;
        }
        $sha = hash('sha256', $body);
        if ($page->exists && $page->sha256 === $sha && $page->path) {
            $page->fill(['fetched_at' => now()])->save();

            return $body;
        }
        $file = 'harvest/stallioncompare/'.$kind.'/'.sha1($url).'.html.gz';
        Storage::disk('local')->put($file, gzencode($body, 9));
        $page->fill(['path' => $file, 'sha256' => $sha, 'status' => $kind === 'listing' ? 'parsed' : 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();
        $out['changed'] += $kind === 'listing' ? 0 : 1;

        return $body;
    }

    private function fetch(string $url, array &$out): ?string
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        $out['requests']++;
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)
                ->retry(2, 10000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->serverError()), throw: false)
                ->get($url); // a 404 is final: no retry
        } catch (\Throwable $e) {
            $this->say('failed '.$url.': '.$e->getMessage());

            return null;
        }
        $this->say($r->status().' '.$url);
        if (in_array($r->status(), [403, 429], true)) {
            throw new HarvestBlocked('StallionCompare answered '.$r->status().'; stopped.');
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
