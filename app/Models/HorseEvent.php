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

    /**
     * How a price is labeled (Kate's rules, adopted Oct 17 2026), kept in `result` on ownership entries. A reported
     * sale is only ever what an auction reported as sold; a high bid or an asking price never becomes a sale price.
     */
    public const PRICE_KINDS = [
        'reported_sale' => 'Reported auction sale',
        'high_bid' => 'Recorded high bid',
        'no_sale' => 'No sale',
        'asking' => 'Advertised asking price',
        'documented_private' => 'Documented private sale',
        'offered' => 'Offered at auction',
    ];

    public const PRICE_NOTE = 'Historical prices reflect a horse and the circumstances of a transaction at that time. They are not an estimate of current value.';

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

    public function isPriceEntry(): bool
    {
        return $this->category === 'ownership' && isset(self::PRICE_KINDS[$this->result]);
    }

    /** "Reported auction sale — $35,000", "Recorded high bid — $28,000 · Sale unconfirmed", "No sale". */
    public function priceLine(bool $withAmount = true): string
    {
        $label = self::PRICE_KINDS[$this->result] ?? ($this->typeLabel() ?? $this->title);
        $amount = $withAmount && $this->result !== 'offered' ? self::money($this->amount_cents) : null;

        return $label.($amount ? ' — '.$amount : '').($this->result === 'high_bid' ? ' · Sale unconfirmed' : '');
    }

    /** The published page an entry came from, when its details name one. */
    public function sourceUrl(): ?string
    {
        return preg_match('#From the published (?:sale )?(?:results|listing): (https?://\S+)#', (string) $this->details, $m) ? $m[1] : null;
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
