<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\ShareLink;
use Illuminate\Support\Collection;

/**
 * What each audience may see. Visibility is enforced in these queries, never only in the templates.
 *
 * - steward / staff: everything on the record
 * - report (share link): items marked shared or public, in the sections the link includes
 * - public (summary page, search): items marked public, and only when the owner turned the summary on
 */
final class Views
{
    public static function identifiers(Horse $horse, string $audience, ?ShareLink $link = null): Collection
    {
        $q = $horse->currentIdentifiers()->orderBy('kind');

        return match ($audience) {
            'steward' => $q->get(),
            'report' => $link && $link->includes('identifiers') ? $q->whereIn('visibility', ['shared', 'public'])->get() : collect(),
            default => $q->where('visibility', 'public')->get(),
        };
    }

    public static function events(Horse $horse, string $audience, ?ShareLink $link = null): Collection
    {
        $q = $horse->currentEvents()->with('document')->orderByDesc('occurred_on')->orderByDesc('created_at');

        return match ($audience) {
            'steward' => $q->get(),
            'report' => $link ? $q->whereIn('visibility', ['shared', 'public'])->whereIn('category', $link->sections ?? [])->get() : collect(),
            default => $q->where('visibility', 'public')->get(),
        };
    }

    public static function documents(Horse $horse, string $audience, ?ShareLink $link = null): Collection
    {
        $q = $horse->documents()->where('kind', '!=', 'photo')->orderByDesc('issued_on')->orderByDesc('created_at');

        return match ($audience) {
            'steward' => $q->get(),
            'report' => $link && $link->includes('documents') ? $q->whereIn('visibility', ['shared', 'public'])->get() : collect(),
            default => $q->where('visibility', 'public')->get(),
        };
    }

    public static function names(Horse $horse, string $audience): Collection
    {
        $q = $horse->names()->orderBy('created_at');

        return match ($audience) {
            'steward' => $q->get(),
            'report' => $q->whereIn('visibility', ['shared', 'public'])->get(),
            default => $q->where('visibility', 'public')->get(),
        };
    }

    public static function ownershipHistory(Horse $horse, string $audience, ?ShareLink $link = null): Collection
    {
        if ($audience === 'public' || ($audience === 'report' && ! $link?->includes('ownership'))) {
            return collect();
        }

        // People are never named outside the record itself: readers see roles and dates only.
        return $horse->stewardships()->orderBy('created_at')->get();
    }

    /**
     * What a professional contributor sees: the entries and documents they added themselves. The owner's own items,
     * whatever their visibility, stay out of view (the owner can send a report link for those).
     */
    public static function contributedEvents(Horse $horse, \App\Models\User $user): Collection
    {
        return $horse->events()->where('contributor_id', $user->id)->with('document')->orderByDesc('occurred_on')->orderByDesc('created_at')->get();
    }

    public static function contributedDocuments(Horse $horse, \App\Models\User $user): Collection
    {
        return $horse->documents()->where('uploaded_by', $user->id)->where('kind', '!=', 'photo')->orderByDesc('created_at')->get();
    }

    /** Pedigree lines by position. */
    public static function pedigree(Horse $horse, string $audience, ?ShareLink $link = null): Collection
    {
        $q = $horse->pedigree()->with('linkedHorse');

        return (match ($audience) {
            'steward' => $q->get(),
            'report' => $link && $link->includes('pedigree') ? $q->whereIn('visibility', ['shared', 'public'])->get() : collect(),
            default => $q->where('visibility', 'public')->get(),
        })->keyBy('position');
    }

