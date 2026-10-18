<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\NameReview;
use Illuminate\Support\Facades\DB;

/**
 * Finds pairs of records whose registered names are the same apart from spacing and punctuation, or a letter or two
 * apart (both names share a one-letter-deleted form, or match once doubled letters are made single). Adds new pairs to the staff list; never decides anything.
 *
 * Priority (lower first): 1 same letters; 2 near, and one side is only known from a pedigree chart (a typo is
 * likeliest there); 3 near, one side has no results of its own; 4 near, both have their own results (usually two
 * real horses with similar names).
 */
class FindNameVariants
{
    public function __invoke(): int
    {
        // National registry lists (IFCE, millions of names) aren't compared: they're never matched by name anyway.
        $rows = DB::table('horses')->whereNull('merged_into_id')->whereNotNull('registered_name')->where(fn ($w) => $w->whereNull('source')->orWhere('source', '!=', 'ifce'))
            ->select('id', 'registered_name', 'source')->orderBy('id')->get();
        $byKey = [];
        foreach ($rows as $r) {
            $k = preg_replace('/[^A-Z0-9]/', '', strtoupper($r->registered_name));
            if ($k !== '') {
                $byKey[$k][] = $r;
            }
        }
        $withResults = DB::table('horse_events')->where('category', 'competition')->distinct()->pluck('horse_id')->flip();
        $pairs = [];
        $add = function ($x, $y, string $match) use (&$pairs, $withResults) {
            [$a, $b] = strcmp($x->id, $y->id) < 0 ? [$x, $y] : [$y, $x];
            if ($match === 'same_letters') {
                $p = 1;
            } elseif ($a->source === 'pedigree' || $b->source === 'pedigree') {
                $p = 2;
            } else {
                $p = isset($withResults[$a->id]) && isset($withResults[$b->id]) ? 4 : 3;
            }
            $pairs[$a->id.'|'.$b->id] = ['horse_a_id' => $a->id, 'horse_b_id' => $b->id, 'match' => $match, 'priority' => $p];
        };
        foreach ($byKey as $group) {
            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    $add($group[$i], $group[$j], 'same_letters');
                }
            }
        }
        // Near names: keys of 6+ letters that share a form with one letter removed.
        $buckets = [];
        foreach (array_keys($byKey) as $k) {
            $k = (string) $k;
            if (strlen($k) < 6) {
                continue;
            }
            $buckets[$k][$k] = true;
            // Doubled letters count as one ("Gota Lota" / "Gotta Lotta").
            $buckets['~'.preg_replace('/(.)\1+/', '$1', $k)][$k] = true;
            for ($i = 0; $i < strlen($k); $i++) {
                $buckets[substr($k, 0, $i).substr($k, $i + 1)][$k] = true;
            }
        }
        foreach ($buckets as $keys) {
            if (count($keys) < 2 || count($keys) > 12) {
                continue; // a very crowded form is a common short word pattern, not a typo
            }
            $keys = array_keys($keys);
            for ($i = 0; $i < count($keys); $i++) {
                for ($j = $i + 1; $j < count($keys); $j++) {
                    foreach ($byKey[$keys[$i]] as $x) {
                        foreach ($byKey[$keys[$j]] as $y) {
                            $add($x, $y, 'near');
                        }
                    }
                }
            }
        }
        $new = 0;
        foreach (array_chunk(array_values($pairs), 500) as $chunk) {
            $new += NameReview::insertOrIgnore(array_map(fn ($p) => $p + ['status' => 'open', 'created_at' => now(), 'updated_at' => now()], $chunk));
        }

        return $new;
    }
}
