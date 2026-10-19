<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewItem extends Model
{
    use HasUuids;

    public const KINDS = [
        'identity_check' => 'Identity check',
        'ownership_claim' => 'Ownership claim',
        'identifier_conflict' => 'Identifier conflict',
        'dispute' => 'Correction / dispute',
        'fact_conflict' => 'Conflicting identity value',
        'results_match' => 'Show results to attach',
        'pedigree_conflict' => 'Conflicting pedigree line (from a merge)',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'resolved_at' => 'datetime'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
