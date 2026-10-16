<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\User;
use App\Models\Verification;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Horsefax Verified: staff check one claim and record how and what it covers. Revoking keeps the verification row
 * (with the reason) and returns the claim to unverified or disputed. A horse's identity is verified the same way,
 * with the Horse itself as the subject.
 */
class Verify
{
    public function verify(Model $subject, User $staff, array $data): Verification
    {
        return DB::transaction(function () use ($subject, $staff, $data) {
            $horseId = $subject instanceof Horse ? $subject->id : $subject->horse_id;
            $this->revokeActive($subject, $staff, 'Replaced by a new verification');
            $v = Verification::create([
                'horse_id' => $horseId,
                'subject_type' => class_basename($subject),
                'subject_id' => $subject->getKey(),
                'method' => $data['method'],
                'scope' => $data['scope'],
                'verified_by' => $staff->id,
                'verified_at' => now(),
                'recheck_on' => $data['recheck_on'] ?? null,
                'evidence_document_id' => $data['evidence_document_id'] ?? null,
                'internal_note' => $data['note'] ?? null,
            ]);
            if ($subject instanceof Horse) {
                $before = $subject->identity_status;
                $subject->forceFill(['identity_status' => 'verified'])->save();
            } else {
                $before = $subject->status;
                $subject->forceFill(['status' => 'verified', 'verified_at' => $v->verified_at])->save();
            }
            Audit::record('verification.added', $subject, ['status' => $before], ['status' => 'verified', 'method' => $v->method, 'scope' => $v->scope, 'recheck_on' => $v->recheck_on?->toDateString()], $horseId, $staff->id);

            return $v;
        });
    }

    /** Withdraw verification: the claim goes back to unverified (or disputed). */
    public function withdraw(Model $subject, User $staff, string $reason, string $status = 'unverified'): void
    {
        DB::transaction(function () use ($subject, $staff, $reason, $status) {
            $horseId = $subject instanceof Horse ? $subject->id : $subject->horse_id;
            $this->revokeActive($subject, $staff, $reason);
            if ($subject instanceof Horse) {
                $before = $subject->identity_status;
                $subject->forceFill(['identity_status' => $status === 'disputed' ? 'under_review' : 'unverified'])->save();
            } else {
                $before = $subject->status;
                $subject->forceFill(['status' => $status, 'verified_at' => null])->save();
            }
            Audit::record('verification.withdrawn', $subject, ['status' => $before], ['status' => $status, 'reason' => $reason], $horseId, $staff->id);
        });
    }

    private function revokeActive(Model $subject, User $staff, string $reason): void
    {
        Verification::where('subject_type', class_basename($subject))->where('subject_id', $subject->getKey())->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $staff->id, 'revoked_reason' => $reason]);
    }
}
