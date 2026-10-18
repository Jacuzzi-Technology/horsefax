<?php

namespace App\Models\Concerns;

use App\Models\Verification;
use App\Support\Record;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Shared by every claim: identifiers, events, documents, pedigree lines and identity facts. */
trait IsClaim
{
    /** The verification currently standing behind this claim, if any. */
    public function verification(): HasOne
    {
        return $this->hasOne(Verification::class, 'subject_id')
            ->where('subject_type', class_basename(static::class))
            ->whereNull('revoked_at')
            ->orderByDesc('verified_at'); // only one active at a time (Verify revokes the previous one)
    }

    public function sourceLabel(): string
    {
        return Record::SOURCES[$this->source_type ?? 'owner_reported'] ?? $this->source_type;
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
