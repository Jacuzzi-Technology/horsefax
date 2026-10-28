<?php

namespace App\Support;

use App\Models\Horse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How far back a horse's pedigree is traced (Richard, Oct 28 2026: the chart shows four generations, but clicking
 * through the linked ancestors usually goes much further, and "Pedigree: 2 generations" undersold it). Walks the
 * public sire and dam links record to record, the path a reader can click, one generation at a time. Each ancestor is
 * visited once, so a bad link that loops back can't inflate the count (a first version counted Spooks Gotta Whiz at
 * 43 through an "Ismeretlen" (unknown) record that was its own parent), and placeholder names ("Unknown", "NN") don't
 * count. Capped at 40 generations; cached a day per horse.
 */
class PedigreeDepth
{
    public const CAP = 40;

    public static function traced(Horse $horse): int
    {
        if (! $horse->exists || ! $horse->id) {
            return 0; // the sample report's example horse
        }

        return Cache::remember('pedigree-depth:'.$horse->id, now()->addDay(), fn () => self::compute($horse->id));
    }

    public static function compute(string $horseId): int
    {
        $seen = [$horseId => true];
        $frontier = [$horseId];
        $depth = 0;
        for ($gen = 0; $frontier && $gen < self::CAP; $gen++) {
            $lines = DB::table('pedigree_entries')->whereIn('horse_id', $frontier)->whereNull('superseded_by')->where('visibility', 'public')
                ->get(['position', 'name', 'linked_horse_id']);
            $next = [];
            foreach ($lines as $l) {
                if (Record::placeholderName($l->name)) {
                    continue;
                }
                $depth = max($depth, min(self::CAP, $gen + strlen($l->position))); // unlinked deeper lines on this record count too
                if ($l->linked_horse_id && in_array($l->position, ['s', 'd'], true) && ! isset($seen[$l->linked_horse_id])) {
                    $seen[$l->linked_horse_id] = true;
                    $next[] = $l->linked_horse_id;
                }
            }
            $frontier = $next;
        }

        return $depth;
    }
}
