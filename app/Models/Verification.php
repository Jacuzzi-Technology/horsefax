<?php

namespace App\Models;

use App\Support\Record;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Horsefax verification of one claim: who (shown as the team, never the person), when, how, and what it covers. */
class Verification extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'recheck_on' => 'date', 'revoked_at' => 'datetime'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function staff(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }

    public function methodLabel(): string
    {
        return Record::VERIFICATION_METHODS[$this->method] ?? $this->method;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
