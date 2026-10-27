<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a published stallion page says about standing the horse: fee, terms, farm and town. See the migration. */
class StallionService extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['observed_on' => 'date'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function source(): BelongsTo { return $this->belongsTo(DataSource::class, 'data_source_id'); }

    public function feeLabel(): ?string
    {
        if ($this->fee_cents === null) {
            return $this->fee_text;
        }

        return '$'.number_format($this->fee_cents / 100).($this->currency && $this->currency !== 'USD' ? ' '.$this->currency : '');
    }

    public function placeLabel(): ?string
    {
        return collect([$this->city, $this->region, $this->country && ! in_array($this->country, ['United States', 'USA'], true) ? $this->country : null])->filter()->join(', ') ?: null;
    }
}
