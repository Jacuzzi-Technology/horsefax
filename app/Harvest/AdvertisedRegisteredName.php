<?php

namespace App\Harvest;

use App\Actions\Record\CreateRecordsFromSaleLots;
use App\Actions\Record\MatchSaleLots;
use App\Models\ReviewSuggestion;
use App\Models\SaleLot;
use App\Support\Record;

/** Shared by the classified-ad loaders (RanchWorldAds, DreamHorse), moved out of RanchWorldAdsHarvester unchanged. */
class AdvertisedRegisteredName
{
    /**
     * An ad giving the horse's registered name (Richard, Oct 22 2026: "create new unverified horse listings anytime we
     * have the registered names on the sites we're crawling"):
     *  - one record agrees on name + parentage: PROPOSED to staff (lot queued + lot_belongs suggestion), never attached,
     *    unless that record was itself made from an earlier ad of this horse on the same site (then the ad is linked, held);
     *  - a record of that name with nothing to compare: left queued for staff, nothing created beside it;
     *  - no record of that name, or every one contradicts the ad (a different horse): a new unverified record.
     * A registered name: the ad names a registry (or says registered) or gives sire and dam, the horse isn't listed as
     * grade, and the name has two or more words ("Trigger" is a barn name). Mules and donkeys are left out. Returns proposed, linked, created, queued or unmatched.
     */
    public static function apply(SaleLot $lot, string $site): string
    {
        $words = count(preg_split('/\s+/', trim($lot->horse_name)));
        $registered = $lot->registry || $lot->registered === true || ($lot->sire_name && $lot->dam_name);
        if (! $registered || Record::placeholderName($lot->horse_name) || $words < 2 || $lot->registered === false
            || preg_match('/\b(mule|donkey|burro|ass)\b/i', (string) $lot->breed)) {
            return 'unmatched';
        }
        [$horse, $tier, $review, $note] = (new MatchSaleLots)->match($lot); // a fresh matcher each time: it sees records made so far
        if ($horse) {
            if (SaleLot::where('horse_id', $horse->id)->where('data_source_id', $lot->data_source_id)->where('match_tier', 'created')->exists()) {
                $lot->forceFill(['horse_id' => $horse->id, 'match_tier' => $tier, 'matched_at' => now(), 'review_status' => 'held',
                    'review_note' => 'Same horse as an earlier '.$site.' ad; advertised asking prices are not published yet'])->save();

                return 'linked';
            }
            $summary = $site.' ad names '.$lot->horse_name.($lot->sire_name ? ' by '.$lot->sire_name : '').($lot->dam_name ? ' out of '.$lot->dam_name : '').', which agrees with '.$horse->horsefax_id.'. Proposed on name and parentage only; not attached.';
            $lot->forceFill(['review_status' => 'queued', 'review_note' => mb_substr('Proposed: '.$horse->horsefax_id.' ('.$tier.')', 0, 255)])->save();
            ReviewSuggestion::firstOrCreate(['subject_type' => 'sale_lot', 'subject_id' => $lot->id], [
                'suggestion' => 'lot_belongs', 'horse_id' => $horse->id, 'summary' => $summary,
                'evidence' => [['url' => $lot->source_url, 'facts' => array_filter(['sire' => $lot->sire_name, 'dam' => $lot->dam_name, 'foal_year' => $lot->foal_year, 'sex' => $lot->sex]), 'supports' => true]],
            ]);

            return 'proposed';
        }
        $different = $review === 'conflict' && str_starts_with((string) $note, MatchSaleLots::DIFFERENT);
        if ($review && ! $different) {
            $lot->forceFill(['review_status' => $review, 'review_note' => $note])->save();

            return $review === 'queued' ? 'queued' : 'unmatched';
        }
        app(CreateRecordsFromSaleLots::class)->createForAdvertised($lot, $different ? $note : null);

        return 'created';
    }
}
