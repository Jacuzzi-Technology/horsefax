<?php

namespace App\Actions\Record;

use App\Models\HorseEvent;
use App\Models\HorseIdentifier;
use App\Models\ReviewItem;
use App\Models\Stewardship;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Staff decisions on the review queue. Source evidence is never deleted: approving or rejecting only changes statuses
 * and stewardships, and every decision is logged with the staff member's note.
 */
class Reviews
{
    public function approve(ReviewItem $item, User $staff, string $note, array $options = []): void
    {
        DB::transaction(function () use ($item, $staff, $note, $options) {
            match ($item->kind) {
                'ownership_claim' => $this->approveClaim($item, $options),
                'identifier_conflict' => $this->clearConflict($item, $options),
                'dispute' => $this->upholdDispute($item),
                default => null,
            };
            $this->close($item, $staff, 'approved', $note);
        });
    }

    public function reject(ReviewItem $item, User $staff, string $note): void
    {
        DB::transaction(fn () => $this->close($item, $staff, 'rejected', $note));
    }

    private function approveClaim(ReviewItem $item, array $options): void
    {
        $horse = $item->horse;
        if (! empty($options['end_current_owner'])) {
            foreach ($horse->activeStewards()->where('role', 'owner')->where('user_id', '!=', $item->submitted_by)->get() as $old) {
                $old->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_reason' => 'Ownership claim approved by Horsefax staff'])->save();
                Audit::record('stewardship.ended', $old, null, ['reason' => $old->ended_reason]);
            }
        }
        $existing = $horse->activeStewards()->where('user_id', $item->submitted_by)->first();
        if ($existing) {
            $existing->forceFill(['role' => 'owner', 'status' => 'verified'])->save();
        } else {
            $existing = Stewardship::create(['horse_id' => $horse->id, 'user_id' => $item->submitted_by, 'role' => 'owner', 'status' => 'verified', 'started_on' => now()->toDateString()]);
        }
        Audit::record('stewardship.verified', $existing, null, ['user_id' => $item->submitted_by, 'via' => 'ownership_claim']);
    }

    private function clearConflict(ReviewItem $item, array $options): void
    {
        $identifier = HorseIdentifier::find($item->subject_id);
        if (! $identifier) {
            return;
        }
        $others = HorseIdentifier::whereIn('id', $item->details['other_identifier_ids'] ?? [])->get();
        if (($options['outcome'] ?? 'both_stand') === 'new_is_wrong') {
            $identifier->forceFill(['status' => 'disputed', 'in_conflict' => false])->save();
        } else {
            $identifier->forceFill(['in_conflict' => false])->save();
        }
        foreach ($others as $other) {
            $other->forceFill(['in_conflict' => false])->save();
        }
        $identifier->horse->forceFill(['identity_status' => 'unverified'])->save();
        Audit::record('identifier.conflict_resolved', $identifier, null, ['outcome' => $options['outcome'] ?? 'both_stand']);
    }

    private function upholdDispute(ReviewItem $item): void
    {
        $subject = match ($item->subject_type) {
            'HorseEvent' => HorseEvent::find($item->subject_id),
            'HorseIdentifier' => HorseIdentifier::find($item->subject_id),
            default => null,
        };
        if ($subject) {
            $before = $subject->status;
            $subject->forceFill(['status' => 'disputed'])->save();
            Audit::record('fact.disputed', $subject, ['status' => $before], ['status' => 'disputed']);
        }
    }

    private function close(ReviewItem $item, User $staff, string $status, string $note): void
    {
        $item->forceFill(['status' => $status, 'resolution_note' => $note, 'resolved_by' => $staff->id, 'resolved_at' => now()])->save();
        Audit::record('review.'.$status, $item, null, ['kind' => $item->kind, 'note' => $note], $item->horse_id, $staff->id);
    }
}
