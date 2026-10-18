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
            if (! $horse) {
                $same = Horse::named($h['name'])->get()
                    ->filter(fn ($x) => ! $h['foal_year'] || ! $x->birth_year || (int) $x->birth_year === (int) $h['foal_year']);
                if ($same->count() > 1) {
                    return 'skipped_ambiguous';
                }
                $horse = $same->first();
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
            if (($horse->birth_precision ?? 'unknown') === 'unknown' && ($h['birth_date'] || $h['foal_year'])) {
                $fill += $h['birth_date'] ? ['birth_precision' => 'day', 'birth_date' => $h['birth_date'], 'birth_year' => $h['foal_year']] : ['birth_precision' => 'year', 'birth_year' => $h['foal_year']];
            }
            if (! $horse->breed && $h['registry'] === 'AQHA') {
                $fill['breed'] = 'Quarter Horse';
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

            // Pedigree lines the record doesn't have yet; then records for every horse in the chart.
            $have = $horse->pedigree()->pluck('position')->flip();
            $added = 0;
            foreach ($h['pedigree'] as $pos => $name) {
                if (! isset($have[$pos]) && trim($name) !== '') {
                    PedigreeEntry::create(['horse_id' => $horse->id, 'position' => $pos, 'name' => trim($name), 'visibility' => 'public',
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
}
