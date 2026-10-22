<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A search-backed suggested answer for one staff review item. Staff accept or reject it. */
class ReviewSuggestion extends Model
{
    protected $guarded = ['id'];

    public const LABELS = [
        'same_horse' => 'Same horse',
        'different_horses' => 'Different horses',
        'lot_belongs' => 'This sale belongs to',
        'unsure' => 'No answer from the search',
    ];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'decided_at' => 'datetime', 'cost_cents' => 'float'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }

    public function actionable(): bool
    {
        return $this->status === 'pending' && $this->suggestion !== 'unsure';
    }
}
