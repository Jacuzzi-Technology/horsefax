<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One horse as an auction published it: identity as printed, what the sale reported, and the price. Not a horse record. */
class SaleLot extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sale_date' => 'date', 'birth_date' => 'date', 'registered' => 'boolean', 'pedigree' => 'array', 'matched_at' => 'datetime'];
    }

    public function source(): BelongsTo { return $this->belongsTo(DataSource::class, 'data_source_id'); }
    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }

    /** The price kind shown on a record (HorseEvent::result) for this lot's status. */
    public function priceKind(): ?string
    {
        return match ($this->status) {
            'sold' => 'reported_sale',
            'high_bid' => 'high_bid',
            'no_sale' => 'no_sale',
            'asking' => 'asking',
            'offered' => 'offered', // catalogued and offered; the sale published no price for it
            default => null, // catalog, withdrawn: nothing to put on a record
        };
    }

    /** May this lot go onto a public record yet? (config horsefax.published_sale_kinds) */
    public function publishable(): bool
    {
        return in_array($this->priceKind(), config('horsefax.published_sale_kinds', ['reported_sale']), true);
    }

    /** Store a published lot, or refresh it when the sale republishes (a catalog lot gets its result). Keeps any match. */
    public static function record(int $sourceId, string $externalId, array $values): self
    {
        foreach ($values as $k => $v) {
            if (is_string($v)) {
                $values[$k] = mb_scrub($v, 'UTF-8'); // a malformed byte in a published page never stops a run
            }
        }
        $values['name_key'] = ShowRecord::key($values['horse_name']);
        $values['sale_year'] ??= isset($values['sale_date']) ? (int) substr((string) $values['sale_date'], 0, 4) : null;

        return self::updateOrCreate(['data_source_id' => $sourceId, 'external_id' => $externalId], $values);
    }
}
