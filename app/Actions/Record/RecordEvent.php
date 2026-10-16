<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Adds a history entry, or corrects one. A correction is a new entry that supersedes the old one; the old entry stays
 * in the record (and the audit log) marked superseded.
 */
class RecordEvent
{
    public function __invoke(Horse $horse, User $user, array $data, ?HorseEvent $corrects = null): HorseEvent
    {
        return DB::transaction(function () use ($horse, $user, $data, $corrects) {
            $event = HorseEvent::create([
                'horse_id' => $horse->id,
                'category' => $data['category'],
                'type' => $data['type'] ?? null,
                'title' => $data['title'],
                'result' => $data['result'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'organization' => $data['organization'] ?? null,
                'event_name' => $data['event_name'] ?? null,
                'class_name' => $data['class_name'] ?? null,
                'placing' => $data['placing'] ?? null,
                'score' => $data['score'] ?? null,
                'points' => $data['points'] ?? null,
                'earnings_cents' => isset($data['earnings']) && $data['earnings'] !== '' ? (int) round($data['earnings'] * 100) : null,
                'rider' => $data['rider'] ?? null,
                'discipline' => $data['discipline'] ?? null,
                'division' => $data['division'] ?? null,
                'round' => $data['round'] ?? null,
                'amount_cents' => isset($data['amount']) && $data['amount'] !== '' ? (int) round($data['amount'] * 100) : null,
                'amount_visibility' => $data['amount_visibility'] ?? 'private',
                'sale_venue' => $data['sale_venue'] ?? null,
                'occurred_on' => $data['occurred_on'] ?? null,
                'date_precision' => $data['date_precision'] ?? (empty($data['occurred_on']) ? 'unknown' : 'day'),
                'details' => $data['details'] ?? null,
                'provider' => $data['provider'] ?? null,
                'contributor_id' => $user->id,
                'contributor_role' => self::roleOf($horse, $user),
                'status' => ! empty($data['document_id']) ? 'document_supplied' : 'self_reported',
                'visibility' => $data['visibility'],
                'document_id' => $data['document_id'] ?? null,
                'supersedes' => $corrects?->id,
            ]);

            if ($corrects) {
                $corrects->forceFill(['superseded_by' => $event->id])->save();
                Audit::record('event.corrected', $event, $corrects->only(['category', 'title', 'occurred_on', 'details', 'provider', 'visibility']), $event->only(['category', 'title', 'occurred_on', 'details', 'provider', 'visibility']));
            } else {
                Audit::record('event.added', $event, null, $event->only(['category', 'type', 'title', 'occurred_on', 'details', 'provider', 'visibility', 'status', 'result', 'placing', 'organization', 'event_name', 'class_name']));
            }

            return $event;
        });
    }

    /** The capacity someone adds to this record in: their stewardship role, their profession, or staff. */
    public static function roleOf(Horse $horse, User $user): string
    {
        if ($role = $horse->activeStewards()->where('user_id', $user->id)->value('role')) {
            return $role;
        }
        if ($contributor = $horse->activeContributor($user)) {
            return $contributor->role;
        }

        return $user->isStaff() ? 'staff' : 'owner';
    }
}
