<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\ReviewItem;
use App\Models\User;
use App\Support\Audit;
use App\Support\Record;

/**
 * Registration numbers and microchips must point at one horse. A number already on another record is still saved,
 * but both are marked in conflict, the new record goes under review and staff get a review item. Nothing is
 * overwritten or merged automatically.
 */
class AddIdentifier
{
    public function __invoke(Horse $horse, User $user, array $data): HorseIdentifier
    {
        $kind = $data['kind'];
        $normalized = Record::normalizeIdentifier($data['value']);

        $identifier = HorseIdentifier::create([
            'horse_id' => $horse->id,
            'kind' => $kind,
            'registry' => $data['registry'] ?? null,
            'value' => trim($data['value']),
            'value_normalized' => $normalized,
            'status' => 'unverified',
            'source_type' => $data['source_type'] ?? \App\Support\Record::sourceForRole(RecordEvent::roleOf($horse, $user)),
            'visibility' => $data['visibility'] ?? ($kind === 'microchip' ? 'private' : 'shared'),
            'document_id' => $data['document_id'] ?? null,
            'added_by' => $user->id,
        ]);
        Audit::record('identifier.added', $identifier, null, $identifier->only(['kind', 'registry', 'status', 'visibility']) + ['value' => $kind === 'microchip' ? Record::mask($identifier->value) : $identifier->value]);

        $clashes = HorseIdentifier::query()
            ->where('kind', $kind)
            ->where('value_normalized', $normalized)
            ->where('horse_id', '!=', $horse->id)
            ->whereNull('superseded_by')
            ->when($kind === 'registration' && ! empty($data['registry']), fn ($q) => $q->where(fn ($q) => $q->whereNull('registry')->orWhere('registry', $data['registry'])))
            ->get();

        if ($clashes->isNotEmpty()) {
            $identifier->update(['in_conflict' => true]);
            HorseIdentifier::whereIn('id', $clashes->pluck('id'))->update(['in_conflict' => true]);
            $horse->forceFill(['identity_status' => 'under_review'])->save();

            ReviewItem::create([
                'kind' => 'identifier_conflict',
                'horse_id' => $horse->id,
                'submitted_by' => $user->id,
                'subject_type' => 'HorseIdentifier',
                'subject_id' => $identifier->id,
                'statement' => 'This '.strtolower($identifier->label()).' is already on another Horsefax record.',
                'details' => ['other_identifier_ids' => $clashes->pluck('id')->all(), 'other_horse_ids' => $clashes->pluck('horse_id')->unique()->values()->all()],
            ]);
            Audit::record('identifier.conflict', $identifier, null, ['other_horse_ids' => $clashes->pluck('horse_id')->unique()->values()->all()]);
        }

        return $identifier;
    }
}
