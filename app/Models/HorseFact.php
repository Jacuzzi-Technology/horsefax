<?php

namespace App\Models;

use App\Models\Concerns\IsClaim;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One claimed value for one identity field (height, color, breed ...), with its source, status and history. */
class HorseFact extends Model
{
    use HasUuids, IsClaim;

    public const FIELDS = [
        'registered_name' => 'Registered name', 'barn_name' => 'Barn name', 'breed' => 'Breed', 'sex' => 'Sex',
        'birth' => 'Foaled', 'color' => 'Color', 'height' => 'Height', 'markings' => 'Markings',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'is_current' => 'boolean', 'observed_on' => 'date', 'verified_at' => 'datetime'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function contributor(): BelongsTo { return $this->belongsTo(User::class, 'contributor_id'); }

    public function label(): string
    {
        return self::FIELDS[$this->field] ?? $this->field;
    }

    public function display(): string
    {
        return match ($this->field) {
            'sex' => ucfirst((string) $this->value),
            'height' => rtrim(rtrim((string) $this->value, '0'), '.').' hh'.(! empty($this->meta['measured_on']) ? ' (measured '.\Illuminate\Support\Carbon::parse($this->meta['measured_on'])->format('M Y').')' : ''),
            'birth' => (($this->meta['precision'] ?? null) === 'day' ? \Illuminate\Support\Carbon::parse($this->value)->format('M j, Y') : 'Foaled '.$this->value)
                .(! empty($this->meta['country']) ? ', '.$this->meta['country'] : ''),
            default => (string) $this->value,
        };
    }
}
