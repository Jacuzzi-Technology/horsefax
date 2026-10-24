<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\ReviewItem;
use App\Models\ShowRecord;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Richard, Oct 22 2026: most leading reining sires have their own pages that list their best offspring. NRHA results
 * carry no sire, so each name on such a page that matches a horse with results is a sire link the earnings totals were
 * missing (OffspringEarnings). Only the stallion's own page counts, and only horses it names as his own get.
 *
 * A name links on its own only when every check passes:
 *  - exactly one record has that name: registered name or alias (Horse::named, never IFCE), or the name its results
 *    were published under;
 *  - nobody owns the record;
 *  - the foal year fits: with a foal year from the page, it matches the record's and every result falls between age
 *    2 and 25 (LinkBarnNameRecords); without one, the first result comes at least 5 years after the sire's foal year;
 *  - the record has no sire line yet (or already names this sire), and no dam line that differs from the page's.
 * Otherwise the line is held for staff ("pedigree_conflict" review, approve to use it): a held line is stored disputed
 * and superseded (by the current line, or by itself when there is none), so it never counts or shows until approved.
 * Several records of the name: a held line on each (at most MAX_CANDIDATES). Names with no record are only counted:
 * no results under them, so no money to credit.
 */
class LinkPublishedOffspring
{
    public const MAX_CANDIDATES = 3;

