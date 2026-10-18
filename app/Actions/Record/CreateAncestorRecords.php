<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\PedigreeEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\Record;

/**
 * Every horse named in a pedigree becomes a Horsefax record (Richard, Oct 16 2026), the way allbreedpedigree builds
 * its database. A pedigree line is linked to an existing record when one matches (registration number, or a unique
 * exact registered name); otherwise a new record is created: identity unverified, source "pedigree", no owner until
 * someone claims it. Each ancestor record also gets its own sire/dam lines from the chart, but only while it has no
 * owner: a chart never edits a record someone looks after. Records from a private chart are not listed in search.
 */
class CreateAncestorRecords
{
    public function __invoke(Horse $horse, User $user): int
    {
        $created = 0;
        $entries = $horse->pedigree()->get()->keyBy('position');
        $listed = $entries->contains(fn ($e) => $e->visibility === 'public');

        // Pass 1: link or create a record for every line.
        foreach ($entries->sortBy(fn ($e) => strlen($e->position)) as $pos => $entry) {
            if ($entry->linked_horse_id) {
                continue;
            }
            $match = $this->match($entry, $horse);
            if ($match === false) {
                continue; // several possible matches: leave it for a person to sort out
            }
            if ($match === null) {
                $match = $this->create($entry, $horse, $user, $listed);
                $created++;
            }
            $entry->forceFill(['linked_horse_id' => $match->id])->save();
            Audit::record('pedigree.linked', $entry, null, ['position' => $pos, 'linked_horse_id' => $match->id], $horse->id);
        }

        // Pass 2: give unowned ancestor records the lines behind them.
        $entries = $horse->pedigree()->get()->keyBy('position');
        foreach ($entries as $pos => $entry) {
            $ancestor = $entry->linked_horse_id ? Horse::find($entry->linked_horse_id) : null;
            if (! $ancestor || $ancestor->id === $horse->id || $ancestor->activeStewards()->exists()) {
                continue;
            }
            $existing = $ancestor->pedigree()->pluck('position')->flip();
            foreach ($entries as $p2 => $behind) {
                if (! str_starts_with($p2, $pos) || $p2 === $pos) {
                    continue;
                }
                $rel = substr($p2, strlen($pos));
                if (isset($existing[$rel])) {
                    continue; // never overwrite what's already there
                }
                $line = PedigreeEntry::create([
                    'horse_id' => $ancestor->id, 'position' => $rel, 'name' => $behind->name, 'registry' => $behind->registry,
                    'registration_number' => $behind->registration_number, 'foal_year' => $behind->foal_year, 'color' => $behind->color,
                    'linked_horse_id' => $behind->linked_horse_id, 'visibility' => $listed ? 'public' : 'private', 'added_by' => $user->id,
                    'source_type' => 'historical',
                ]);
                Audit::record('pedigree.saved', $line, null, $line->only(['position', 'name', 'linked_horse_id']) + ['from_chart_of' => $horse->id], $ancestor->id);
            }
        }

        return $created;
    }

    /** @return Horse|null|false  a match, null for none, false when it's ambiguous */
    private function match(PedigreeEntry $entry, Horse $horse): Horse|null|false
    {
        if ($entry->registration_number) {
            $ids = HorseIdentifier::where('kind', 'registration')->whereNull('superseded_by')
                ->where('value_normalized', Record::normalizeIdentifier($entry->registration_number))
                ->when($entry->registry, fn ($q) => $q->where(fn ($w) => $w->whereNull('registry')->orWhere('registry', $entry->registry)))
                ->where('horse_id', '!=', $horse->id)->pluck('horse_id')->unique();
            if ($ids->count() === 1) {
                return Horse::find($ids->first());
            }
            if ($ids->count() > 1) {
                return false;
            }
        }
        $byName = Horse::named($entry->name)->where('id', '!=', $horse->id)->get();
        if ($byName->count() > 1) {
            // Same name, different horses (it happens): only a matching foaling year settles it.
            $byYear = $entry->foal_year ? $byName->where('birth_year', $entry->foal_year) : collect();

            return $byYear->count() === 1 ? $byYear->first() : false;
        }

        return $byName->first();
    }

    private function create(PedigreeEntry $entry, Horse $horse, User $user, bool $listed): Horse
    {
        $ancestor = new Horse([
            'registered_name' => trim($entry->name),
            'sex' => str_ends_with($entry->position, 's') ? 'stallion' : 'mare',
            'birth_precision' => $entry->foal_year ? 'year' : 'unknown',
            'birth_year' => $entry->foal_year,
            'color' => $entry->color,
        ]);
        $ancestor->horsefax_id = CreateHorse::newId();
        $ancestor->created_by = $user->id;
        $ancestor->forceFill(['source' => 'pedigree', 'source_horse_id' => $horse->id, 'listed' => $listed, 'identity_status' => 'unverified'])->save();
        app(RecordFacts::class)($ancestor, $user, RecordFacts::fromColumns($ancestor->only(['registered_name', 'sex', 'birth_precision', 'birth_year', 'birth_date', 'color'])), 'historical');
        Audit::record('horse.created_from_pedigree', $ancestor, null, $ancestor->only(['horsefax_id', 'registered_name', 'sex', 'birth_year']) + ['from_chart_of' => $horse->id]);

        if ($entry->registration_number) {
            app(AddIdentifier::class)($ancestor, $user, [
                'kind' => 'registration', 'registry' => $entry->registry, 'value' => $entry->registration_number,
                'visibility' => $listed ? 'public' : 'private', 'source_type' => 'historical',
            ]);
        }

        return $ancestor;
    }
}
