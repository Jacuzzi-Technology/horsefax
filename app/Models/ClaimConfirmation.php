<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The seller named in an ownership claim, asked to confirm or deny the sale. */
class ClaimConfirmation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function reviewItem(): BelongsTo { return $this->belongsTo(ReviewItem::class); }
}
