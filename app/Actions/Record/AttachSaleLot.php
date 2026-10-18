<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\SaleLot;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * A published sale lot becomes an entry in the horse's ownership & sales history. Prices are public (Richard, Oct 17
 * 2026) and labeled by what the sale reported (Kate's rules, kept in HorseEvent::result): a reported auction sale,
 * a recorded high bid with the sale unconfirmed, or no sale. Each entry names its sale, lot and source page, its
 * currency and whether a buyer's premium is included. Historical and unverified, like every published result.
 */
class AttachSaleLot
{
    public function __invoke(SaleLot $lot, Horse $horse, User $by, string $tier): ?HorseEvent
    {
        $kind = $lot->priceKind();
        if ($kind === null || $lot->horse_event_id || ! $lot->publishable()) {
            return null;
        }

        return DB::transaction(function () use ($lot, $horse, $by, $tier, $kind) {
            $premium = ['included' => 'included', 'excluded' => 'not included', 'none' => 'none charged'][$lot->buyers_premium] ?? 'unknown';
            $details = collect([
                'From the published sale results: '.$lot->source_url,
                'Currency: '.$lot->currency.'. Buyer\'s premium: '.$premium.'.',
                $lot->note,
                $kind === 'reported_sale' ? 'Reported by the auction; Horsefax has not confirmed payment or transfer.' : null,
            ])->filter()->implode("\n");
            $date = $lot->sale_date ?? ($lot->sale_year ? \Illuminate\Support\Carbon::create($lot->sale_year, 1, 1) : null);
            $event = HorseEvent::create([
                'horse_id' => $horse->id, 'category' => 'ownership', 'type' => 'auction',
                'title' => HorseEvent::PRICE_KINDS[$kind], 'result' => $kind,
                'sale_venue' => $lot->sale_name.($lot->lot ? ', lot '.$lot->lot : ''),
                'occurred_on' => $date, 'date_precision' => $lot->sale_date ? $lot->date_precision : 'year',
                'amount_cents' => $kind === 'no_sale' ? null : $lot->price_cents, 'amount_visibility' => 'public',
                'details' => $details, 'contributor_id' => $by->id, 'contributor_role' => 'staff',
                'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public',
            ]);
            $lot->forceFill(['horse_id' => $horse->id, 'horse_event_id' => $event->id, 'match_tier' => $tier, 'review_status' => null, 'matched_at' => now()])->save();

            $pedigree = $lot->pedigree ?: array_filter(['s' => $lot->sire_name, 'd' => $lot->dam_name, 'ds' => $lot->dam_sire_name]);
            app(AddPublishedIdentity::class)($horse, BuildRecordsFromResults::system(), [
                'registry' => $lot->registry, 'registration_number' => $lot->registration_number, 'foal_year' => $lot->foal_year, 'pedigree' => $pedigree,
            ], 'sale lot '.$lot->id);
            Audit::record('sale_lot.attached', $horse, null, ['sale' => $lot->sale_name, 'lot' => $lot->lot, 'kind' => $kind, 'tier' => $tier], $horse->id, $by->id);

            return $event;
        });
    }
}
