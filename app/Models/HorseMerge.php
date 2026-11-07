<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorseMerge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['log' => 'array', 'undone_at' => 'datetime'];
    }

    public function kept(): BelongsTo { return $this->belongsTo(Horse::class, 'kept_horse_id'); }
    public function gone(): BelongsTo { return $this->belongsTo(Horse::class, 'gone_horse_id'); }
}