    /**
     * The chart to draw: this record's own lines, then (Richard, Oct 19 2026: always four generations) any gap filled
     * from the public pedigree of a linked ancestor's record, e.g. the sire's record supplies the sire's grandparents.
     * Filled lines are marked ->inherited; nothing is saved to this record.
     */
    public static function pedigreeChart(Collection $own, int $depth = 4): Collection
    {
        $chart = $own->keyBy('position');
        if ($chart->isEmpty()) {
            return $chart;
        }
        for ($len = 2; $len <= $depth; $len++) {
            // Linked ancestors one or more generations up, nearest first, for the positions still missing at this depth.
            $sources = $chart->filter(fn ($e) => strlen($e->position) < $len && $e->linked_horse_id);
            if ($sources->isEmpty()) {
                break;
            }
            $lines = \App\Models\PedigreeEntry::whereIn('horse_id', $sources->pluck('linked_horse_id')->unique())
                ->where('visibility', 'public')->whereRaw('length(position) <= ?', [$len - 1])->with('linkedHorse')->get()->groupBy('horse_id');
            foreach (Record::pedigreePositions() as $pos => $label) {
                if (strlen($pos) !== $len || $chart->has($pos)) {
                    continue;
                }
                for ($cut = $len - 1; $cut >= 1; $cut--) {
                    $from = $chart->get(substr($pos, 0, $cut));
                    $line = $from?->linked_horse_id ? ($lines[$from->linked_horse_id] ?? collect())->firstWhere('position', substr($pos, $cut)) : null;
                    if ($line) {
                        $copy = $line->replicate();
                        $copy->setRelation('linkedHorse', $line->linkedHorse);
                        $copy->position = $pos;
                        $copy->inherited = true;
                        $chart->put($pos, $copy);
                        break;
                    }
                }
            }
        }

        return $chart;
    }

    /** Offspring on Horsefax. Readers only see offspring whose own record is public. */
    public static function offspring(Horse $horse, string $audience, ?ShareLink $link = null): Collection
    {
        $q = $horse->offspring()->orderBy('birth_year');

        return match ($audience) {
            'steward' => $q->get(),
            'report' => $link && $link->includes('pedigree') ? $q->where('public_summary', true)->get() : collect(),
            default => $q->where('public_summary', true)->get(),
        };
    }

    /** Released photos/videos only, outside the record. */
    public static function media(Horse $horse, string $audience, ?ShareLink $link = null): Collection
    {
        $q = $horse->media()->with('document')->orderByDesc('taken_on')->orderByDesc('created_at');

        return match ($audience) {
            'steward' => $q->get(),
            'report' => $link && $link->includes('media') ? $q->where('rights_attested', true)->whereIn('visibility', ['shared', 'public'])->get() : collect(),
            default => $q->where('rights_attested', true)->where('visibility', 'public')->get(),
        };
    }

    /** May this audience see the sale price on this entry? */
    public static function showAmount(\App\Models\HorseEvent $event, string $audience, ?ShareLink $link = null): bool
    {
        if ($event->amount_cents === null) {
            return false;
        }

        return match ($audience) {
            'steward' => true,
            'report' => $link?->includes('sale_prices') && in_array($event->amount_visibility, ['shared', 'public'], true),
            default => $event->amount_visibility === 'public',
        };
    }

    /** Current identity facts keyed by field. Basic identity is shown to every audience that can see the record. */
    public static function facts(Horse $horse): Collection
    {
        $facts = $horse->currentFacts()->with('verification')->get()->keyBy('field');

        // Records loaded in bulk from a published list keep identity on the record itself: show those values as claims too.
        if (in_array($horse->source, ['ifce'], true)) {
            $from = ['registered_name' => $horse->registered_name, 'breed' => $horse->breed, 'sex' => $horse->sex, 'color' => $horse->color, 'breeder' => $horse->breeder,
                'birth' => $horse->birth_precision === 'day' ? $horse->birth_date?->toDateString() : ($horse->birth_year ? (string) $horse->birth_year : null)];
            foreach ($from as $field => $value) {
                if ($value !== null && $value !== '' && ! $facts->has($field)) {
                    $facts[$field] = new \App\Models\HorseFact(['horse_id' => $horse->id, 'field' => $field, 'value' => $value, 'source_type' => 'official_source',
                        'status' => 'unverified', 'is_current' => true, 'meta' => $field === 'birth' ? ['precision' => $horse->birth_precision, 'country' => $horse->birth_country] : null]);
                }
            }
        }

        return $facts;
    }

    /** The profile photo, if this audience may see it. */
    public static function photo(Horse $horse, string $audience): ?\App\Models\Document
    {
        $photo = $horse->profilePhoto;
        if (! $photo) {
            return null;
        }

        return match ($audience) {
            'steward' => $photo,
            'report' => in_array($photo->visibility, ['shared', 'public'], true) ? $photo : null,
            default => $photo->visibility === 'public' ? $photo : null,
        };
    }
}
