<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\PedigreeEntry;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Support\Facades\DB;

/**
 * A horse described on a published page (a stallion directory, a foal nomination list): its identity, registration
 * number and pedigree. Finds the Horsefax record (registration number first, then a unique unowned record with the
 * same name and no conflicting foal year) or creates one (source "published", identity unverified, no owner). Fills
 * only what's missing and never touches a record someone owns. Every horse in the chart gets a record too.
 *
 * Optional extras (registration papers, Oct 20 2026): a pedigree line may be an array (name, registration_number,
 * foal_year, color, registry) so ancestors link by number; 'previous_names' are former registered names: a record still
 * under one of them is renamed to the official name and the old one kept as a previous name (ShowNameRule flags it
 * if the horse has results under the old name); a foal year already on the record upgrades to the full date.
 * 'breed' and 'breeder' (Oct 27 2026) fill those fields when blank. 'parentage_must_agree' (stud books from other
 * countries, where a same-named horse is often a different one): a record whose sire or dam differs from the page's is
 * not this horse; when every same-named record differs, a new record is made beside them.
 *
 * @return string created, updated, unchanged or skipped_owned / skipped_ambiguous
 */
class ImportPublishedHorse
{
    public function __invoke(array $h, string $sourceUrl): string
    {
        $system = BuildRecordsFromResults::system();

        return DB::transaction(function () use ($h, $sourceUrl, $system) {
            $horse = null;
            if ($h['registration_number']) {
                $ids = HorseIdentifier::where('kind', 'registration')->whereNull('superseded_by')
                    ->where('value_normalized', Record::normalizeIdentifier($h['registration_number']))
                    ->where(fn ($q) => $q->whereNull('registry')->orWhere('registry', $h['registry']))->pluck('horse_id')->unique();
                if ($ids->count() > 1) {
                    return 'skipped_ambiguous';
                }
                $horse = $ids->count() ? Horse::find($ids->first()) : null;
            }
            $formerly = null;
            foreach (array_merge([$h['name']], $h['previous_names'] ?? []) as $i => $name) {
                if ($horse) {
                    break;
                }
                $same = Horse::named($name)->get()
                    ->filter(fn ($x) => ! $h['foal_year'] || ! $x->birth_year || (int) $x->birth_year === (int) $h['foal_year']);
                if (! empty($h['parentage_must_agree'])) {
                    $same = $same->reject(fn ($x) => self::parentsDiffer($x, $h['pedigree']));
                }
                if ($same->count() > 1) {
                    return 'skipped_ambiguous';
                }
                $horse = $same->first();
                $formerly = $horse && $i > 0 ? $name : null;
            }
            if ($horse && $horse->activeStewards()->exists()) {
                return 'skipped_owned'; // the owner's record: they or staff decide what to add
            }

            $status = 'unchanged';
            if (! $horse) {
                $horse = new Horse(['registered_name' => $h['name'], 'birth_precision' => 'unknown']);
                $horse->horsefax_id = CreateHorse::newId();
                $horse->created_by = $system->id;
                $horse->forceFill(['source' => 'published', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
                Audit::record('horse.created_from_published', $horse, null, ['horsefax_id' => $horse->horsefax_id, 'registered_name' => $horse->registered_name, 'source_url' => $sourceUrl], $horse->id, $system->id);
                $status = 'created';
            }

            // Fill only what's missing.
            $fill = [];
            if (! $horse->sex && $h['sex']) {
                $fill['sex'] = $h['sex'];
            }
            if (! $horse->color && $h['color']) {
                $fill['color'] = $h['color'];
            }
            $sameYear = $h['birth_date'] && $horse->birth_precision === 'year' && (int) $horse->birth_year === (int) $h['foal_year'];
            if ((($horse->birth_precision ?? 'unknown') === 'unknown' && ($h['birth_date'] || $h['foal_year'])) || $sameYear) {
                $fill += $h['birth_date'] ? ['birth_precision' => 'day', 'birth_date' => $h['birth_date'], 'birth_year' => $h['foal_year']] : ['birth_precision' => 'year', 'birth_year' => $h['foal_year']];
            }
            if (! $horse->breed && ($h['breed'] ?? null)) {
                $fill['breed'] = $h['breed'];
            } elseif (! $horse->breed && $h['registry'] === 'AQHA') {
                $fill['breed'] = 'Quarter Horse';
            }
            if (! $horse->breeder && trim((string) ($h['breeder'] ?? '')) !== '') {
                $fill['breeder'] = trim($h['breeder']);
            }
            if ($fill) {
                $horse->forceFill($fill + ['listed' => true, 'public_summary' => true])->save();
                app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns($horse->only(array_merge(array_keys($fill), ['birth_precision', 'birth_year', 'birth_date']))), 'historical');
                $status = $status === 'created' ? 'created' : 'updated';
            }
            if ($h['registration_number'] && ! $horse->identifiers()->where('kind', 'registration')->where('value_normalized', Record::normalizeIdentifier($h['registration_number']))->exists()) {
                app(AddIdentifier::class)($horse, $system, ['kind' => 'registration', 'registry' => $h['registry'], 'value' => $h['registration_number'], 'visibility' => 'public', 'source_type' => 'historical']);
                $status = $status === 'created' ? 'created' : 'updated';
            }

            // Former registered names: the official name becomes the record's name, the old ones previous names.
            $names = array_values(array_filter(array_merge($h['previous_names'] ?? [], $formerly ? [$horse->registered_name] : []),
                fn ($n) => \App\Models\ShowRecord::key($n) !== \App\Models\ShowRecord::key($h['name'])));
            if ($formerly) {
                $old = $horse->registered_name;
                $horse->forceFill(['registered_name' => $h['name']])->save();
                app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns(['registered_name' => $h['name']]), 'historical');
                Audit::record('horse.renamed_from_published', $horse, ['registered_name' => $old], ['registered_name' => $h['name'], 'source_url' => $sourceUrl], $horse->id, $system->id);
                $status = $status === 'created' ? 'created' : 'updated';
            }
            $known = $horse->names()->pluck('name')->map(fn ($n) => \App\Models\ShowRecord::key($n))->all();
            foreach (array_unique($names) as $old) {
                if (! in_array(\App\Models\ShowRecord::key($old), $known, true)) {
                    \App\Models\HorseName::create(['horse_id' => $horse->id, 'name' => $old, 'kind' => 'previous', 'visibility' => 'shared', 'added_by' => $system->id]);
                    $known[] = \App\Models\ShowRecord::key($old);
                    if ($shown = ShowNameRule::shownAs($horse, $old)) {
                        ShowNameRule::flagRename($horse, $old, $h['name'], $shown);
                    }
                    $status = $status === 'created' ? 'created' : 'updated';
                }
            }

            // Pedigree lines the record doesn't have yet; then records for every horse in the chart.
            $have = $horse->pedigree()->pluck('position')->flip();
            $added = 0;
            foreach ($h['pedigree'] as $pos => $line) {
                $line = is_array($line) ? $line : ['name' => $line];
                if (! isset($have[$pos]) && trim((string) ($line['name'] ?? '')) !== '') {
                    PedigreeEntry::create(['horse_id' => $horse->id, 'position' => $pos, 'name' => trim($line['name']), 'visibility' => 'public',
                        'registry' => $line['registry'] ?? null, 'registration_number' => $line['registration_number'] ?? null,
                        'foal_year' => $line['foal_year'] ?? null, 'color' => $line['color'] ?? null,
                        'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'unverified']);
                    $added++;
                }
            }
            if ($added) {
                Audit::record('pedigree.seeded_from_published', $horse, null, ['lines' => $added, 'source_url' => $sourceUrl], $horse->id, $system->id);
                app(CreateAncestorRecords::class)($horse, $system);
                $status = $status === 'created' ? 'created' : 'updated';
            }

            return $status;
        });
    }

    /** The record names a sire or dam, and it isn't the one given. */
    private static function parentsDiffer(Horse $horse, array $pedigree): bool
    {
        $have = $horse->pedigree()->whereIn('position', ['s', 'd'])->pluck('name', 'position');
        foreach (['s', 'd'] as $pos) {
            $want = is_array($pedigree[$pos] ?? null) ? ($pedigree[$pos]['name'] ?? null) : ($pedigree[$pos] ?? null);
            if ($want && isset($have[$pos]) && \App\Models\ShowRecord::key($have[$pos]) !== \App\Models\ShowRecord::key($want)) {
                return true;
            }
        }

        return false;
    }
}
