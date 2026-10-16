<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedigreeEntry extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function linkedHorse(): BelongsTo { return $this->belongsTo(Horse::class, 'linked_horse_id'); }
}
