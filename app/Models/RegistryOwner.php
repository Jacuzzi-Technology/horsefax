<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered owner as printed on a registry document: name, city/state and date acquired. Recorded, never shown
 * (Richard, Oct 21 2026). Not the same as a Horsefax steward: this is what the registry says, not who looks after the
 * record here.
 */
class RegistryOwner extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['name', 'city', 'state', 'country'];

    protected function casts(): array
    {
        return ['date_acquired' => 'date'];
    }

    public function record(): BelongsTo { return $this->belongsTo(RegistryRecord::class, 'registry_record_id'); }
    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
}
