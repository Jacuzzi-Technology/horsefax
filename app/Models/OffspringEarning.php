<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sire's or dam's offspring totals, per organization. Computed nightly; staff only until Richard says otherwise. */
class OffspringEarning extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['computed_at' => 'datetime'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
}
