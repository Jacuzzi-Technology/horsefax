<?php

namespace App\Models;

use App\Support\Record;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorseIdentifier extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['in_conflict' => 'boolean'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }

    public function label(): string
    {
        return $this->kind === 'registration' && $this->registry
            ? $this->registry.' registration'
            : (Record::IDENTIFIER_KINDS[$this->kind] ?? $this->kind).($this->registry ? ' ('.$this->registry.')' : '');
    }

    /** What a non-steward reader sees. Microchips are always masked outside the record itself. */
    public function displayValue(bool $full = false): string
    {
        return ($this->kind === 'microchip' && ! $full) ? Record::mask($this->value) : $this->value;
    }
}
