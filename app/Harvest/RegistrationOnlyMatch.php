<?php

namespace App\Harvest;

use App\Actions\Record\MatchSaleLots;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\SaleLot;
use App\Support\Record;

/**
 * Attach a sale lot to a record on its registration number and nothing else (Richard, Oct 22 2026, for the sale-catalog
 * imports). Same rule as the DreamHorse loader: exactly one matchable record holding the number (registry agreeing
 * when the lot names one) and nothing on that record contradicting the lot. Lots without a match are marked
 * review_status 'unmatched' so the nightly hf:match-sales never tries name + pedigree matches on them.
 */
class RegistrationOnlyMatch
{
    public const UNMATCHED = 'unmatched';

    /**
     * The whole placement for a catalog lot: registration number first, then (no number, or no record holding it) the
     * registered-name rule (AdvertisedRegisteredName), which may propose a match, queue it or create an unverified record. A rerun leaves lots
     * already attached or waiting on staff (queued, conflict, held, dismissed) alone.
     *
     * @return string matched, conflict, proposed, linked, created, queued, unmatched or already
     */
    public static function place(SaleLot $lot, string $sourceLabel): string
    {
        if ($lot->horse_id || ! in_array($lot->review_status, [null, self::UNMATCHED], true)) {
            return 'already';
        }
        $r = self::apply($lot);
        if (! in_array($r, ['unmatched', 'no_number'], true)) {
            return $r;
        }
        $n = AdvertisedRegisteredName::apply($lot, $sourceLabel, 'catalog lot');
        if ($n === 'unmatched' && ! $lot->horse_id && $lot->review_status === null) {
            $lot->forceFill(['review_status' => self::UNMATCHED, 'review_note' => 'Not a registered name; matched on registration number only'])->save();
        }

        return $n;
    }

    /** @return string matched, conflict, unmatched, no_number or already */
    public static function apply(SaleLot $lot): string
    {
        if ($lot->horse_id) {
            return 'already';
        }
        if (! $lot->registration_number || ! preg_match('/\d/', $lot->registration_number)) {
            if ($lot->review_status === null) {
                $lot->forceFill(['review_status' => self::UNMATCHED, 'review_note' => 'No registration number; matched on registration number only'])->save();
            }

            return 'no_number';
        }
        $ids = HorseIdentifier::where('kind', 'registration')->whereNull('superseded_by')
            ->where('value_normalized', Record::normalizeIdentifier($lot->registration_number))
            ->when($lot->registry, fn ($q) => $q->where(fn ($w) => $w->whereNull('registry')->orWhere('registry', $lot->registry)))
            ->pluck('horse_id')->unique();
        $ids = MatchSaleLots::matchable(Horse::whereIn('id', $ids))->pluck('id');
        if ($ids->count() > 1) {
            $lot->forceFill(['review_status' => 'conflict', 'review_note' => 'Registration number '.$lot->registration_number.' is on more than one record'])->save();

            return 'conflict';
        }
        if ($ids->count() === 1) {
            $horse = Horse::find($ids->first());
            if ($why = app(MatchSaleLots::class)->contradiction($lot, $horse)) {
                $lot->forceFill(['review_status' => 'conflict', 'review_note' => mb_substr('Registration number matches '.$horse->horsefax_id.' but the '.$why.' differs', 0, 255)])->save();

                return 'conflict';
            }
            // review_status null: the nightly hf:match-sales puts a reported price on the record (AttachSaleLot).
            $lot->forceFill(['horse_id' => $horse->id, 'match_tier' => 'registration', 'matched_at' => now(), 'review_status' => $lot->priceKind() && ! $lot->publishable() ? 'held' : null, 'review_note' => null])->save();

            return 'matched';
        }
        $lot->forceFill(['review_status' => self::UNMATCHED, 'review_note' => 'No record holds registration number '.$lot->registration_number])->save();

        return 'unmatched';
    }
}
