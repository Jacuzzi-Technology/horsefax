<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\OffspringEarning;
use Illuminate\Support\Facades\DB;

/**
 * Richard, Oct 22 2026: sire and dam offspring earnings, computed by Horsefax from the results it has harvested, the
 * way EquiStat compiles its own (NRHA keeps the official totals but publishes none as data). Totals stay OFF public
 * horse pages until they roughly match NRHA's published milestones (see benchmark()); Richard decides when.
 *
 * A foal counts toward a parent when:
 *  - its result rows are attached to its record (show_appearances.horse_event_id), merged records followed;
 *  - its current sire/dam line links to that parent's record, or, when the line isn't linked, names exactly one
 *    record (registered name or alias). National registry list records (IFCE) are never matched by name.
 * The same result published on two harvested pages counts once. Money is kept per organization as published with the
 * result (NRHA, NRCHA, ...): NRHA's milestones are reining only, so the benchmark uses the NRHA rows alone.
 * NRHA result rows carry no sire, so an NRHA earner counts only when its pedigree came from somewhere else (NRCHA
 * results, AQHA papers, sale catalogs, stallion sites, HOMD). Rerunnable: each run replaces every row.
 */
class OffspringEarnings
{
    public const BENCHMARK_ORG = 'NRHA';

    /**
     * NRHA's Million Dollar Earners page (https://nrha.com/million-dollar-earners/, read Oct 22 2026): the highest $1M
     * tier each sire or dam has passed, and the year. Tiers only, no dollar amounts: a floor, not the real total.
     * Sires at $4M and up, and every listed dam. NRHA lists Colonels Smoking Gun by his barn name, Gunner.
     * [name, role, millions passed, year passed, other names it may be on record as]
     */
    public const NRHA_MILESTONES = [
        ['Gunnatrashya', 'sire', 16, 2025, []],
        ['Colonels Smoking Gun', 'sire', 15, 2024, ['Gunner']],
        ['Magnum Chic Dream', 'sire', 15, 2026, []],
        ['Spooks Gotta Whiz', 'sire', 15, 2026, []],
        ['Wimpys Little Step', 'sire', 13, 2023, []],
        ['Topsail Whiz', 'sire', 13, 2024, []],
        ['Smart Spook', 'sire', 9, 2025, []],
        ['Gunners Special Nite', 'sire', 9, 2026, []],
        ['Smart Chic Olena', 'sire', 7, 2019, []],
        ['Hollywood Dun It', 'sire', 6, 2010, []],
        ['Shiners Voodoo Dr', 'sire', 6, 2025, []],
        ['Walla Walla Whiz', 'sire', 5, 2024, []],
        ['Inferno Sixty Six', 'sire', 5, 2025, []],
        ['Colonels Shining Gun', 'sire', 5, 2025, []],
        ['Shining Spark', 'sire', 4, 2013, []],
        ['Custom Crome', 'sire', 4, 2016, []],
        ['Gallo Del Cielo', 'sire', 4, 2017, []],
        ['Smart Like Juice', 'sire', 4, 2019, []],
        ['Jacs Electric Spark', 'sire', 4, 2024, []],
        ['Pale Face Dunnit', 'sire', 4, 2024, []],
        ['A Sparkling Vintage', 'sire', 4, 2024, []],
        ['Hollywoodstinseltown', 'sire', 4, 2025, []],
        ['SG Frozen Enterprize', 'sire', 4, 2025, []],
        ['Spooks Gotta Gun', 'sire', 4, 2026, []],
        ['Wimpys Little Chic', 'dam', 2, 2024, []],
        ['Ebony Shines', 'dam', 1, 2018, []],
        ['Dun It For Chex', 'dam', 1, 2019, []],
        ['Snip O Gun', 'dam', 1, 2019, []],
        ['Princess In Diamonds', 'dam', 1, 2020, []],
        ['Wind Her Up Shiner', 'dam', 1, 2022, []],
        ['Dunit A Lil Ruf', 'dam', 1, 2022, []],
        ['Best Stop', 'dam', 1, 2023, []],
        ['Americasnextgunmodel', 'dam', 1, 2024, []],
        ['Wind Her Up Chic', 'dam', 1, 2024, []],
        ['Shesouttayourleague', 'dam', 1, 2024, []],
        ['This Chicsdundreamin', 'dam', 1, 2024, []],
        ['Chexanicki', 'dam', 1, 2024, []],
        ['Gunna Be Custom', 'dam', 1, 2025, []],
        ['Stop Little Sister', 'dam', 1, 2025, []],
        ['Electric Cha Ching', 'dam', 1, 2026, []],
        ['Redhot Walla', 'dam', 1, 2026, []],
    ];