    /** @param array{name:string, foal_year:?int, dam:?string, source_url:string} $o */
    public function __invoke(Horse $sire, array $o): string
    {
        $system = BuildRecordsFromResults::system();
        $name = \App\Harvest\NrchaHarvester::display(trim($o['name']));
        $fy = isset($o['foal_year']) && $o['foal_year'] ? (int) $o['foal_year'] : null;
        $dam = isset($o['dam']) && trim((string) $o['dam']) !== '' ? \App\Harvest\NrchaHarvester::display(trim($o['dam'])) : null;
        if (ShowRecord::key($name) === '' || ShowRecord::key($name) === ShowRecord::key((string) $sire->registered_name)) {
            return 'invalid';
        }

        $ids = Horse::named($name)->pluck('id')
            ->merge(ShowRecord::where('name_key', ShowRecord::key($name))->whereNotNull('horse_id')->pluck('horse_id'))
            ->unique()->values();
        $candidates = Horse::whereIn('id', $ids)->whereNull('merged_into_id')->where('id', '!=', $sire->id)->get();
        if ($candidates->isEmpty()) {
            return 'no_record';
        }
        if ($candidates->count() > 1) {
            if ($candidates->count() > self::MAX_CANDIDATES) {
                return 'too_many_records';
            }
            foreach ($candidates as $c) {
                $this->hold($c, $sire, $o, $name, 'more than one record has this name ('.$candidates->pluck('horsefax_id')->implode(', ').')', $system);
            }

            return 'held_ambiguous';
        }
        $foal = $candidates->first();
        if ($foal->activeStewards()->exists()) {
            return 'skipped_owned'; // the owner's record: they or staff decide
        }
        $current = $foal->pedigree()->where('position', 's')->first();
        if ($current && ($current->linked_horse_id === $sire->id || ShowRecord::key($current->name) === ShowRecord::key((string) $sire->registered_name))) {
            if (! $current->linked_horse_id) {
                $current->forceFill(['linked_horse_id' => $sire->id])->save();
            }

            return 'already_linked';
        }
        if ($current) {
            return $this->hold($foal, $sire, $o, $name, "the record's sire line says {$current->name}", $system, $current) ? 'held_conflict' : 'already_held';
        }
        $currentDam = $foal->pedigree()->where('position', 'd')->first();
        if ($dam && $currentDam && ShowRecord::key($currentDam->name) !== ShowRecord::key($dam)) {
            return $this->hold($foal, $sire, $o, $name, "the page gives the dam as {$dam}, the record says {$currentDam->name}", $system) ? 'held_conflict' : 'already_held';
        }
        if ($why = $this->implausible($foal, $sire, $fy)) {
            return $this->hold($foal, $sire, $o, $name, $why, $system) ? 'held_years' : 'already_held';
        }

        DB::transaction(function () use ($foal, $sire, $o, $fy, $dam, $currentDam, $system) {
            PedigreeEntry::create(['horse_id' => $foal->id, 'position' => 's', 'name' => $sire->registered_name, 'linked_horse_id' => $sire->id,
                'foal_year' => $sire->birth_year, 'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'unverified']);
            if ($dam && ! $currentDam) {
                PedigreeEntry::create(['horse_id' => $foal->id, 'position' => 'd', 'name' => $dam, 'visibility' => 'public',
                    'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'unverified']);
            }
            if ($fy && ($foal->birth_precision ?? 'unknown') === 'unknown') {
                $foal->forceFill(['birth_precision' => 'year', 'birth_year' => $fy])->save();
                app(RecordFacts::class)($foal, $system, RecordFacts::fromColumns($foal->only(['birth_precision', 'birth_year', 'birth_date'])), 'historical');
            }
            Audit::record('pedigree.seeded_from_stallion_page', $foal, null, ['sire' => $sire->registered_name, 'dam' => $dam, 'foal_year' => $fy, 'source_url' => $o['source_url']], $foal->id, $system->id);
            if ($dam && ! $currentDam) {
                app(CreateAncestorRecords::class)($foal, $system); // records for the dam
            }
        });

        return 'linked';
    }

    /** Why the foal year doesn't fit the record's results, or null. */
    private function implausible(Horse $foal, Horse $sire, ?int $fy): ?string
    {
        $years = ShowRecord::where('horse_id', $foal->id)->selectRaw('min(first_year) f, max(last_year) l')->first();
        if ($fy) {
            if ($foal->birth_year && (int) $foal->birth_year !== $fy) {
                return "the page gives foal year {$fy}, the record says {$foal->birth_year}";
            }
            if ($years?->f && $years->f < $fy + LinkBarnNameRecords::MIN_AGE) {
                return "results from {$years->f}, before age ".LinkBarnNameRecords::MIN_AGE." for a {$fy} foal";
            }
            if ($years?->l && $years->l > $fy + LinkBarnNameRecords::MAX_AGE) {
                return "results in {$years->l}, past age ".LinkBarnNameRecords::MAX_AGE." for a {$fy} foal";
            }

            return null;
        }
        if ($sire->birth_year && $foal->birth_year && $foal->birth_year < $sire->birth_year + 3) {
            return "foaled {$foal->birth_year}, too soon after the sire ({$sire->birth_year})";
        }
        if ($sire->birth_year && $years?->f && $years->f < $sire->birth_year + 5) {
            return "results from {$years->f}, too soon after the sire's foal year ({$sire->birth_year})";
        }

        return null;
    }

    /** A held sire line plus a staff review item. False when the same line is already held. */
    private function hold(Horse $foal, Horse $sire, array $o, string $name, string $why, $system, ?PedigreeEntry $current = null): bool
    {
        if ($foal->activeStewards()->exists()) {
            return false;
        }
        $held = PedigreeEntry::where('horse_id', $foal->id)->where('position', 's')->where('linked_horse_id', $sire->id)
            ->where('status', 'disputed')->whereNotNull('superseded_by')->exists();
        if ($held) {
            return false;
        }
        $current ??= $foal->pedigree()->where('position', 's')->first();

        return DB::transaction(function () use ($foal, $sire, $o, $name, $why, $system, $current) {
            $line = PedigreeEntry::create(['horse_id' => $foal->id, 'position' => 's', 'name' => $sire->registered_name, 'linked_horse_id' => $sire->id,
                'visibility' => 'public', 'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'disputed']);
            $line->forceFill(['superseded_by' => $current?->id ?? $line->id])->save();
            ReviewItem::create([
                'kind' => 'pedigree_conflict', 'horse_id' => $foal->id, 'submitted_by' => $system->id,
                'subject_type' => 'PedigreeEntry', 'subject_id' => $line->id,
                'statement' => $sire->registered_name.'\'s own page lists "'.$name.'" as his offspring ('.$o['source_url'].'), but it wasn\'t linked automatically: '.$why.'. '
                    .($current ? 'Approve to use "'.$sire->registered_name.'" as the sire instead of "'.$current->name.'"; reject to keep "'.$current->name.'".' : 'Approve to add "'.$sire->registered_name.'" as the sire; reject if this record is a different horse.'),
                'details' => ['current_entry_id' => $current?->id, 'source_url' => $o['source_url'], 'from' => 'stallion_page', 'reason' => $why],
            ]);
            Audit::record('pedigree.held_from_stallion_page', $line, null, ['sire' => $sire->registered_name, 'reason' => $why, 'source_url' => $o['source_url']], $foal->id, $system->id);

            return true;
        });
    }
}
