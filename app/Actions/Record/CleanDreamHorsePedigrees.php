<?php

namespace App\Actions\Record;

use App\Harvest\DreamHorseParser;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\NameReview;
use App\Models\PedigreeEntry;
use App\Models\SaleLot;
use App\Models\ShowRecord;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for the first DreamHorse load (Oct 23 2026). Sellers type notes into the pedigree chart ("Kickoff
 * Time LTE 50k and 200 AQHA Points", "Instagrand by Into Mischief"), and the loader took them as names, before
 * DreamHorseParser::pedigreeName() existed. So:
 *  - the records it created carried junk sire and dam names, and some of them are the same horse as another record;
 *  - CreateAncestorRecords made ancestor records under the junk names, and copied the junk lines into their charts.
 *
 * For every junk name on a DreamHorse lot whose record was created from it (match_tier "created"):
 *  1. An ancestor record (source "pedigree") under the junk name: when exactly one record already has the clean name,
 *     its links move there; when none does, it's renamed to the clean name; when several do, or nothing name-like is
 *     left, its links are dropped.
 *  2. Pedigree lines written by the system under the junk name get the clean name (or go, if nothing's left).
 *  3. The lots' sire, dam and dam's sire, and their pedigree, are cleaned.
 *  4. A created record that another record of the same name now agrees with (clean sire and dam) is PROPOSED to staff as
 *     a duplicate pair (name_reviews, open). Never merged automatically.
 *  5. Ancestor records left with nothing pointing at them go (PruneOrphanAncestors).
 * Everything runs in one transaction; a dry run rolls it back, so its counts are real.
 */
class CleanDreamHorsePedigrees
{
    public function __invoke(bool $dryRun = false, ?\Closure $log = null): array
    {
        $out = ['created_lots' => 0, 'lots_cleaned' => 0, 'junk_names' => 0, 'ancestors_renamed' => 0, 'ancestors_relinked' => 0,
            'ancestors_unlinked' => 0, 'lines_renamed' => 0, 'lines_removed' => 0, 'duplicates_proposed' => 0, 'ancestors_pruned' => 0];
        $source = DataSource::where('key', 'dreamhorse')->first();
        if (! $source) {
            return $out;
        }
        DB::beginTransaction();
        try {
            $this->run($source, $out, $log);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $dryRun ? DB::rollBack() : DB::commit();

        return $out;
    }

    private function run(DataSource $source, array &$out, ?\Closure $log): void
    {
        $system = BuildRecordsFromResults::system();
        $lots = SaleLot::where('data_source_id', $source->id)->where('match_tier', 'created')->whereNotNull('horse_id')->get();
        $out['created_lots'] = $lots->count();

        // The junk names, and what each becomes (null: nothing name-like left).
        $junk = [];
        foreach ($lots as $lot) {
            foreach (array_merge($lot->pedigree ?? [], array_filter(['s' => $lot->sire_name, 'd' => $lot->dam_name, 'ds' => $lot->dam_sire_name])) as $raw) {
                $clean = DreamHorseParser::pedigreeName($raw);
                if ($clean !== $raw) {
                    $junk[$raw] = $clean;
                }
            }
        }
        $out['junk_names'] = count($junk);
        $log && $log(count($junk).' junk names on '.$lots->count().' created lots');

        // 1. Ancestor records made under a junk name.
        $moveTo = [];   // junk ancestor id => clean record id (or null: unlink)
        $prune = [];
        foreach (array_chunk(array_keys($junk), 500) as $chunk) {
            foreach (Horse::where('source', 'pedigree')->whereIn('registered_name', $chunk)->whereNull('merged_into_id')->get() as $a) {
                $clean = $junk[$a->registered_name];
                $existing = $clean === null ? collect() : Horse::named($clean)->where('id', '!=', $a->id)->pluck('id');
                if ($clean !== null && $existing->isEmpty()) {
                    $old = $a->registered_name;
                    $a->forceFill(['registered_name' => $clean])->save();
                    app(RecordFacts::class)($a, $system, RecordFacts::fromColumns(['registered_name' => $clean]), 'historical');
                    Audit::record('horse.renamed_cleanup', $a, ['registered_name' => $old], ['registered_name' => $clean, 'reason' => 'DreamHorse seller note removed from pedigree name'], $a->id, $system->id);
                    $out['ancestors_renamed']++;
                } else {
                    $moveTo[$a->id] = $existing->count() === 1 ? $existing->first() : null;
                    $out[$existing->count() === 1 ? 'ancestors_relinked' : 'ancestors_unlinked']++;
                    $prune[] = $a->id;
                }
            }
        }

        // 2. Pedigree lines under a junk name (the created records' charts, and the lines copied into ancestors).
        foreach (array_chunk(array_keys($junk), 500) as $chunk) {
            foreach (PedigreeEntry::whereIn('name', $chunk)->where('added_by', $system->id)->get() as $line) {
                $clean = $junk[$line->name];
                if ($clean === null) {
                    $prune[] = $line->linked_horse_id;
                    Audit::record('pedigree.removed_cleanup', $line, $line->only(['position', 'name', 'linked_horse_id']), null, $line->horse_id, $system->id);
                    $line->delete();
                    $out['lines_removed']++;
                    continue;
                }
                $link = $line->linked_horse_id;
                if ($link && array_key_exists($link, $moveTo)) {
                    $link = $moveTo[$link];
                }
                $before = $line->only(['name', 'linked_horse_id']);
                $line->forceFill(['name' => $clean, 'linked_horse_id' => $link])->save();
                Audit::record('pedigree.renamed_cleanup', $line, $before, $line->only(['name', 'linked_horse_id']), $line->horse_id, $system->id);
                $out['lines_renamed']++;
            }
        }
        // Lines elsewhere (not under a junk name) that linked to a junk ancestor follow it too.
        foreach ($moveTo as $from => $to) {
            PedigreeEntry::where('linked_horse_id', $from)->update(['linked_horse_id' => $to]);
        }

        // 3. The lots themselves, and 4. duplicate pairs for staff.
        $matcher = app(MatchSaleLots::class);
        foreach ($lots as $lot) {
            $ped = array_filter(array_map(fn ($n) => DreamHorseParser::pedigreeName($n), $lot->pedigree ?? []));
            $parents = ['sire_name' => DreamHorseParser::pedigreeName($lot->sire_name), 'dam_name' => DreamHorseParser::pedigreeName($lot->dam_name),
                'dam_sire_name' => DreamHorseParser::pedigreeName($lot->dam_sire_name)];
            if ($ped === ($lot->pedigree ?? []) && $parents === $lot->only(array_keys($parents))) {
                continue;
            }
            $lot->forceFill($parents + ['pedigree' => $ped ?: null])->save();
            $out['lots_cleaned']++;
            $horse = Horse::find($lot->horse_id);
            if (! $horse || ! $horse->registered_name) {
                continue;
            }
            foreach (Horse::named($horse->registered_name)->where('id', '!=', $horse->id)->get() as $other) {
                if (! $this->sameParents($other, $parents) || $matcher->contradiction($lot, $other, $parents['sire_name'], $parents['dam_name'])) {
                    continue;
                }
                [$a, $b] = strcmp($horse->id, $other->id) < 0 ? [$horse->id, $other->id] : [$other->id, $horse->id];
                $out['duplicates_proposed'] += NameReview::insertOrIgnore(['horse_a_id' => $a, 'horse_b_id' => $b, 'match' => 'same_letters', 'priority' => 1,
                    'status' => 'open', 'note' => mb_substr('DreamHorse cleanup: same name, and the same sire and dam once the seller\'s notes are taken out of the ad\'s chart ('
                        .$horse->horsefax_id.' from '.$lot->source_url.'). Proposed only; not merged.', 0, 1000), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // 5. Ancestors nothing points at any more.
        $out['ancestors_pruned'] = count(app(PruneOrphanAncestors::class)(array_values(array_unique(array_filter($prune)))));
        $log && $log(json_encode($out));
    }

    /** Does the record's chart agree on both parents the lot gives (at least one given, none different)? */
    private function sameParents(Horse $h, array $parents): bool
    {
        $have = PedigreeEntry::where('horse_id', $h->id)->whereNull('superseded_by')->whereIn('position', ['s', 'd'])->pluck('name', 'position');
        $agree = 0;
        foreach (['s' => $parents['sire_name'], 'd' => $parents['dam_name']] as $pos => $name) {
            if (! $name || ! isset($have[$pos])) {
                continue;
            }
            if (ShowRecord::key($name) !== ShowRecord::key($have[$pos])) {
                return false;
            }
            $agree++;
        }

        return $agree > 0;
    }
}
