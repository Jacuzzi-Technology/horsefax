<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\User;
use App\Support\Audit;

/**
 * Once a published result or sale lot is attached to a record, the identity it printed (registration number, foal
 * year, sire / dam / dam's sire, a fuller pedigree from a catalog) fills what the record is missing: historical and
 * unverified, never over anything already there, and only on records nobody looks after yet (an owner's record is
 * theirs to edit). A registration number that's already on another record goes to staff as a conflict
 * (AddIdentifier). Returns the fields filled.
 */
class AddPublishedIdentity
{
    /** @param array{registry?:?string, registration_number?:?string, foal_year?:?int, pedigree?:array<string,?string>} $id */
    public function __invoke(Horse $horse, User $system, array $id, string $from): array
    {
        if ($horse->activeStewards()->exists()) {
            return [];
        }
        $filled = [];
        $reg = trim((string) ($id['registration_number'] ?? ''));
        if ($reg !== '' && ! $horse->identifiers()->where('kind', 'registration')->whereNull('superseded_by')->exists()) {
            app(AddIdentifier::class)($horse, $system, ['kind' => 'registration', 'registry' => $id['registry'] ?? null, 'value' => $reg, 'visibility' => 'public', 'source_type' => 'historical']);
            $filled[] = 'registration';
        }
        $year = $id['foal_year'] ?? null;
        if ($year && (! $horse->birth_year || $horse->birth_precision === 'unknown')) {
            $horse->forceFill(['birth_year' => $year, 'birth_precision' => 'year'])->save();
            app(RecordFacts::class)($horse, $system, ['birth' => [(string) $year, ['precision' => 'year']]], 'historical');
            $filled[] = 'foal_year';
        }
        $pedigree = array_filter($id['pedigree'] ?? [], fn ($v) => is_string($v) && trim($v) !== '');
        if ($pedigree && ! $horse->pedigree()->exists()) {
            foreach ($pedigree as $pos => $name) {
                PedigreeEntry::create(['horse_id' => $horse->id, 'position' => $pos, 'name' => trim($name), 'visibility' => 'public',
                    'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'unverified']);
            }
            app(CreateAncestorRecords::class)($horse, $system);
            $filled[] = 'pedigree';
        }
        if ($filled) {
            Audit::record('identity.filled_from_published', $horse, null, ['fields' => $filled, 'from' => $from], $horse->id, $system->id);
        }

        return $filled;
    }
}
