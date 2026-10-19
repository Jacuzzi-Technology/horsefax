<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiographAccess extends Model
{
    use HasUuids;

    protected $table = 'radiograph_access';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function set(): BelongsTo { return $this->belongsTo(RadiographSet::class, 'radiograph_set_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
