<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\NameReview;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\DB;

/**
 * Richard, Oct 22 2026: HOMD listings with no registered name were imported under the page title as a barn name
 * (ImportHomdHorse), so the results matcher, which compares registered names, never saw them and made a second
 * record from the results (e.g. Wimpys Flashy Jac: HF-HJ5K-KJRF from HOMD, HF-APSX-WPCP from NRHA results).
 *
 * A barn-name-only record and a results name are treated as the same horse only when every check passes:
 *  - the names are the same (ignoring case, spacing and punctuation) and at least two words long;
 *  - no other horse, by registered name, alias or barn name, has that name;
 *  - nobody owns either record;
 *  - the barn-name record has a foal year and every result falls between age 2 and 25;
 *  - published parentage with the results (NRCHA) doesn't name a different sire or dam.
 * Otherwise the pair goes to the staff name-review list (match "barn_name"); nothing is decided for them.
 * Some HOMD titles are nicknames, not registered names, which is why the checks are strict.
 */
class LinkBarnNameRecords
{
    public const MIN_AGE = 2;
    public const MAX_AGE = 25;

    /** The barn-name-only record a results name belongs to, or null (with the reason). @return array{0: ?Horse, 1: ?string} */
    public static function candidate(string $name, ?Horse $except = null): array
    {
        $key = ShowRecord::key($name);
        if (str_word_count(preg_replace('/[^A-Za-z0-9 ]/', '', $name)) < 2) {
            return [null, null]; // one-word titles ("Freckles", "Cowboy") are nicknames far more often than registered names
        }
        $barn = Horse::whereNull('merged_into_id')->whereNull('registered_name')->where('source', 'published')
            ->whereRaw("upper(regexp_replace(barn_name, '[^A-Za-z0-9]', '', 'g')) = ?", [$key])->get();
        if ($barn->count() !== 1) {
            return [null, $barn->count() > 1 ? 'more than one barn-name record' : null];
        }
        $horse = $barn->first();
        $others = Horse::named($name)->when($except, fn ($q) => $q->where('id', '!=', $except->id))->count();

        return $others > 0 ? [null, 'another horse has this registered name'] : [$horse, null];
    }

    /** Why these results can't be the barn-name record's, or null when every check passes. */
    public static function conflict(Horse $horse, iterable $records): ?string
    {
        if ($horse->activeStewards()->exists()) {
            return 'the barn-name record has an owner';
        }
        if (! $horse->birth_year) {
            return 'no foal year to check the show years against';
        }
        $sire = $horse->pedigree()->where('position', 's')->value('name');
        $dam = $horse->pedigree()->where('position', 'd')->value('name');
        foreach ($records as $r) {
            if ($r->first_year && $r->first_year < $horse->birth_year + self::MIN_AGE) {
                return "results from {$r->first_year}, before age ".self::MIN_AGE;
            }
            if ($r->last_year && $r->last_year > $horse->birth_year + self::MAX_AGE) {
                return "results in {$r->last_year}, past age ".self::MAX_AGE;
            }
            $par = $r->parentage();
            foreach (['sire' => $sire, 'dam' => $dam] as $k => $ours) {
                if ($ours && $par[$k] && ShowRecord::key($ours) !== ShowRecord::key($par[$k])) {
                    return "results list the {$k} as {$par[$k]}, the listing says {$ours}";
                }
            }
        }

        return null;
    }

    /**
     * The results name becomes the record's registered name (historical, unverified): the checks above make it the
     * horse's show name, and charts that point at the record need a name to print. The barn name stays.
     */
    public static function adoptRegisteredName(Horse $horse, string $name, \App\Models\User $by): void
    {
        $horse->forceFill(['registered_name' => $name])->save();
        app(RecordFacts::class)($horse, $by, RecordFacts::fromColumns(['registered_name' => $name]), 'historical');
        \App\Support\Audit::record('horse.registered_name_from_results', $horse, ['registered_name' => null], ['registered_name' => $name, 'barn_name' => $horse->barn_name], $horse->id, $by->id);
    }

    /** Merge existing results-made records into their barn-name twins. @return array{merged:int, to_review:int, skipped:int} */
    public function __invoke(bool $dryRun = false, ?\Closure $log = null): array
    {
        $system = BuildRecordsFromResults::system();
        $out = ['merged' => 0, 'to_review' => 0, 'skipped' => 0];
        $barnOnly = Horse::whereNull('merged_into_id')->whereNull('registered_name')->whereNotNull('barn_name')->where('source', 'published')->get();
        foreach ($barnOnly as $keep) {
            $twins = Horse::whereNull('merged_into_id')->where('source', 'results')
                ->whereRaw("upper(regexp_replace(registered_name, '[^A-Za-z0-9]', '', 'g')) = ?", [ShowRecord::key($keep->barn_name)])->get();
            if ($twins->count() !== 1) {
                continue;
            }
            $gone = $twins->first();
            [$found, $why] = self::candidate($gone->registered_name, $gone);
            $why ??= $found?->is($keep) ? null : 'one-word name, could be a nickname';
            $why ??= $gone->activeStewards()->exists() ? 'the results record has an owner' : self::conflict($keep, ShowRecord::where('horse_id', $gone->id)->get());
            $label = "{$keep->barn_name} ({$keep->horsefax_id} <- {$gone->horsefax_id})";
            if ($why) {
                if (! $dryRun) {
                    [$a, $b] = strcmp($keep->id, $gone->id) < 0 ? [$keep->id, $gone->id] : [$gone->id, $keep->id];
                    $out['to_review'] += NameReview::insertOrIgnore(['horse_a_id' => $a, 'horse_b_id' => $b, 'match' => 'barn_name', 'priority' => 2,
                        'status' => 'open', 'note' => 'Barn name matches a results name, not merged: '.$why, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    $out['to_review']++;
                }
                $log && $log("review: $label: $why");
                continue;
            }
            if (! $dryRun) {
                DB::transaction(function () use ($keep, $gone, $system) {
                    self::adoptRegisteredName($keep, $gone->registered_name, $system);
                    app(MergeHorses::class)($keep, $gone, $system, 'Same name as the HOMD listing\'s barn name; show years fit the foal year (LinkBarnNameRecords).');
                });
            }
            $out['merged']++;
            $log && $log("merge: $label");
        }

        return $out;
    }
}
