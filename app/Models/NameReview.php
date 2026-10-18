<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NameReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function a(): BelongsTo { return $this->belongsTo(Horse::class, 'horse_a_id'); }
    public function b(): BelongsTo { return $this->belongsTo(Horse::class, 'horse_b_id'); }
}
