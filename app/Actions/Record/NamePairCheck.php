<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\PedigreeEntry;
use App\Models\ShowRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What two possibly-duplicate records say about each other: where they disagree (registration number, sire, dam, foal
 * year, sex, or one shown before the other was foaled), whether either is owned, and which spelling to keep by default.
 * Loads everything for a page of pairs at once. Never decides: staff do (Richard, Oct 19 2026).
 */
class NamePairCheck
{
    /** @var array<string, Collection> */
    public array $facts = [];

    /** @param list<string> $ids horse ids */
    public function load(array $ids): static
    {
        $this->facts = [
            'parents' => PedigreeEntry::whereIn('horse_id', $ids)->whereIn('position', ['s', 'd'])->whereNull('superseded_by')->get()->groupBy('horse_id'),
            'results' => DB::table('horse_events')->whereIn('horse_id', $ids)->where('category', 'competition')->selectRaw('horse_id, count(*) n')->groupBy('horse_id')->pluck('n', 'horse_id'),
            'shown' => ShowRecord::whereIn('horse_id', $ids)->selectRaw('horse_id, min(first_year) a, max(last_year) b')->groupBy('horse_id')->get()->keyBy('horse_id'),
            'charts' => PedigreeEntry::whereIn('linked_horse_id', $ids)->selectRaw('linked_horse_id, count(*) n')->groupBy('linked_horse_id')->pluck('n', 'linked_horse_id'),
            'regs' => DB::table('horse_identifiers')->whereIn('horse_id', $ids)->where('kind', 'registration')->whereNull('superseded_by')->get()->groupBy('horse_id'),
            'owned' => DB::table('stewardships')->whereIn('horse_id', $ids)->where('status', '!=', 'ended')->pluck('horse_id')->flip(),
        ];

        return $this;
    }

    /** @return list<string> plain-English disagreements, empty when nothing contradicts */
    public function disagreements(Horse $a, Horse $b): array
    {
        $w = [];
        $regs = fn (Horse $h) => collect($this->facts['regs'][$h->id] ?? [])->pluck('value_normalized')->filter(fn ($v) => preg_match('/\d/', (string) $v))->all();
        [$ra, $rb] = [$regs($a), $regs($b)];
        if ($ra && $rb && ! array_intersect($ra, $rb)) {
            $w[] = 'Registration numbers disagree.';
        }
        $ya = $a->birth_precision !== 'unknown' ? (int) $a->birth_year : null;
        $yb = $b->birth_precision !== 'unknown' ? (int) $b->birth_year : null;
        if ($ya && $yb && $ya !== $yb) {
            $w[] = 'Foal years disagree ('.$ya.' left, '.$yb.' right).';
        }
        // A filly grows into a mare and a colt into a stallion; any other difference (a gelding, the other sex) counts.
        $sex = fn ($s) => ['filly' => 'mare', 'colt' => 'stallion'][strtolower((string) $s)] ?? strtolower((string) $s);
        if ($a->sex && $b->sex && $sex($a->sex) !== $sex($b->sex)) {
            $w[] = 'Sex disagrees ('.$a->sex.' left, '.$b->sex.' right).';
        }
        foreach (['s' => 'Sire', 'd' => 'Dam'] as $pos => $label) {
            $na = collect($this->facts['parents'][$a->id] ?? [])->firstWhere('position', $pos)?->name;
            $nb = collect($this->facts['parents'][$b->id] ?? [])->firstWhere('position', $pos)?->name;
            if ($na && $nb && ShowRecord::key($na) !== ShowRecord::key($nb)) {
                $w[] = $label.'s disagree ('.$na.' left, '.$nb.' right).';
            }
        }
        // Shown before the other record's foal year: can't be one horse.
        foreach ([[$a, $yb, 'left', 'right'], [$b, $ya, 'right', 'left']] as [$h, $year, $side, $other]) {
            $first = $this->facts['shown'][$h->id]->a ?? null;
            if ($year && $first && (int) $first < $year) {
                $w[] = 'The '.$side.' record was shown in '.$first.', before the '.$other.' one was foaled ('.$year.').';
            }
        }

        return $w;
    }

    public function owned(Horse $h): bool
    {
        return isset($this->facts['owned'][$h->id]);
    }

    /**
     * Which record to keep by default: an owned one (it can't be merged away), then one with a registration number,
     * then the spelling from show results (shows print the registered name), then the one with more results and more
     * pedigree charts, then the older record. Staff can switch it.
     */
    public function suggestedKeep(Horse $a, Horse $b): Horse
    {
        $score = fn (Horse $h) => [
            $this->owned($h) ? 1 : 0,
            collect($this->facts['regs'][$h->id] ?? [])->contains(fn ($r) => preg_match('/\d/', (string) $r->value_normalized)) ? 1 : 0,
            ['results' => 3, 'published' => 2, 'pedigree' => 1][$h->source] ?? 3,
            (int) ($this->facts['results'][$h->id] ?? 0),
            (int) ($this->facts['charts'][$h->id] ?? 0),
        ];
        $c = $score($a) <=> $score($b);

        return $c > 0 ? $a : ($c < 0 ? $b : (strcmp($a->id, $b->id) <= 0 ? $a : $b));
    }
}
