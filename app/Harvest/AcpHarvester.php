<?php

namespace App\Harvest;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseMention;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * American Classic Pedigrees (americanclassicpedigrees.com), Avalyn Hunter's library of about 3,300 Thoroughbred
 * profiles: Classic winners, champions, Hall of Famers, leading sires and Broodmares of the Year of the Americas.
 * Checked Oct 27 2026: robots.txt blocks only /ajax/ and /apps/; no terms of use; the footer's "All rights reserved"
 * covers her writing, so facts only and never the prose. Richard asked for it built and loaded (Oct 27 2026).
 *
 * Pages come from sitemap.xml; pages that aren't horse profiles (articles, blogs) don't parse and are skipped. Each
 * profile becomes or fills a record through ImportPublishedHorse (breed Thoroughbred, unverified; same-named records
 * with different parents stay apart; "(USA)" dropped from names, other country suffixes kept), with: foal date, color,
 * sex, breeder, sire/dam/damsire, death date, the race record (Achievements & earnings; dollars only as earnings), each
 * listed race result (Competition · Race result, by year), Honors (titles) and a link back to the profile. One request
 * at a time, at least 3 s apart, as HorsefaxBot.
 */
class AcpHarvester
{
    use PoliteFetch;

    public const BASE = 'https://www.americanclassicpedigrees.com';

    private DataSource $source;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'acp'], [
            'name' => 'American Classic Pedigrees (Avalyn Hunter)', 'url' => self::BASE.'/horse-profiles.html', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 27 2026: robots.txt blocks only /ajax/ and /apps/; no terms of use; "© Avalyn Hunter. All rights reserved" covers her writing, so facts only, never prose. Richard approved building and loading it.',
        ]);
    }

    /** Fetch every sitemap page not kept yet. Returns counts. */
    public function collect(?int $max = null): array
    {
        $out = ['listed' => 0, 'fetched' => 0, 'kept' => 0];
        $r = $this->fetch(self::BASE.'/sitemap.xml');
        if ($r && in_array($r->status(), [403, 429], true)) {
            throw new HarvestBlocked('American Classic Pedigrees answered '.$r->status().'; stopped.');
        }
        preg_match_all('#<loc>(https://www\.americanclassicpedigrees\.com/[a-z0-9-]+\.html)</loc>#', (string) $r?->body(), $m);
        foreach (array_unique($m[1]) as $url) {
            $out['listed']++;
            if (HarvestPage::where('url', $url)->whereNotNull('path')->exists()) {
                $out['kept']++;
                continue;
            }
            if ($max !== null && $out['fetched'] >= $max) {
                continue;
            }
            $this->page($url, 'profile');
            $out['fetched']++;
        }

        return $out;
    }

    /** Load profiles fetched since they were last loaded. Returns counts. */
    public function load(): array
    {
        $out = ['pages' => 0, 'not_profiles' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'results' => 0, 'honors' => 0, 'breeders' => 0];
        $system = BuildRecordsFromResults::system();
        $import = new ImportPublishedHorse;
        HarvestPage::where('data_source_id', $this->source->id)->where('kind', 'profile')->where('status', 'fetched')->whereNotNull('path')->orderBy('url')
            ->each(function (HarvestPage $page) use ($system, $import, &$out) {
                $p = AcpParser::page(gzdecode(Storage::disk('local')->get($page->path)));
                if (! $p) {
                    $out['not_profiles']++;
                    $page->forceFill(['status' => 'skipped', 'error' => 'Not a horse profile'])->save();

                    return;
                }
                $status = $import(['name' => $p['name'], 'registry' => null, 'registration_number' => null, 'foal_year' => $p['foal_year'], 'birth_date' => $p['birth_date'],
                    'sex' => $p['sex'], 'color' => $p['color'], 'breed' => 'Thoroughbred', 'breeder' => $p['breeder'], 'pedigree' => $p['pedigree'],
                    'parentage_must_agree' => true], $page->url);
                $out[$status] = ($out[$status] ?? 0) + 1;
                $out['breeders'] += $p['breeder'] ? 1 : 0;
                if (($horse = $import->last) && ! $horse->activeStewards()->exists()) {
                    DB::transaction(fn () => $this->extras($horse, $p, $page->url, $system, $out));
                }
                $page->forceFill(['status' => 'parsed'])->save();
                if (++$out['pages'] % 100 === 0) {
                    $this->say(json_encode($out));
                }
            });

        return $out;
    }

    private function extras(Horse $horse, array $p, string $url, User $system, array &$out): void
    {
        $credit = 'Published on American Classic Pedigrees: '.$url;
        $base = ['details' => $credit, 'contributor_id' => $system->id, 'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public'];
        $add = function (array $attrs, array $match) use ($horse, $base) {
            if (HorseEvent::where('horse_id', $horse->id)->where($match)->exists()) {
                return false;
            }
            HorseEvent::create($attrs + $base + ['horse_id' => $horse->id, 'date_precision' => 'unknown']);

            return true;
        };

        if ($p['died_year']) {
            $add(['category' => 'other', 'type' => 'death', 'title' => 'Death', 'occurred_on' => $p['died_on'] ?? $p['died_year'].'-01-01', 'date_precision' => $p['died_on'] ? 'day' : 'year'], ['type' => 'death']);
        }
        if ($p['record']) {
            $add(['category' => 'achievements', 'type' => 'earnings', 'title' => 'Race record', 'result' => $p['record'], 'earnings_cents' => $p['earnings_cents'],
                'occurred_on' => now()->toDateString(), 'date_precision' => 'day'], ['type' => 'earnings', 'details' => $credit]);
        }
        foreach ($p['results'] as $r) {
            $out['results'] += $add(['category' => 'competition', 'type' => 'race', 'title' => mb_substr($r['text'], 0, 255), 'event_name' => mb_substr($r['race'], 0, 255),
                'class_name' => $r['class'], 'organization' => $r['track'], 'placing' => $r['placing'], 'discipline' => 'Thoroughbred racing',
                'occurred_on' => $r['year'].'-01-01', 'date_precision' => 'year'], ['type' => 'race', 'title' => mb_substr($r['text'], 0, 255), 'occurred_on' => $r['year'].'-01-01']) ? 1 : 0;
        }
        foreach ($p['honors'] as $h) {
            $year = preg_match('/\((\d{4})\)|\b(\d{4})\b/', $h, $m) ? (int) ($m[1] ?: $m[2]) : null;
            $out['honors'] += $add(['category' => 'achievements', 'type' => 'title', 'title' => $h, 'occurred_on' => $year ? $year.'-01-01' : null,
                'date_precision' => $year ? 'year' : 'unknown'], ['type' => 'title', 'title' => $h]) ? 1 : 0;
        }
        HorseMention::firstOrCreate(['horse_id' => $horse->id, 'url' => $url],
            ['data_source_id' => $this->source->id, 'title' => 'Profile of '.$p['name'].' by Avalyn Hunter', 'publisher' => 'American Classic Pedigrees']);
    }
}
