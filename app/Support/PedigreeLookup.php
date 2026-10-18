<?php

namespace App\Support;

use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pedigree autofill, allbreedpedigree-style: type a sire or dam, pick a match from Horsefax, and the chart fills in
 * that horse's own ancestors. Matches come from two places:
 *  - Horsefax records (listed or public horses, or the person's own), using the horse's own pedigree;
 *  - names already entered in other horses' pedigrees, using the lines recorded behind them in that chart.
 * Only pedigree lines marked Public (or on the person's own horses) are ever used.
 */
final class PedigreeLookup
{
    /** Chart positions are s/d strings; a position's parents are pos.'s' and pos.'d'. Charts go up to Record::PEDIGREE_GENERATIONS (5) generations. */
    public const MAX_DEPTH = Record::PEDIGREE_GENERATIONS;

    public static function search(string $q, ?User $user, string $position): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        $male = str_ends_with($position, 's');
        $mine = $user ? $user->horses()->pluck('horses.id') : collect();

        $records = Horse::query()
            ->where(fn ($w) => $w->where('listed', true)->orWhere('public_summary', true)->orWhereIn('id', $mine))
            ->where(fn ($w) => $w->where('registered_name', 'ilike', $like)->orWhere('registered_name', 'ilike', '% '.$like))
            ->limit(15)->get()
            ->map(fn (Horse $h) => [
                'kind' => 'record', 'id' => $h->id, 'name' => $h->registered_name,
                'registry' => self::publicRegistration($h)['registry'] ?? null,
                'registration_number' => self::publicRegistration($h)['number'] ?? null,
                'foal_year' => $h->birth_year, 'color' => $h->color, 'horsefax_id' => $h->horsefax_id,
                'ancestors' => self::visibleEntries($h, $user)->count(),
                'sex_fit' => $h->sex === null || ($male ? in_array($h->sex, ['stallion', 'gelding', 'colt'], true) : in_array($h->sex, ['mare', 'filly'], true)),
            ]);

        $seen = $records->map(fn ($r) => mb_strtolower($r['name']).'|'.Record::normalizeIdentifier((string) $r['registration_number']))->flip();

        $entries = PedigreeEntry::query()
            ->whereNull('superseded_by')
            ->where('name', 'ilike', $like)
            ->where(fn ($w) => $w->where('visibility', 'public')->orWhereIn('horse_id', $mine))
            ->limit(200)->get()
            ->groupBy(fn ($e) => mb_strtolower($e->name).'|'.Record::normalizeIdentifier((string) $e->registration_number))
            ->reject(fn ($group, $key) => isset($seen[$key]))
            ->map(function (Collection $group) use ($user) {
                // Use the chart that knows the most about this ancestor.
                $best = $group->sortByDesc(fn ($e) => self::entryAncestry($e, $user)->count())->first();

                return [
                    'kind' => 'entry', 'id' => $best->id, 'name' => $best->name, 'registry' => $best->registry,
                    'registration_number' => $best->registration_number, 'foal_year' => $best->foal_year, 'color' => $best->color,
                    'horsefax_id' => null, 'ancestors' => self::entryAncestry($best, $user)->count(), 'sex_fit' => true,
                    'seen_in' => $group->pluck('horse_id')->unique()->count(),
                ];
            })->values();

        $exact = mb_strtolower($q);

        return $records->concat($entries)
            ->sort(fn ($a, $b) => [$b['sex_fit'], mb_strtolower($b['name']) === $exact, $b['ancestors'], mb_strtolower($a['name'])]
                <=> [$a['sex_fit'], mb_strtolower($a['name']) === $exact, $a['ancestors'], mb_strtolower($b['name'])])
            ->take(10)->values()->map(fn ($r) => collect($r)->except('sex_fit')->all())->all();
    }

    /**
     * The ancestors behind a chosen match, keyed by position relative to it ('s', 'd', 'ss', ...).
     *
     * @return array<string, array{name: string, registry: ?string, registration_number: ?string, foal_year: ?int, color: ?string, linked_horse_id: ?string}>
     */
    public static function ancestry(string $kind, string $id, ?User $user): array
    {
        $out = collect();
        if ($kind === 'record') {
            $horse = Horse::find($id);
            if (! $horse || ! self::recordVisible($horse, $user)) {
                return [];
            }
            $out = self::visibleEntries($horse, $user)->keyBy('position');
        } elseif ($kind === 'entry') {
            $entry = PedigreeEntry::find($id);
            if (! $entry || ! self::entryVisible($entry, $user)) {
                return [];
            }
            $out = self::entryAncestry($entry, $user);
            // If the ancestor has its own Horsefax record, its own pedigree fills any gaps.
            if ($entry->linked_horse_id && ($linked = Horse::find($entry->linked_horse_id)) && self::recordVisible($linked, $user)) {
                foreach (self::visibleEntries($linked, $user)->keyBy('position') as $pos => $e) {
                    $out->put($pos, $out->get($pos) ?? $e);
                }
            }
        }

        return $out->filter(fn ($e, $pos) => strlen($pos) <= self::MAX_DEPTH - 1)
            ->map(fn (PedigreeEntry $e) => [
                'name' => $e->name, 'registry' => $e->registry, 'registration_number' => $e->registration_number,
                'foal_year' => $e->foal_year, 'color' => $e->color, 'linked_horse_id' => $e->linked_horse_id,
            ])->all();
    }

    private static function steward(?User $user, string $horseId): bool
    {
        return $user !== null && ($user->isStaff() || $user->horses()->where('horses.id', $horseId)->exists());
    }

    private static function recordVisible(Horse $h, ?User $user): bool
    {
        return $h->listed || $h->public_summary || self::steward($user, $h->id);
    }

    private static function entryVisible(PedigreeEntry $e, ?User $user): bool
    {
        return $e->superseded_by === null && ($e->visibility === 'public' || self::steward($user, $e->horse_id));
    }

    /** A horse's current pedigree lines this person may see. */
    private static function visibleEntries(Horse $h, ?User $user): Collection
    {
        $q = PedigreeEntry::where('horse_id', $h->id)->whereNull('superseded_by');
        if (! self::steward($user, $h->id)) {
            $q->where('visibility', 'public');
        }

        return $q->get();
    }

    /** Lines recorded behind an entry in the same chart, re-keyed relative to it. */
    private static function entryAncestry(PedigreeEntry $entry, ?User $user): Collection
    {
        $p = $entry->position;
        $q = PedigreeEntry::where('horse_id', $entry->horse_id)->whereNull('superseded_by')
            ->where('position', 'like', $p.'%')->whereRaw('length(position) > ?', [strlen($p)]);
        if (! self::steward($user, $entry->horse_id)) {
            $q->where('visibility', 'public');
        }

        return $q->get()->keyBy(fn ($e) => substr($e->position, strlen($p)));
    }

    /** First public registration on a record (never a microchip). */
    private static function publicRegistration(Horse $h): array
    {
        $i = $h->currentIdentifiers()->where('kind', 'registration')->where('visibility', 'public')->where('in_conflict', false)->first();

        return $i ? ['registry' => $i->registry, 'number' => $i->value] : [];
    }
}
