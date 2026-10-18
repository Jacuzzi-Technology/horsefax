<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\PedigreeEntry;
use App\Models\SaleLot;
use App\Models\ShowRecord;
use App\Support\Record;

/**
 * Matches published sale lots to horse records (the rules in the Horsefax source catalog, Oct 17 2026). Never on
 * name alone:
 *  1. registration number: the one record holding that number;
 *  2. name + sire + dam: exactly one record of that name whose pedigree agrees;
 *  3. name + foal year + one parent: exactly one such record;
 *  4. name + foal year only, or name with nothing to contradict it: queued for staff, never attached automatically.
 * Only the kinds of result in config horsefax.published_sale_kinds are put on records; other matches are held.
 * Any record whose registration number, sire, dam or foal year contradicts the lot is ruled out; if that leaves
 * nothing, the lot is flagged as a conflict for staff (same-name horses).
 */
class MatchSaleLots
{
    public const AUTO = ['registration', 'sire_dam', 'foal_year_parent'];

    /** @var array<string, list<string>> name key => horse ids */
    private array $byName = [];

    /** @return array{attached:int, queued:int, conflict:int, unmatched:int} */
    public function __invoke(?int $limit = null, ?\Closure $log = null): array
    {
        $this->byName = [];
        // National registry lists (IFCE) are never matched by name, and merged-away records never at all.
        self::matchable(Horse::query())->whereNotNull('registered_name')->select(['id', 'registered_name'])->lazyById(2000, 'id')
            ->each(function ($h) {
                $this->byName[ShowRecord::key($h->registered_name)][] = $h->id;
            });
        $system = BuildRecordsFromResults::system();
        $attach = app(AttachSaleLot::class);
        $out = ['attached' => 0, 'held' => 0, 'queued' => 0, 'conflict' => 0, 'unmatched' => 0];
        $q = SaleLot::whereNull('horse_id')->whereIn('status', ['sold', 'high_bid', 'no_sale'])
            ->where(fn ($w) => $w->whereNull('review_status')->orWhereIn('review_status', ['queued', 'conflict', 'held']));
        $done = 0;
        foreach ($q->lazyById(500, 'id') as $lot) {
            if ($limit !== null && $done >= $limit) {
                break;
            }
            $done++;
            [$horse, $tier, $review, $note] = $this->match($lot);
            if ($horse && in_array($tier, self::AUTO, true) && ! $lot->publishable()) {
                // Matched, but this kind of result isn't published yet: held, and attached on a later run once it is.
                $lot->forceFill(['review_status' => 'held', 'review_note' => 'Matches '.$horse->horsefax_id.' ('.$tier.'); not published yet'])->save();
                $out['held']++;
            } elseif ($horse && in_array($tier, self::AUTO, true)) {
                $attach($lot, $horse, $system, $tier);
                $out['attached']++;
            } elseif ($review) {
                $lot->forceFill(['review_status' => $review, 'review_note' => $note, 'horse_id' => null])->save();
                $out[$review]++;
            } else {
                $lot->forceFill(['review_status' => null, 'review_note' => null])->save();
                $out['unmatched']++;
            }
            if ($log && $done % 500 === 0) {
                $log("$done lots: ".json_encode($out));
            }
        }

        return $out;
    }

