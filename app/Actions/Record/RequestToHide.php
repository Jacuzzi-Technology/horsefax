<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\ReviewItem;
use App\Models\User;
use App\Support\Audit;

/**
 * Richard, Oct 19 2026: "Owners can't hide anything without it getting approved." Anything that makes an item less
 * visible (public to shared or private, a public price hidden, the public record or search listing turned off) becomes
 * a request to Horsefax staff; the item stays as it is until staff approve. Showing more takes effect at once. Staff
 * may change visibility directly.
 */
class RequestToHide
{
    public const RANK = ['private' => 0, 'shared' => 1, 'public' => 2];

    /**
     * Sale history (sales, auctions, listings and their prices) is never hidden at an owner's request, HOMD included
     * (Richard, Oct 19 2026). Only staff can change it, e.g. when a sale was matched to the wrong horse.
     */
    public static function isSaleHistory(object $item, string $type): bool
    {
        return $item instanceof \App\Models\HorseEvent && $item->category === 'ownership'
            && ($type === 'amount' || $item->isPriceEntry() || in_array($item->type, ['sale', 'purchase', 'auction'], true));
    }

    public static function lowers(?string $from, string $to): bool
    {
        return (self::RANK[$to] ?? 0) < (self::RANK[$from] ?? 0);
    }

    /** @param string $type identifier|event|document|name|media|amount|horse */
    public function __invoke(Horse $horse, User $user, string $type, string $id, string $field, string $from, string $to, string $what): ReviewItem
    {
        $open = ReviewItem::where('kind', 'visibility_change')->where('horse_id', $horse->id)->where('status', 'open')
            ->where('subject_id', $type === 'horse' ? null : $id)->get()->first(fn ($i) => ($i->details['field'] ?? null) === $field && ($i->details['type'] ?? null) === $type);
        if ($open) {
            return $open; // one request at a time per item
        }
        $labels = ['private' => 'Private', 'shared' => 'Shared', 'public' => 'Public', '1' => 'On', '0' => 'Off'];
        $item = ReviewItem::create([
            'kind' => 'visibility_change', 'horse_id' => $horse->id, 'submitted_by' => $user->id,
            'subject_type' => $type === 'horse' ? null : $type, 'subject_id' => $type === 'horse' ? null : $id,
            'statement' => 'Asked to change '.$what.' from '.($labels[$from] ?? $from).' to '.($labels[$to] ?? $to).'.',
            'details' => ['type' => $type, 'id' => $id, 'field' => $field, 'from' => $from, 'to' => $to, 'what' => $what],
        ]);
        Audit::record('visibility.change_requested', $item, null, $item->details, $horse->id, $user->id);
        \App\Support\Notify::staff('Horsefax request to hide on '.$horse->horsefax_id, $item->statement);

        return $item;
    }

    /** Staff approved: make the change. */
    public static function apply(ReviewItem $item): void
    {
        $d = $item->details ?? [];
        if (($d['type'] ?? null) === 'horse') {
            $item->horse?->forceFill([$d['field'] => (bool) (int) $d['to']])->save();
        } else {
            $model = match ($d['type'] ?? null) {
                'identifier' => \App\Models\HorseIdentifier::class, 'event', 'amount' => \App\Models\HorseEvent::class,
                'document' => \App\Models\Document::class, 'name' => \App\Models\HorseName::class, 'media' => \App\Models\Media::class,
                default => null,
            };
            $model && ($row = $model::where('horse_id', $item->horse_id)->find($d['id'])) && $row->forceFill([$d['field'] => $d['to']])->save();
        }
        Audit::record('visibility.change_approved', $item, null, $d, $item->horse_id);
    }
}
