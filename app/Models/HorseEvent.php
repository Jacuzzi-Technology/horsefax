<?php

namespace App\Models;

use App\Support\Record;
use App\Models\Concerns\IsClaim;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorseEvent extends Model
{
    use HasUuids, IsClaim;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'occurred_on' => 'date', 'expires_on' => 'date', 'points' => 'decimal:2'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function contributor(): BelongsTo { return $this->belongsTo(User::class, 'contributor_id'); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }

    public function categoryLabel(): string
    {
        return Record::EVENT_CATEGORIES[$this->category] ?? $this->category;
    }

    public function typeLabel(): ?string
    {
        return Record::eventTypeLabel($this->category, $this->type);
    }

    public static function money(?int $cents): ?string
    {
        return $cents === null ? null : '$'.number_format($cents / 100, $cents % 100 ? 2 : 0);
    }

    public function dateLabel(): string
    {
        if (! $this->occurred_on) {
            return 'Date unknown';
        }

        return match ($this->date_precision) {
            'year' => $this->occurred_on->format('Y'),
            'month' => $this->occurred_on->format('M Y'),
            default => $this->occurred_on->format('M j, Y'),
        };
    }
}
