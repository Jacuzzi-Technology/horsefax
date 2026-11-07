<?php

namespace App\Harvest;

use App\Actions\Record\AddIdentifier;
use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseIdentifier;
use App\Support\Record;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * MLOSZ, the Federation of Hungarian Horse Breeders (mlosz.hu), runs Hungary's horse breeding database with the
 * national food-chain authority (Nébih). Its free detailed search covers every horse with Hungarian registration,
 * including thousands of imported American Quarter Horses, Paints and Appaloosas and their US pedigrees. Kate found it;
 * checked Oct 27 2026: robots.txt allows everything (including /api/); the terms (Hungarian ÁSZF, the English page is a
 * placeholder) cover paid subscribers only and don't ban scraping or reuse; the privacy policy has no such clause; no
 * bot wall. Collected under Richard's standing approval.
 *
 * The search page posts {"fajtaKod": <breed>} to /api/search/detailed-search?offset=&limit=, where offset is the PAGE
 * number (0, 1, 2 ...), not a row offset (the first run took it for rows and kept 108 empty pages). It answers JSON: name,
 * Hungarian ID, foal date, sex, color, sire, dam, dam's sire and breeder. Owners are not published ("Data not public").
 * Each answer is kept as a gzipped evidence copy; load() reads only the kept copies.
 *
 * Every horse becomes or fills a record through ImportPublishedHorse (source "published", unverified, unowned), with the
 * Hungarian ID as an MLOSZ registration number and the breeder (Richard, Oct 27 2026: breeders are public, owners are
 * not). Names repeat across countries, so a same-named record whose sire or dam differs is a different horse. One
 * request at a time, at least 3 s apart, as HorsefaxBot; a 403/429 stops the run.
 */
class MloszHarvester
{
    public const BASE = 'https://mlosz.hu';
    public const SEARCH = self::BASE.'/api/search/detailed-search';
    public const PAGE = 100;

    /**
     * Every breed MLOSZ lists is collected (Richard, Oct 27 2026: build Horsefax to be useful around the world). Breed
     * names come from MLOSZ's own list (/api/assets, English, else Hungarian); these codes use the Horsefax name.
     */
    public const BREEDS = ['quart' => 'Quarter Horse', 'paint' => 'Paint', 'app' => 'Appaloosa', 'atv' => 'Thoroughbred', 'artv' => 'Arabian',
        'arab' => 'Arabian', 'morg' => 'Morgan', 'shetl' => 'Shetland Pony', 'hafl' => 'Haflinger', 'friz' => 'Friesian', 'lip' => 'Lipizzan'];

    /** Not horses: left out, as in the French import. */
    public const NOT_HORSES = ['szam', 'mpsz', 'öszvé', 'zebra'];

