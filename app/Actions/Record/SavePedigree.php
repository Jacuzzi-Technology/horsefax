<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\PedigreeEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Support\Facades\DB;

/**
 * Saves the pedigree chart. A changed line supersedes the old one (kept for history). Each line is linked to an
 * existing Horsefax record when its registration number matches, or its name matches exactly one registered name;
 * those links are what list a horse's offspring.
 */
class SavePedigree
{
    /** @param array<string, array{name?: ?string, registry?: ?string, registration_number?: ?string, foal_year?: ?int, color?: ?string}> $lines */
    public function __invoke(Horse $horse, User $user, array $lines, string $visibility): int
    {
        return DB::transaction(function () use ($horse, $user, $lines, $visibility) {
            $changed = 0;
            $current = $horse->pedigree()->get()->keyBy('position');
            foreach (array_keys(Record::pedigreePositions()) as $pos) {
                $line = $lines[$pos] ?? [];
                $name = trim((string) ($line['name'] ?? ''));
                $old = $current->get($pos);
                $new = [
                    'name' => $name,
                    'registry' => $line['registry'] ?? null ?: null,
                    'registration_number' => trim((string) ($line['registration_number'] ?? '')) ?: null,
                    'foal_year' => $line['foal_year'] ?? null ?: null,
                    'color' => trim((string) ($line['color'] ?? '')) ?: null,
                ];
                $same = $old && $old->name === $new['name'] && $old->registry === $new['registry'] && $old->registration_number === $new['registration_number']
                    && (int) $old->foal_year === (int) $new['foal_year'] && $old->color === $new['color'];
                if ($same) {
                    if ($old->visibility !== $visibility) {
                        $old->forceFill(['visibility' => $visibility])->save();
                    }
                    continue;
                }
                if ($name === '' && ! $old) {
                    continue;
                }
                $entry = null;
                if ($name !== '') {
                    $entry = PedigreeEntry::create($new + [
                        'horse_id' => $horse->id, 'position' => $pos, 'visibility' => $visibility, 'added_by' => $user->id,
                        'source_type' => \App\Support\Record::sourceForRole(RecordEvent::roleOf($horse, $user)),
                        'linked_horse_id' => $this->chosenHorse($horse, $line['linked_horse_id'] ?? null) ?? $this->findHorse($horse, $new),
                    ]);
                }
                if ($old) {
                    $old->forceFill(['superseded_by' => $entry?->id ?? $old->id])->save();
                }
                Audit::record('pedigree.saved', $entry ?? $old, $old?->only(['position', 'name', 'registry', 'registration_number']), $entry?->only(['position', 'name', 'registry', 'registration_number', 'linked_horse_id']), $horse->id);
                $changed++;
            }

            return $changed;
        });
    }

    /** A record picked in the autofill, if it exists, isn't this horse, and is listed or public. */
    private function chosenHorse(Horse $horse, ?string $id): ?string
    {
        if (! $id || $id === $horse->id) {
            return null;
        }

        return Horse::whereKey($id)->where(fn ($w) => $w->where('listed', true)->orWhere('public_summary', true))->value('id');
    }

    private function findHorse(Horse $horse, array $line): ?string
    {
        if ($line['registration_number']) {
            $id = HorseIdentifier::where('kind', 'registration')->where('value_normalized', Record::normalizeIdentifier($line['registration_number']))
                ->whereNull('superseded_by')->where('in_conflict', false)->where('horse_id', '!=', $horse->id)->pluck('horse_id')->unique();
            if ($id->count() === 1) {
                return $id->first();
            }
        }
        $byName = Horse::named($line['name'])->where('id', '!=', $horse->id)->pluck('id');

        return $byName->count() === 1 ? $byName->first() : null;
    }
}
