<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\HorseEvent;
use Illuminate\Support\Collection;

/**
 * The Life Timeline (Kate's design, Oct 19 2026): the same entries the report shows, already filtered for the reader,
 * arranged by year. Nothing is stored separately and no date is invented: an entry known only to the year sits under
 * that year without a month; an entry with no date goes under "Undated". Show results collapse into one line per year
 * (count, earnings, best placing) with the results inside; milestones (foaled, first and latest result, first time at
 * auction, ownership changes) are derived from the same entries.
 */
class Timeline
{
    public const CATEGORIES = [
        'identity' => 'Identity', 'competition' => 'Competition', 'sales' => 'Sales', 'ownership' => 'Ownership',
        'health' => 'Health & care', 'training' => 'Training', 'media' => 'Photos & videos', 'other' => 'Other',
    ];

    /**
     * @return array{years: array<string, list<array>>, undated: list<array>, categories: array<string,string>}
     *         each item: kind (event|career|milestone|media|ownership), category, date (sortable Y-m-d or null), label, title, ...
     */
    public static function build(Horse $horse, Collection $events, Collection $media, Collection $ownership, ?\Closure $showAmount = null): array
    {
        $showAmount ??= fn ($e) => \App\Support\Views::showAmount($e, 'public');
        $items = [];
        $undated = [];
        $add = function (array $item) use (&$items, &$undated) {
            if ($item['year']) {
                $items[$item['year']][] = $item;
            } else {
                $undated[] = $item;
            }
        };

        // Foaled
        if ($horse->birth_year && ($horse->birth_precision ?? 'unknown') !== 'unknown') {
            $add(['kind' => 'milestone', 'category' => 'identity', 'year' => (int) $horse->birth_year,
                'sort' => sprintf('%04d-00-00', $horse->birth_year), 'when' => $horse->birthLabel() ?? (string) $horse->birth_year, 'title' => 'Foaled']);
        }

        // Show results: one line per year, the results inside.
        $shows = $events->where('category', 'competition');
        foreach ($shows->groupBy(fn ($e) => $e->occurred_on?->format('Y') ?? '') as $year => $group) {
            if ($year === '') {
                foreach ($group as $e) {
                    $undated[] = self::event($e, $showAmount);
                }
                continue;
            }
            $placings = $group->map(fn ($e) => (int) preg_replace('/\D.*$/', '', (string) $e->placing))->filter();
            $add([
                'kind' => 'career', 'category' => 'competition', 'year' => (int) $year, 'sort' => $year.'-12-31',
                'when' => (string) $year, 'title' => $group->count().' '.\Illuminate\Support\Str::plural('show result', $group->count()),
                'earnings' => $group->sum('earnings_cents') ?: null, 'best' => $placings->min(),
                'organizations' => $group->pluck('organization')->filter()->map(fn ($o) => preg_replace('/\s*\(.*$/', '', $o))->unique()->take(4)->values()->all(),
                'entries' => $group->sortByDesc(fn ($e) => $e->occurred_on?->format('Y-m-d'))->map(fn ($e) => self::event($e, $showAmount))->values()->all(),
            ]);
        }
        $dated = $shows->filter(fn ($e) => $e->occurred_on)->sortBy(fn ($e) => $e->occurred_on->format('Y-m-d'));
        if ($dated->count() > 1) {
            $first = $dated->first();
            $last = $dated->last();
            $add(['kind' => 'milestone', 'category' => 'competition', 'year' => (int) $first->occurred_on->format('Y'), 'sort' => $first->occurred_on->format('Y-m-d').'a',
                'when' => $first->dateLabel(), 'title' => 'First recorded show result', 'detail' => collect([$first->event_name, $first->class_name])->filter()->join(' · ')]);
            if ($last->occurred_on->format('Y') !== $first->occurred_on->format('Y')) {
                $add(['kind' => 'milestone', 'category' => 'competition', 'year' => (int) $last->occurred_on->format('Y'), 'sort' => $last->occurred_on->format('Y-m-d').'z',
                    'when' => $last->dateLabel(), 'title' => 'Most recent recorded show result', 'detail' => collect([$last->event_name, $last->class_name])->filter()->join(' · ')]);
            }
        }

        // Everything else, entry by entry. Sales and listings are "sales"; other ownership entries are "ownership".
        $firstAuction = $events->where('category', 'ownership')->filter(fn ($e) => $e->occurred_on && $e->type === 'auction')->sortBy(fn ($e) => $e->occurred_on->format('Y-m-d'))->first();
        foreach ($events->where('category', '!=', 'competition') as $e) {
            $item = self::event($e, $showAmount);
            if ($firstAuction && $e->is($firstAuction) && $events->where('type', 'auction')->count() > 1) {
                $item['badge'] = 'First time at auction';
            }
            $add($item);
        }

        // Ownership changes: dates and roles only, never names (as on the report).
        foreach ($ownership as $s) {
            $d = $s->started_on ?? $s->created_at;
            $add(['kind' => 'ownership', 'category' => 'ownership', 'year' => $d ? (int) $d->format('Y') : null, 'sort' => $d?->format('Y-m-d'),
                'when' => $d?->format('M Y') ?? 'Date unknown', 'title' => 'Ownership changed', 'detail' => \App\Support\Roles::stewardLabel($s)]);
        }

        // Photos and videos: by the date taken; Horsefax-imported listing photos sit with their listing; the rest are undated.
        $listing = $events->where('category', 'ownership')->filter(fn ($e) => $e->occurred_on && str_contains((string) $e->sale_venue, 'Horse of My Dreams') && ! $e->isPriceEntry())->sortBy(fn ($e) => $e->occurred_on)->first();
        $byDate = $media->groupBy(fn ($m) => $m->taken_on?->format('Y-m') ?? ($listing && $m->uploaded_by === $listing->contributor_id ? 'listing' : ''));
        foreach ($byDate as $key => $group) {
            $count = $group->count();
            $what = self::mediaWords($group);
            if ($key === 'listing') {
                $add(['kind' => 'media', 'category' => 'media', 'year' => (int) $listing->occurred_on->format('Y'), 'sort' => $listing->occurred_on->format('Y-m-d').'m',
                    'when' => $listing->dateLabel(), 'title' => ucfirst($what).' from the Horse of My Dreams listing', 'count' => $count]);
            } elseif ($key !== '') {
                $d = \Illuminate\Support\Carbon::createFromFormat('Y-m', $key)->startOfMonth();
                $add(['kind' => 'media', 'category' => 'media', 'year' => (int) $d->format('Y'), 'sort' => $d->format('Y-m').'-15', 'when' => $d->format('M Y'), 'title' => ucfirst($what), 'count' => $count]);
            } else {
                $undated[] = ['kind' => 'media', 'category' => 'media', 'year' => null, 'sort' => null, 'when' => 'Date unknown', 'title' => ucfirst($what), 'count' => $count];
            }
        }

        krsort($items);
        foreach ($items as &$list) {
            usort($list, fn ($a, $b) => strcmp((string) $b['sort'], (string) $a['sort']));
        }
        $present = collect($items)->flatten(1)->merge($undated)->pluck('category')->unique()->all();

        return ['years' => $items, 'undated' => $undated, 'categories' => array_intersect_key(self::CATEGORIES, array_flip($present))];
    }

