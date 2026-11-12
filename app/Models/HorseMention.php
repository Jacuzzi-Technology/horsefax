<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An article that names the horse: title and link to the publisher. */
class HorseMention extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_on' => 'date'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
}
