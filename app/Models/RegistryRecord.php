<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Details from one registry document (AQHA Records report, certificate front or back). Recorded, never shown
 * (Richard, Oct 21 2026): no page, report, share link, export or API renders it. See the create_registry_records
 * migration for what it holds and why.
 */
class RegistryRecord extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['data', 'raw_text'];

    protected function casts(): array
    {
        return ['data' => 'array', 'document_date' => 'date'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function owners(): HasMany { return $this->hasMany(RegistryOwner::class); }
}
