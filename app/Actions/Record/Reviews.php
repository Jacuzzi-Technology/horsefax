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
                'dispute' => $this->upholdDispute($item, $staff),
                'results_match' => ($rec = \App\Models\ShowRecord::find($item->subject_id)) && ! $rec->horse_id ? app(AttachShowResults::class)($rec, $item->horse, $staff) : null,
                'fact_conflict' => ($fact = \App\Models\HorseFact::find($item->subject_id)) ? RecordFacts::accept($fact) : null,
                'pedigree_conflict' => $this->usePedigreeLine($item),
                // Staff confirmed results under a former name are this horse's; a flagged rename just stands.
                ShowNameRule::KIND => ($item->details['case'] ?? null) === 'former_name_results' && ($rec = \App\Models\ShowRecord::find($item->subject_id))
                    && (! $rec->horse_id || $rec->horse_id === $item->horse_id) ? app(AttachShowResults::class)($rec, $item->horse, $staff, true, true) : null,
                'visibility_change' => RequestToHide::apply($item),
                'identity_check' => $this->decideIdentity($item, $staff, 'verified', $note, $options['method'] ?? 'id_document'),
                default => null,
            };
            $this->close($item, $staff, 'approved', $note);
        });
    }

    public function reject(ReviewItem $item, User $staff, string $note): void
    {
        DB::transaction(function () use ($item, $staff, $note) {
            if ($item->kind === 'identity_check') {
                $this->decideIdentity($item, $staff, 'rejected', $note, null);
            }
            $this->close($item, $staff, 'rejected', $note);
        });
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

    /** A merge conflict on a pedigree line: staff chose the other line. The current one is kept behind it, never deleted. */
    private function usePedigreeLine(ReviewItem $item): void
    {
        $line = \App\Models\PedigreeEntry::find($item->subject_id);
        $current = \App\Models\PedigreeEntry::find($item->details['current_entry_id'] ?? null);
        if (! $line) {
            return;
        }
        $current?->forceFill(['superseded_by' => $line->id, 'status' => 'disputed'])->save();
        $line->forceFill(['superseded_by' => null, 'status' => 'unverified'])->save();
        Audit::record('pedigree.conflict_resolved', $line, ['name' => $current?->name], ['name' => $line->name, 'position' => $line->position], $line->horse_id);
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

    private function upholdDispute(ReviewItem $item, User $staff): void
    {
        $subject = match ($item->subject_type) {
            'HorseEvent' => HorseEvent::find($item->subject_id),
            'HorseIdentifier' => HorseIdentifier::find($item->subject_id),
            'Document' => \App\Models\Document::find($item->subject_id),
            'PedigreeEntry' => \App\Models\PedigreeEntry::find($item->subject_id),
            'HorseFact' => \App\Models\HorseFact::find($item->subject_id),
            default => null,
        };
        if ($subject) {
            // An upheld dispute also withdraws any Horsefax verification standing behind the item.
            app(Verify::class)->withdraw($subject, $staff, 'Dispute upheld: '.\Illuminate\Support\Str::limit((string) $item->statement, 200), 'disputed');
        }
    }

    /** Records the identity decision on the person and deletes the ID image: only the result, method and date are kept. */
    private function decideIdentity(ReviewItem $item, User $staff, string $status, string $note, ?string $method): void
    {
        $check = \App\Models\IdentityCheck::find($item->subject_id);
        if (! $check) {
            return;
        }
        if ($check->document_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($check->document_path);
        }
        $check->forceFill(['status' => $status, 'method' => $method ?? $check->method, 'note' => $note, 'decided_by' => $staff->id, 'decided_at' => now(),
            'document_path' => null, 'document_deleted_at' => now()])->save();
        if ($status === 'verified') {
            $check->user->forceFill(['identity_verified_at' => now(), 'identity_verified_name' => $check->legal_name])->save();
        }
        Audit::record('identity.'.$status, $check, null, ['method' => $check->method], null, $staff->id);
    }

    private function close(ReviewItem $item, User $staff, string $status, string $note): void
    {
        $item->forceFill(['status' => $status, 'resolution_note' => $note, 'resolved_by' => $staff->id, 'resolved_at' => now()])->save();
        Audit::record('review.'.$status, $item, null, ['kind' => $item->kind, 'note' => $note], $item->horse_id, $staff->id);
    }
}
