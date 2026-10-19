<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiographView extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
