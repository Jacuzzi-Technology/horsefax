<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff check of a person's identity. The ID image is deleted once the check is decided. */
class IdentityCheck extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'document_deleted_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