    /** Rebuild every row. @return array the run's coverage stats */
    public function __invoke(?\Closure $log = null): array
    {
        $started = microtime(true);
        $log ??= fn () => null;
        foreach (['oe_results', 'oe_parents', 'oe_foal_org'] as $t) {
            DB::statement("drop table if exists $t");
        }

        // Attached results, each counted once even when two harvested pages publish it; the foal is the record the
        // result is attached to now (a merge moves results to the kept record).
        DB::statement(<<<'SQL'
            create temp table oe_results as
            select coalesce(h.merged_into_id, h.id) foal_id, coalesce(a.organization, 'Other') org, a.event_year, coalesce(a.earnings_cents, 0) cents
            from (select distinct on (show_record_id, organization, event_name, event_year, class_name, coalesce(round, ''), coalesce("placing", ''), coalesce(earnings_cents, 0))
                         organization, event_year, earnings_cents, horse_event_id
                  from show_appearances where horse_event_id is not null
                  order by show_record_id, organization, event_name, event_year, class_name, coalesce(round, ''), coalesce("placing", ''), coalesce(earnings_cents, 0), id) a
            join horse_events e on e.id = a.horse_event_id
            join horses h on h.id = e.horse_id
        SQL);
        $log('results: '.DB::table('oe_results')->count());

        // Each foal's current sire and dam line, and the parent's record (merged records followed).
        DB::statement(<<<'SQL'
            create temp table oe_parents as
            select distinct on (p.horse_id, p.position) p.horse_id foal_id, case p.position when 's' then 'sire' else 'dam' end role,
                   p.name, coalesce(ph.merged_into_id, ph.id) parent_id, p.linked_horse_id is not null linked
            from pedigree_entries p
            join horses f on f.id = p.horse_id and f.merged_into_id is null
            left join horses ph on ph.id = p.linked_horse_id
            where p.position in ('s', 'd') and p.superseded_by is null and p.status <> 'disputed'
            order by p.horse_id, p.position, p.updated_at desc
        SQL);
        // Unlinked lines: a name that belongs to exactly one record (never an IFCE record) is that parent.
        $byName = DB::update(<<<'SQL'
            update oe_parents o set parent_id = m.id
            from (select k, (array_agg(id))[1] id from (
                    select lower(registered_name) k, id from horses
                    where merged_into_id is null and coalesce(source, '') <> 'ifce' and registered_name is not null
                    union
                    select lower(n.name), n.horse_id from horse_names n join horses h on h.id = n.horse_id
                    where n.kind = 'alias' and h.merged_into_id is null and coalesce(h.source, '') <> 'ifce'
                  ) x group by k having count(distinct id) = 1) m
            where o.parent_id is null and lower(trim(o.name)) = m.k and m.id <> o.foal_id
        SQL);
        DB::statement('delete from oe_parents where parent_id = foal_id');
        DB::statement('create index on oe_parents (foal_id)');
        $log("parent lines: ".DB::table('oe_parents')->count()." ($byName matched by name)");

        DB::statement(<<<'SQL'
            create temp table oe_foal_org as
            select foal_id, org, sum(cents) cents, min(event_year) first_year, max(event_year) last_year from oe_results group by 1, 2
        SQL);
        DB::statement('create index on oe_foal_org (foal_id)');

        $stats = $this->coverage($byName);

        DB::transaction(function () {
            OffspringEarning::query()->delete();
            // Per organization: foals that showed there.
            DB::statement(<<<'SQL'
                insert into offspring_earnings (horse_id, role, organization, offspring, starters, earners, earnings_cents, first_year, last_year, computed_at)
                select p.parent_id, p.role, f.org, count(distinct p.foal_id), count(distinct p.foal_id), count(distinct p.foal_id) filter (where f.cents > 0),
                       sum(f.cents), min(f.first_year), max(f.last_year), now()
                from oe_parents p join oe_foal_org f on f.foal_id = p.foal_id
                where p.parent_id is not null group by p.parent_id, p.role, f.org
            SQL);
            // All organizations: every foal on record, how many showed, how many earned.
            DB::statement(<<<'SQL'
                insert into offspring_earnings (horse_id, role, organization, offspring, starters, earners, earnings_cents, first_year, last_year, computed_at)
                select p.parent_id, p.role, 'all', count(*), count(f.foal_id), count(*) filter (where f.cents > 0),
                       coalesce(sum(f.cents), 0), min(f.first_year), max(f.last_year), now()
                from oe_parents p
                left join (select foal_id, sum(cents) cents, min(first_year) first_year, max(last_year) last_year from oe_foal_org group by 1) f on f.foal_id = p.foal_id
                where p.parent_id is not null group by p.parent_id, p.role
            SQL);
        });
        $stats['rows'] = OffspringEarning::count();
        $stats['benchmark'] = self::benchmark(); // kept with the run so the staff page computes nothing on load
        foreach (['oe_results', 'oe_parents', 'oe_foal_org'] as $t) {
            DB::statement("drop table if exists $t");
        }
        DB::table('offspring_earnings_runs')->insert(['stats' => json_encode($stats), 'seconds' => (int) round(microtime(true) - $started),
            'created_at' => now(), 'updated_at' => now()]);

        return $stats;
    }

