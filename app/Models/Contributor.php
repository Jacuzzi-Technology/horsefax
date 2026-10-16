<?php

namespace App\Models;

use App\Support\Record;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A professional (vet, farrier, trainer, breeder) invited by the owner to add to one horse's record. */
class Contributor extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }

    public function roleLabel(): string
    {
        return Record::CONTRIBUTOR_ROLES[$this->role] ?? ucfirst($this->role);
    }

    public function statusLabel(): string
    {
        return ['pending' => 'Invited', 'active' => 'Active', 'declined' => 'Declined', 'revoked' => 'Access removed'][$this->status] ?? $this->status;
    }
}