    private DataSource $source;
    private float $lastRequest = 0;
    /** @var array<string, string> MLOSZ code => breed name */
    private array $breedNames = [];

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, private array $breeds = [])
    {
        $this->source = DataSource::firstOrCreate(['key' => 'mlosz'], [
            'name' => 'MLOSZ – Federation of Hungarian Horse Breeders (mlosz.hu)', 'url' => self::BASE.'/en/detailed-search', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 27 2026: robots.txt allows all paths; the terms (ÁSZF, Hungarian) cover paid subscribers only, with no scraping or reuse clause; the privacy policy has none; no bot wall. Owners are not published. Found by Kate; collected under Richard\'s standing approval.',
        ]);
    }

    /** MLOSZ's breed list (kept, refreshed with --refresh): code => name. */
    public function breedNames(bool $refresh = false): array
    {
        if ($this->breedNames && ! $refresh) {
            return $this->breedNames;
        }
        $url = self::BASE.'/api/assets';
        $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'assets']);
        if ($refresh || ! $page->exists || ! $page->path || ! Storage::disk('local')->exists($page->path)) {
            $body = $this->get($url);
            if ($body === null || ! isset((json_decode($body, true) ?? [])['fajtak'])) {
                throw new HarvestBlocked('MLOSZ breed list unavailable; stopped.');
            }
            Storage::disk('local')->put('harvest/mlosz/assets.json.gz', gzencode($body, 9));
            $page->fill(['path' => 'harvest/mlosz/assets.json.gz', 'sha256' => hash('sha256', $body), 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();
        }
        foreach (json_decode(gzdecode(Storage::disk('local')->get($page->path)), true)['fajtak'] ?? [] as $b) {
            $this->breedNames[$b['fajtaKod']] = self::BREEDS[$b['fajtaKod']] ?? ($b['fajtaMegnevezesEn'] ?: ucfirst((string) $b['fajtaMegnevezesHu']));
        }

        return $this->breedNames;
    }

    /** The breeds this run covers: those asked for, or every horse breed MLOSZ lists. */
    private function breeds(bool $refresh = false): array
    {
        return $this->breeds ?: array_values(array_diff(array_keys($this->breedNames($refresh)), self::NOT_HORSES));
    }

    /** Fetch every result page of each breed not already kept. Returns counts. */
    public function collect(bool $refresh = false): array
    {
        $out = ['requests' => 0, 'kept' => 0, 'horses' => 0, 'changed' => 0];
        foreach ($this->breeds($refresh) as $breed) {
            $total = null;
            for ($p = 0; $total === null || $p * self::PAGE < $total; $p++) {
                $url = self::pageUrl($breed, $p);
                $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'search_'.$breed]);
                if (! $refresh && $page->exists && $page->path && Storage::disk('local')->exists($page->path)) {
                    $total ??= (int) (json_decode(gzdecode(Storage::disk('local')->get($page->path)), true)['total'] ?? 0);
                    $out['kept']++;
                    continue;
                }
                $body = $this->post($breed, $p);
                $out['requests']++;
                $json = $body === null ? null : json_decode($body, true);
                if (! is_array($json) || ! isset($json['horses'])) {
                    $page->fill(['status' => 'failed', 'error' => 'No JSON answer', 'fetched_at' => now()])->save();
                    break;
                }
                $total = (int) $json['total'];
                $out['horses'] += count($json['horses']);
                if ($page->exists && $page->status === 'parsed' && $page->sha256 === hash('sha256', $body)) {
                    $page->fill(['fetched_at' => now()])->save(); // unchanged since it was loaded: nothing to reload

                    continue;
                }
                $file = 'harvest/mlosz/'.$breed.'/page-'.str_pad((string) $p, 4, '0', STR_PAD_LEFT).'.json.gz';
                Storage::disk('local')->put($file, gzencode($body, 9));
                $page->fill(['path' => $file, 'sha256' => hash('sha256', $body), 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now(), 'rows' => count($json['horses'])])->save();
                $out['changed']++;
            }
            $this->say($breed.': '.($total ?? 0).' horses listed');
        }

        return $out;
    }

    /**
     * Turn kept pages into records: those fetched since they were last loaded (new or changed), or all with $all.
     * Loading is the slow part (about 200 horses a minute), so a monthly refresh only reloads what changed: MLOSZ lists
     * horses in id order, so new horses land on the last pages.
     */
    public function load(bool $all = false): array
    {
        $out = ['pages' => 0, 'horses' => 0, 'with_breeder' => 0, 'skipped_no_name' => 0, 'ueln_added' => 0, 'deaths_added' => 0];
        $names = $this->breedNames();
        $pages = HarvestPage::where('data_source_id', $this->source->id)->whereIn('kind', array_map(fn ($b) => 'search_'.$b, $this->breeds()))
            ->whereNotNull('path')->when(! $all, fn ($q) => $q->where('status', 'fetched'))->orderBy('url')->get();
        $import = app(ImportPublishedHorse::class);
        $system = BuildRecordsFromResults::system();
        foreach ($pages as $page) {
            $json = json_decode(gzdecode(Storage::disk('local')->get($page->path)), true) ?? [];
            foreach ($json['horses'] ?? [] as $row) {
                $h = self::horse($row, $names);
                if (! $h) {
                    $out['skipped_no_name']++;
                    continue;
                }
                $url = self::BASE.'/en/horse-datacard/'.$row['loId'];
                $status = $import($h, $url);
                $out[$status] = ($out[$status] ?? 0) + 1;
                $out['horses']++;
                $out['with_breeder'] += $h['breeder'] ? 1 : 0;
                if (! str_starts_with($status, 'skipped') && ($h['ueln'] || $h['deceased'])) {
                    $this->extras($h, $url, $system, $out);
                }
            }
            $page->forceFill(['status' => 'parsed'])->save();
            $out['pages']++;
            if ($out['pages'] % 10 === 0) {
                $this->say(json_encode($out));
            }
        }

        return $out;
    }

    /**
     * UELN (the international life number) as its own identifier, and a death entry when the stud book records the
     * horse as a permanent loss ("fogyaték végleges"). The data sheet also shows very old horses as deceased with no such
     * record, apparently by age; only the recorded loss is taken.
     */
    private function extras(array $h, string $url, $system, array &$out): void
    {
        $horse = Horse::whereIn('id', HorseIdentifier::where('kind', 'registration')->where('registry', 'MLOSZ')->whereNull('superseded_by')
            ->where('value_normalized', Record::normalizeIdentifier($h['registration_number']))->select('horse_id'))->first();
        if (! $horse || $horse->activeStewards()->exists()) {
            return;
        }
        if ($h['ueln'] && ! $horse->identifiers()->where('kind', 'ueln')->where('value_normalized', Record::normalizeIdentifier($h['ueln']))->exists()) {
            app(AddIdentifier::class)($horse, $system, ['kind' => 'ueln', 'registry' => null, 'value' => $h['ueln'], 'visibility' => 'public', 'source_type' => 'historical']);
            $out['ueln_added']++;
        }
        if ($h['deceased'] && ! HorseEvent::where('horse_id', $horse->id)->where('type', 'death')->exists()) {
            HorseEvent::create(['horse_id' => $horse->id, 'category' => 'other', 'type' => 'death', 'title' => 'Death', 'occurred_on' => null, 'date_precision' => 'unknown',
                'details' => 'Recorded as deceased (permanent loss) in the Hungarian stud book (MLOSZ); no date published. Source: '.$url,
                'contributor_id' => $system->id, 'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public']);
            $out['deaths_added']++;
        }
    }

    /** One search row as ImportPublishedHorse input, or null when it has no usable name. */
    public static function horse(array $r, array $breedNames = []): ?array
    {
        $name = trim((string) ($r['nev'] ?? ''));
        if ($name === '' || Record::placeholderName($name)) {
            return null;
        }
        $year = $r['szuletesiEv'] ?? null;
        $date = $year && ($r['szuletesiHo'] ?? null) && ($r['szuletesiNap'] ?? null)
            ? sprintf('%04d-%02d-%02d', $year, $r['szuletesiHo'], $r['szuletesiNap']) : null;
        $sex = match ($r['nemKod'] ?? null) {
            'K' => 'mare',
            'M' => ! empty($r['ivartalanitva']) ? 'gelding' : 'stallion',
            default => null,
        };
        $color = $r['szinEn'] ?? $r['laraSzincsoportEn'] ?? null;

        return [
            'name' => $name, 'registry' => 'MLOSZ', 'registration_number' => trim((string) ($r['loAzon'] ?? '')) ?: null,
            'foal_year' => $year ? (int) $year : null, 'birth_date' => $date, 'sex' => $sex,
            'color' => $color ? ucfirst(trim($color)) : null, 'breed' => self::BREEDS[$r['fajtaKod'] ?? ''] ?? $breedNames[$r['fajtaKod'] ?? ''] ?? null,
            'breeder' => self::breeder($r['tenyesztoNev'] ?? null),
            'ueln' => trim((string) ($r['ueln'] ?? '')) ?: null, 'deceased' => ! empty($r['fogyatekVegleges']),
            'pedigree' => array_filter(['s' => trim((string) ($r['apaNev'] ?? '')), 'd' => trim((string) ($r['anyaNev'] ?? '')), 'ds' => trim((string) ($r['anyApNev'] ?? ''))]),
            'parentage_must_agree' => true,
        ];
    }

    /** The breeder as published, with the Hungarian "és" ("and") and "és/vagy" ("and/or") in English. */
    public static function breeder(?string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '') {
            return null;
        }

        return mb_substr(preg_replace(['/\bés\/vagy\b/u', '/\s+és\s+/u'], ['and/or', ' & '], $name), 0, 160);
    }

    public static function pageUrl(string $breed, int $page): string
    {
        return self::SEARCH.'?fajtaKod='.$breed.'&page='.$page.'&limit='.self::PAGE;
    }

    private function get(string $url): ?string
    {
        $this->pause();
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA, 'Accept' => 'application/json'])->timeout(90)->retry(2, 10000, throw: false)->get($url);
        } catch (\Throwable $e) {
            $this->say('failed '.$url.': '.$e->getMessage());

            return null;
        }
        $this->say($r->status().' '.$url);
        if (in_array($r->status(), [403, 429], true)) {
            throw new HarvestBlocked('MLOSZ answered '.$r->status().'; stopped.');
        }

        return $r->successful() ? $r->body() : null;
    }

    private function pause(): void
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
    }

    private function post(string $breed, int $page): ?string
    {
        $this->pause();
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA, 'Accept' => 'application/json'])->timeout(90)->retry(2, 10000, throw: false)
                ->post(self::SEARCH.'?offset='.$page.'&limit='.self::PAGE, ['fajtaKod' => $breed]);
        } catch (\Throwable $e) {
            $this->say('failed '.$breed.' page '.$page.': '.$e->getMessage());

            return null;
        }
        $this->say($r->status().' '.$breed.' page '.$page);
        if (in_array($r->status(), [403, 429], true)) {
            throw new HarvestBlocked('MLOSZ answered '.$r->status().'; stopped.');
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