    /** Where the money is, and why some of it can't be credited to a parent yet. */
    private function coverage(int $byName): array
    {
        $orgs = [];
        // Attached results, by whether the foal's sire is known.
        $rows = DB::select(<<<'SQL'
            select f.org,
                   count(distinct f.foal_id) horses, count(distinct f.foal_id) filter (where f.cents > 0) earners,
                   count(distinct f.foal_id) filter (where s.parent_id is not null) horses_with_sire,
                   count(distinct f.foal_id) filter (where f.cents > 0 and s.parent_id is not null) earners_with_sire,
                   sum(f.cents) attached_cents,
                   coalesce(sum(f.cents) filter (where s.parent_id is not null), 0) sire_cents,
                   coalesce(sum(f.cents) filter (where s.foal_id is not null and s.parent_id is null), 0) sire_named_unlinked_cents,
                   coalesce(sum(f.cents) filter (where s.foal_id is null), 0) no_sire_line_cents,
                   coalesce(sum(f.cents) filter (where d.parent_id is not null), 0) dam_cents
            from oe_foal_org f
            left join oe_parents s on s.foal_id = f.foal_id and s.role = 'sire'
            left join oe_parents d on d.foal_id = f.foal_id and d.role = 'dam'
            group by f.org
        SQL);
        foreach ($rows as $r) {
            $orgs[$r->org] = array_map(fn ($v) => (int) $v, (array) $r);
            $orgs[$r->org]['org'] = $r->org;
        }
        // Results not on any record yet: the name isn't matched to a horse, or it is but the result is held for staff.
        $rows = DB::select(<<<'SQL'
            select coalesce(a.organization, 'Other') org,
                   count(*) filter (where r.horse_id is null) unmatched_results, coalesce(sum(a.earnings_cents) filter (where r.horse_id is null), 0) unmatched_cents,
                   count(*) filter (where r.horse_id is not null) held_results, coalesce(sum(a.earnings_cents) filter (where r.horse_id is not null), 0) held_cents
            from show_appearances a join show_records r on r.id = a.show_record_id
            where a.horse_event_id is null group by 1
        SQL);
        foreach ($rows as $r) {
            $orgs[$r->org] = ($orgs[$r->org] ?? ['org' => $r->org]) + array_map(fn ($v) => (int) $v, array_diff_key((array) $r, ['org' => 1]));
        }
        uasort($orgs, fn ($a, $b) => ($b['attached_cents'] ?? 0) + ($b['unmatched_cents'] ?? 0) <=> ($a['attached_cents'] ?? 0) + ($a['unmatched_cents'] ?? 0));

        // NRHA years harvested so far (the harvest runs newest year first): results and money per year.
        $years = DB::table('show_appearances')->where('organization', self::BENCHMARK_ORG)
            ->selectRaw('event_year, count(*) n, coalesce(sum(earnings_cents), 0) cents')->groupBy('event_year')->orderBy('event_year')->get()
            ->mapWithKeys(fn ($r) => [$r->event_year => ['results' => (int) $r->n, 'cents' => (int) $r->cents]])->all();

        $foals = DB::selectOne(<<<'SQL'
            select count(distinct foal_id) foals_with_results,
                   count(distinct foal_id) filter (where foal_id in (select foal_id from oe_parents where role = 'sire' and parent_id is not null)) with_sire,
                   count(distinct foal_id) filter (where foal_id in (select foal_id from oe_parents where role = 'dam' and parent_id is not null)) with_dam
            from oe_foal_org
        SQL);

        return [
            'computed_at' => now()->toIso8601String(),
            'foals_with_results' => (int) $foals->foals_with_results,
            'foals_with_sire' => (int) $foals->with_sire,
            'foals_with_dam' => (int) $foals->with_dam,
            'parents_matched_by_name' => $byName,
            'unlinked_parent_lines' => DB::table('oe_parents')->whereNull('parent_id')->count(),
            'organizations' => array_values($orgs),
            'nrha_years' => $years,
        ];
    }

