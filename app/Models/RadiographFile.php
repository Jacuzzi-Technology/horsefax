<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiographFile extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function set(): BelongsTo { return $this->belongsTo(RadiographSet::class, 'radiograph_set_id'); }

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return \Illuminate\Support\Facades\Storage::disk(config('horsefax.radiographs_disk', 'local'));
    }
}
