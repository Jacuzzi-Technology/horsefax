<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\NameReview;
use App\Support\HorseNames;
use Illuminate\Support\Facades\DB;

/**
 * Finds pairs of records whose registered names are the same apart from spacing and punctuation, or a letter or two
 * apart (both names share a one-letter-deleted form, or match once doubled letters are made single), then sorts them
 * with NamePairRules (Nov 2 2026): contradicted pairs and pairs with nothing to go on stay off the staff list, and open
 * ones that now read that way close. MergeDuplicates merges the ones marked same. An automatic "different horses" that
 * the rules no longer agree with reopens; a decision made by staff is never touched.
 *
 * Priority (lower first): 1 same letters; 2 near, and one side is only known from a pedigree chart (a typo is
 * likeliest there); 3 near, one side has no results of its own; 4 near, both have their own results (usually two
 * real horses with similar names).
 */
class FindNameVariants
{
    /**
     * More records than this under one name means a common name, not a typo, so they aren't paired (Nov 1 2026: Hungarian
     * stud book names such as Csillag and Baba sit on 1,000+ records each, about 6 million pairs, and ran the job out of memory).
     */
    private const CROWDED = 12;

    private const SHARES = 16;

    /** @var array{new:int, closed:int, reopened:int, sorted:int} */
    public array $stats = ['new' => 0, 'closed' => 0, 'reopened' => 0, 'sorted' => 0];

    public function __invoke(): int
    {
        // National registry lists (IFCE, millions of names) aren't compared: they're never matched by name anyway.
        $rows = DB::table('horses')->whereNull('merged_into_id')->whereNotNull('registered_name')->where(fn ($w) => $w->whereNull('source')->orWhere('source', '!=', 'ifce'))
            ->select('id', 'registered_name', 'source')->orderBy('id')->get();
        $byKey = [];
        foreach ($rows as $r) {
            $k = HorseNames::key($r->registered_name);
            if ($k !== '' && ! \App\Support\Record::placeholderName($r->registered_name)) { // "Pending" isn't a name
                $byKey[$k][] = $r;
            }
        }
        $withResults = DB::table('horse_events')->where('category', 'competition')->distinct()->pluck('horse_id')->flip();
        $pairs = [];
        // A short string for each pair, not an array: there are a few hundred thousand before they're sorted.
        $add = function ($x, $y, string $match) use (&$pairs, $withResults) {
            [$a, $b] = strcmp($x->id, $y->id) < 0 ? [$x, $y] : [$y, $x];
            if ($match === 'same_letters') {
                $p = 1;
            } elseif ($a->source === 'pedigree' || $b->source === 'pedigree') {
                $p = 2;
            } else {
                $p = isset($withResults[$a->id]) && isset($withResults[$b->id]) ? 4 : 3;
            }
            $pairs[$a->id.'|'.$b->id] = $match.'|'.$p;
        };
        foreach ($byKey as $group) {
            if (count($group) > self::CROWDED) {
                continue;
            }
            for ($i = 0; $i < count($group); $i++) {
                for ($j = $i + 1; $j < count($group); $j++) {
                    $add($group[$i], $group[$j], 'same_letters');
                }
            }
        }
        // Near names: keys of 6+ letters that share a form with one letter removed.
        $names = array_map('strval', array_keys(array_filter($byKey, fn ($group) => count($group) <= self::CROWDED)));
        // One share of the forms at a time: all of them at once (about 8 million) don't fit in memory.
        for ($share = 0; $share < self::SHARES; $share++) {
            $buckets = [];
            $put = function (string $form, string $k) use (&$buckets, $share) {
                if (crc32($form) % self::SHARES === $share) {
                    $buckets[$form][$k] = true;
                }
            };
            foreach ($names as $k) {
                if (strlen($k) < 6) {
                    continue;
                }
                $put($k, $k);
                // Doubled letters count as one ("Gota Lota" / "Gotta Lotta").
                $put('~'.preg_replace('/(.)\1+/', '$1', $k), $k);
                for ($i = 0; $i < strlen($k); $i++) {
                    $put(substr($k, 0, $i).substr($k, $i + 1), $k);
                }
            }
            foreach ($buckets as $keys) {
                if (count($keys) < 2 || count($keys) > self::CROWDED) {
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
        }
        unset($rows, $byKey, $names, $buckets, $withResults);
        $this->sort($pairs);

        return $this->stats['new'];
    }

    /** @param array<string, string> $pairs "a|b" => "match|priority" */
    private function sort(array $pairs): void
    {
        // Open pairs and automatic decisions are sorted again; staff decisions and finished pairs are left alone.
        $existing = [];
        foreach (NameReview::select('id', 'horse_a_id', 'horse_b_id', 'match', 'priority', 'status', 'note', 'rule', 'decided_by')->toBase()->cursor() as $r) {
            $k = $r->horse_a_id.'|'.$r->horse_b_id;
            $automatic = $r->status === 'different_horses' && ! $r->decided_by && str_starts_with((string) $r->note, 'Automatic:');
            if ($r->match !== 'barn_name' && ($r->status === 'open' || $automatic)) {
                $existing[$k] = $r;
                $pairs[$k] ??= $r->match.'|'.$r->priority;
            } else {
                unset($pairs[$k]);
            }
        }
        foreach (array_chunk(array_keys($pairs), 500) as $chunk) {
            $ids = [];
            foreach ($chunk as $k) {
                [$ids[], $ids[]] = explode('|', $k);
            }
            $facts = NamePairRules::load(array_values(array_unique($ids)));
            $insert = [];
            foreach ($chunk as $k) {
                [$a, $b] = explode('|', $k);
                if (! isset($facts[$a], $facts[$b])) {
                    continue;
                }
                [$outcome, $rule] = NamePairRules::classify($facts[$a], $facts[$b]);
                $listed = ! in_array($outcome, [NamePairRules::DIFFERENT, NamePairRules::NO_EVIDENCE], true);
                $tag = in_array($outcome, [NamePairRules::SAME, NamePairRules::APPROVE], true) ? $rule : null;
                $row = $existing[$k] ?? null;
                if (! $row) {
                    if ($listed) {
                        [$match, $priority] = explode('|', $pairs[$k]);
                        $insert[] = ['horse_a_id' => $a, 'horse_b_id' => $b, 'match' => $match, 'priority' => (int) $priority, 'rule' => $tag, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()];
                    }
                } elseif ($row->status === 'open' && $row->rule !== 'undone') {
                    if (! $listed) {
                        NameReview::where('id', $row->id)->update(['status' => 'closed', 'rule' => $rule, 'note' => 'Automatic: '.str_replace('_', ' ', $rule).'.', 'decided_at' => now(), 'updated_at' => now()]);
                        $this->stats['closed']++;
                    } elseif ($row->rule !== $tag) {
                        NameReview::where('id', $row->id)->update(['rule' => $tag, 'updated_at' => now()]);
                        $this->stats['sorted']++;
                    }
                } elseif ($row->status === 'different_horses' && $listed) {
                    NameReview::where('id', $row->id)->update(['status' => 'open', 'rule' => $tag, 'note' => 'Reopened: was '.$row->note, 'decided_at' => null, 'updated_at' => now()]);
                    $this->stats['reopened']++;
                }
            }
            if ($insert) {
                $this->stats['new'] += NameReview::insertOrIgnore($insert);
            }
        }
    }
}
