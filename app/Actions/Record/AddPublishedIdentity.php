<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\User;
use App\Support\Audit;

/**
 * Once a published result or sale lot is attached to a record, the identity it printed (registration number, foal
 * year, sire / dam / dam's sire, a fuller pedigree from a catalog) fills what the record is missing (pedigree positions
 * included, Oct 27 2026): historical and
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
        if (preg_match('/\d/', $reg) && ! $horse->identifiers()->where('kind', 'registration')->whereNull('superseded_by')->exists()) {
            app(AddIdentifier::class)($horse, $system, ['kind' => 'registration', 'registry' => $id['registry'] ?? null, 'value' => $reg, 'visibility' => 'public', 'source_type' => 'historical']);
            $filled[] = 'registration';
        }
        $year = $id['foal_year'] ?? null;
        if ($year && (! $horse->birth_year || $horse->birth_precision === 'unknown')) {
            $horse->forceFill(['birth_year' => $year, 'birth_precision' => 'year'])->save();
            app(RecordFacts::class)($horse, $system, ['birth' => [(string) $year, ['precision' => 'year']]], 'historical');
            $filled[] = 'foal_year';
        }
        // Pedigree: add the positions the record doesn't have (a catalog's 3 generations onto a record with only sire and
        // dam), never over a line that's there, and never under a line that disagrees (a different sire's ancestors
        // aren't this horse's).
        $pedigree = array_filter($id['pedigree'] ?? [], fn ($v, $p) => is_string($v) && trim($v) !== '' && preg_match('/^[sd]{1,5}$/', (string) $p), ARRAY_FILTER_USE_BOTH);
        if ($pedigree) {
            $have = $horse->pedigree()->get()->keyBy('position');
            $added = 0;
            foreach ($pedigree as $pos => $name) {
                if (isset($have[$pos])) {
                    continue;
                }
                for ($i = 1; $i < strlen($pos); $i++) {
                    $up = substr($pos, 0, $i);
                    if (isset($have[$up]) && isset($pedigree[$up]) && \App\Models\ShowRecord::key($have[$up]->name) !== \App\Models\ShowRecord::key($pedigree[$up])) {
                        continue 2;
                    }
                }
                PedigreeEntry::create(['horse_id' => $horse->id, 'position' => $pos, 'name' => trim($name), 'visibility' => 'public',
                    'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'unverified']);
                $added++;
            }
            if ($added) {
                app(CreateAncestorRecords::class)($horse, $system);
                $filled[] = 'pedigree';
            }
        }
        if ($filled) {
            Audit::record('identity.filled_from_published', $horse, null, ['fields' => $filled, 'from' => $from], $horse->id, $system->id);
        }

        return $filled;
    }
}