    /** @return array{0:?Horse, 1:?string, 2:?string, 3:?string} horse, tier, review status, note */
    public function match(SaleLot $lot): array
    {
        if ($lot->registration_number) {
            $ids = HorseIdentifier::where('kind', 'registration')->whereNull('superseded_by')
                ->where('value_normalized', Record::normalizeIdentifier($lot->registration_number))
                ->when($lot->registry, fn ($q) => $q->where(fn ($w) => $w->whereNull('registry')->orWhere('registry', $lot->registry)))
                ->pluck('horse_id')->unique();
            $ids = self::matchable(Horse::whereIn('id', $ids))->pluck('id');
            if ($ids->count() === 1) {
                return [Horse::find($ids->first()), 'registration', null, null];
            }
            if ($ids->count() > 1) {
                return [null, null, 'conflict', 'Registration number '.$lot->registration_number.' is on more than one record'];
            }
        }

        if (! $this->byName) {
            self::matchable(Horse::whereRaw('lower(registered_name) = lower(?)', [$lot->horse_name]))->pluck('id')
                ->each(fn ($id) => $this->byName[$lot->name_key][] = $id); // single lot (staff page)
        }
        $ids = $this->byName[$lot->name_key] ?? [];
        if (! $ids) {
            return [null, null, null, null];
        }
        $candidates = Horse::whereIn('id', $ids)->get();
        $parents = PedigreeEntry::whereIn('horse_id', $ids)->whereNull('superseded_by')->whereIn('position', ['s', 'd'])->get()->groupBy('horse_id');
        $regs = HorseIdentifier::whereIn('horse_id', $ids)->where('kind', 'registration')->whereNull('superseded_by')->get()->groupBy('horse_id');

        $fits = [];
        $ruledOut = [];
        foreach ($candidates as $h) {
            $p = ($parents[$h->id] ?? collect())->keyBy('position');
            $sire = $p['s']->name ?? null;
            $dam = $p['d']->name ?? null;
            $year = $h->birth_precision !== 'unknown' ? $h->birth_year : null;
            $why = $this->contradiction($lot, $h, $sire, $dam, $regs[$h->id] ?? collect());
            if ($why) {
                $ruledOut[] = $h->horsefax_id.' ('.$why.')';
                continue;
            }
            $fits[] = [
                'horse' => $h,
                'sire' => $lot->sire_name && $sire, 'dam' => $lot->dam_name && $dam,
                'year' => $lot->foal_year && $year,
            ];
        }

        $strong = array_values(array_filter($fits, fn ($f) => $f['sire'] && $f['dam']));
        if (count($strong) === 1) {
            return [$strong[0]['horse'], 'sire_dam', null, null];
        }
        $medium = array_values(array_filter($fits, fn ($f) => $f['year'] && ($f['sire'] || $f['dam'])));
        if (! $strong && count($medium) === 1) {
            return [$medium[0]['horse'], 'foal_year_parent', null, null];
        }
        if ($fits) {
            $basis = count($fits) > 1 ? count($fits).' records share this name'
                : ($fits[0]['year'] ? 'Name and foal year agree; no parentage to compare' : 'Name only: nothing on the record to compare');

            return [null, null, 'queued', $basis];
        }

        return [null, null, 'conflict', 'Same name, different horse: '.implode(', ', $ruledOut)];
    }

    public static function matchable($query)
    {
        return $query->whereNull('merged_into_id')->where(fn ($w) => $w->whereNull('source')->orWhere('source', '!=', 'ifce'));
    }

    /** Why this record can't be the lot's horse (registration number, sire, dam, foal year, show years), or null. */
    public function contradiction(SaleLot $lot, Horse $h, ?string $sire = null, ?string $dam = null, ?\Illuminate\Support\Collection $regs = null): ?string
    {
        if (func_num_args() === 2) {
            $p = $h->pedigree()->whereIn('position', ['s', 'd'])->get()->keyBy('position');
            [$sire, $dam] = [$p['s']->name ?? null, $p['d']->name ?? null];
            $regs = $h->identifiers()->where('kind', 'registration')->whereNull('superseded_by')->get();
        }
        $year = $h->birth_precision !== 'unknown' ? $h->birth_year : null;

        return match (true) {
            $lot->registration_number && $regs && $regs->isNotEmpty()
                && ! $regs->contains(fn ($r) => $r->value_normalized === Record::normalizeIdentifier($lot->registration_number)) => 'registration number',
            $lot->sire_name && $sire && ShowRecord::key($lot->sire_name) !== ShowRecord::key($sire) => 'sire',
            $lot->dam_name && $dam && ShowRecord::key($lot->dam_name) !== ShowRecord::key($dam) => 'dam',
            $lot->foal_year && $year && $lot->foal_year !== $year => 'foal year',
            $lot->foal_year && ! $this->showsFit($h, $lot->foal_year) => 'show years', // shown before it was foaled
            default => null,
        };
    }

    /** A horse can't have shown before it was foaled. */
    private function showsFit(Horse $h, int $foalYear): bool
    {
        $first = ShowRecord::where('horse_id', $h->id)->min('first_year');

        return ! $first || $first >= $foalYear;
    }
}
