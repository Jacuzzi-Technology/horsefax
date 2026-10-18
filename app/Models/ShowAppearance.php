<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowAppearance extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function showRecord(): BelongsTo { return $this->belongsTo(ShowRecord::class); }
    public function source(): BelongsTo { return $this->belongsTo(DataSource::class, 'data_source_id'); }
}