    /** Our NRHA-only totals against NRHA's milestone floors. @return array<int, array> */
    public static function benchmark(): array
    {
        $out = [];
        foreach (self::NRHA_MILESTONES as [$name, $role, $millions, $year, $aka]) {
            // Horse::named skips merged and IFCE records.
            $ids = collect(array_merge([$name], $aka))->flatMap(fn ($n) => Horse::named($n)->pluck('id'))->unique()->values();
            $rows = OffspringEarning::whereIn('horse_id', $ids)->where('role', $role)->get();
            $nrha = $rows->where('organization', self::BENCHMARK_ORG);
            $all = $rows->where('organization', 'all');
            $cents = (int) $nrha->sum('earnings_cents');
            $out[] = [
                'name' => $name, 'role' => $role, 'milestone_millions' => $millions, 'milestone_year' => $year,
                'records' => $ids->count(), 'horse_id' => $ids->count() === 1 ? $ids->first() : null,
                'offspring' => (int) $all->sum('offspring'), 'nrha_starters' => (int) $nrha->sum('starters'), 'nrha_earners' => (int) $nrha->sum('earners'),
                'nrha_cents' => $cents, 'all_cents' => (int) $all->sum('earnings_cents'),
                'share_of_milestone' => round($cents / ($millions * 100_000_000), 3),
            ];
        }

        return $out;
    }

    /** The latest run's stats, or null before the first run. */
    public static function lastRun(): ?object
    {
        $run = DB::table('offspring_earnings_runs')->orderByDesc('id')->first();
        if ($run) {
            $run->stats = json_decode($run->stats, true);
        }

        return $run;
    }
}