    private static function event(HorseEvent $e, \Closure $showAmount): array
    {
        $category = match ($e->category) {
            'competition' => 'competition',
            'ownership' => in_array($e->type, ['sale', 'purchase', 'auction'], true) || $e->isPriceEntry() ? 'sales' : 'ownership',
            'health', 'care' => 'health',
            'training' => 'training',
            default => 'other',
        };
        $title = $e->isPriceEntry() ? $e->priceLine($showAmount($e))
            : ($e->category === 'competition' ? collect([$e->class_name ?: $e->title, $e->placing ? 'placed '.$e->placing : null])->filter()->join(' · ') : $e->title);

        return [
            'kind' => 'event', 'category' => $category, 'year' => $e->occurred_on ? (int) $e->occurred_on->format('Y') : null,
            'sort' => $e->occurred_on?->format('Y-m-d'), 'when' => $e->occurred_on ? $e->dateLabel() : 'Date unknown',
            'title' => $title, 'detail' => collect([$e->category === 'competition' ? $e->event_name : null, $e->sale_venue, $e->category === 'competition' ? HorseEvent::money($e->earnings_cents) : null])->filter()->join(' · '),
            'source' => $e->sourceUrl(), 'event' => $e,
        ];
    }

    private static function mediaWords(Collection $group): string
    {
        $photos = $group->where('kind', 'photo')->count();
        $videos = $group->count() - $photos;

        return collect([$photos ? $photos.' '.\Illuminate\Support\Str::plural('photo', $photos) : null, $videos ? $videos.' '.\Illuminate\Support\Str::plural('video', $videos) : null])->filter()->join(' and ');
    }
}
