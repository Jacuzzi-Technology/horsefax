<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time code a seller or sale company hands the buyer; redeeming it verifies the buyer as owner. */
class ClaimCode extends Model
{
    use HasUuids;

    /** No 0/O, 1/I/L, 2/Z, 5/S, 8/B: codes are read off paper. */
    public const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'redeemed_at' => 'datetime', 'revoked_at' => 'datetime', 'code_encrypted' => 'encrypted'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function redeemer(): BelongsTo { return $this->belongsTo(User::class, 'redeemed_by'); }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    public function plain(): string
    {
        return $this->code_encrypted;
    }

    public function usable(): bool
    {
        return ! $this->redeemed_at && ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function claimUrl(): string
    {
        return route('claim-code.form', ['code' => $this->plain()]);
    }
}
