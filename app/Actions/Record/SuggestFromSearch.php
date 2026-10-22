<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\NameReview;
use App\Models\PedigreeEntry;
use App\Models\ReviewSuggestion;
use App\Models\SaleLot;
use App\Models\ShowRecord;
use App\Search\WebEvidence;
use Illuminate\Support\Facades\DB;

/**
 * Search-backed suggestions for the staff queues (Richard, Oct 19 2026: rules first, then suggestions staff accept with
 * a click). For a possible duplicate pair or a sale lot waiting for review: one web search for the name, then the
 * pages on ALLOWED sources are read (WebEvidence) for the facts printed beside the name. Each mention is compared with
 * both sides:
 *  - one mention agrees with both and contradicts neither: same horse (or: the lot belongs to that record);
 *  - one mention fits only the left side and another only the right: different horses (two horses of that name);
 *  - anything else: no answer, and staff see the pages read.
 * Agreement needs a registration number, foal year, sire or dam in common; a matching sex alone doesn't count. Never
 * applied without staff: ReviewSuggestionController does that on "accept".
 */
class SuggestFromSearch
{
    public function __construct(private ?WebEvidence $web = null) {}

    public function forPair(NameReview $review): ReviewSuggestion
    {
        [$a, $b] = [$review->a, $review->b];
        [$pages, $searches, $fetched] = $this->read($a->registered_name);
        [$pa, $pb] = [self::profile($a), self::profile($b)];
        $v = self::weigh($pages, $pa, $pb);
        $names = ['the left record ('.$a->horsefax_id.')', 'the right record ('.$b->horsefax_id.')'];
        [$suggestion, $horseId] = match ($v['verdict']) {
            'same' => ['same_horse', app(NamePairCheck::class)->load([$a->id, $b->id])->suggestedKeep($a, $b)->id],
            'different' => ['different_horses', null],
            default => ['unsure', null],
        };

        return $this->save('name_review', (string) $review->id, $suggestion, $horseId, self::summary($v, $names, $pages), $v['evidence'], $searches, $fetched);
    }

    public function forLot(SaleLot $lot): ReviewSuggestion
    {
        $records = MatchSaleLots::matchable(Horse::whereRaw('lower(registered_name) = lower(?)', [trim($lot->horse_name)]))->get();
        [$pages, $searches, $fetched] = $this->read($lot->horse_name);
        $pl = self::lotProfile($lot);
        $same = [];
        $different = 0;
        $evidence = [];
        $lines = [];
        foreach ($records as $h) {
            $v = self::weigh($pages, $pl, self::profile($h));
            $evidence = array_merge($evidence, array_map(fn ($e) => $e + ['record' => $h->horsefax_id], $v['evidence']));
            if ($v['verdict'] === 'same') {
                $same[] = $h;
            } elseif ($v['verdict'] === 'different') {
                $different++;
            }
            $lines[] = self::summary($v, ['the sale lot', $h->horsefax_id], $pages);
        }
        if (count($same) === 1 && ! $different) {
            [$suggestion, $horseId] = ['lot_belongs', $same[0]->id];
        } elseif ($records->isNotEmpty() && $different === $records->count()) {
            [$suggestion, $horseId] = ['different_horses', null];
        } else {
            [$suggestion, $horseId] = ['unsure', null];
        }
        $summary = $records->isEmpty() ? 'No unmerged record has this name any more.' : implode(' ', array_unique($lines));

        return $this->save('sale_lot', (string) $lot->id, $suggestion, $horseId, $summary, $evidence, $searches, $fetched);
    }

    /** @return array{0: list<array{url:string, facts:list<array>}>, 1:int, 2:int} pages with facts, searches, fetches */
    private function read(string $name): array
    {
        $web = $this->web ??= new WebEvidence;
        [$s0, $f0] = [$web->searches, $web->fetched];
        $pages = [];
        foreach (array_slice($web->search('"'.trim($name).'" horse'), 0, (int) config('horsefax.search.max_pages_per_item', 5)) as $url) {
            $text = $web->text($url);
            $facts = $text ? WebEvidence::facts($text, $name) : [];
            if ($facts) {
                $pages[] = ['url' => $url, 'facts' => $facts];
            }
        }

        return [$pages, $web->searches - $s0, $web->fetched - $f0];
    }

    /** What a record says about itself, for comparing. */
    public static function profile(Horse $h): array
    {
        $p = PedigreeEntry::where('horse_id', $h->id)->whereIn('position', ['s', 'd'])->whereNull('superseded_by')->pluck('name', 'position');

        return [
            'registration' => DB::table('horse_identifiers')->where('horse_id', $h->id)->where('kind', 'registration')->whereNull('superseded_by')
                ->pluck('value_normalized')->filter(fn ($v) => preg_match('/\d/', (string) $v))->values()->all(),
            'foal_year' => $h->birth_precision !== 'unknown' && $h->birth_year ? (int) $h->birth_year : null,
            'sex' => $h->sex, 'sire' => $p['s'] ?? null, 'dam' => $p['d'] ?? null,
            'first_shown' => ShowRecord::where('horse_id', $h->id)->min('first_year'),
        ];
    }

    public static function lotProfile(SaleLot $lot): array
    {
        return [
            'registration' => preg_match('/\d/', (string) $lot->registration_number) ? [\App\Support\Record::normalizeIdentifier($lot->registration_number)] : [],
            'foal_year' => $lot->foal_year, 'sex' => $lot->sex ? strtolower(trim($lot->sex)) : null,
            'sire' => $lot->sire_name, 'dam' => $lot->dam_name, 'first_shown' => null,
        ];
    }

    /** @return array{agree: list<string>, contradict: list<string>} */
    public static function compare(array $f, array $p): array
    {
        $agree = [];
        $contradict = [];
        if (isset($f['registration']) && $p['registration']) {
            in_array(\App\Support\Record::normalizeIdentifier($f['registration']), $p['registration'], true) ? $agree[] = 'registration number' : $contradict[] = 'registration number';
        }
        if (isset($f['foal_year']) && $p['foal_year']) {
            $f['foal_year'] === $p['foal_year'] ? $agree[] = 'foal year' : $contradict[] = 'foal year';
        }
        if (isset($f['foal_year']) && $p['first_shown'] && $p['first_shown'] < $f['foal_year']) {
            $contradict[] = 'show years';
        }
        foreach (['sire', 'dam'] as $k) {
            if (isset($f[$k]) && $p[$k]) {
                ShowRecord::key($f[$k]) === ShowRecord::key($p[$k]) ? $agree[] = $k : $contradict[] = $k;
            }
        }
        $sex = fn ($s) => ['filly' => 'mare', 'colt' => 'stallion', 'm' => 'mare', 'f' => 'mare', 'g' => 'gelding', 's' => 'stallion', 'c' => 'stallion'][strtolower((string) $s)] ?? strtolower((string) $s);
        if (isset($f['sex']) && $p['sex'] && $sex($f['sex']) !== $sex($p['sex'])) {
            $contradict[] = 'sex';
        }

        return ['agree' => $agree, 'contradict' => array_values(array_unique($contradict))];
    }

    /** @return array{verdict: string, evidence: list<array>, same: int, left: int, right: int} */
    public static function weigh(array $pages, array $left, array $right): array
    {
        $evidence = [];
        $n = ['same' => 0, 'left' => 0, 'right' => 0];
        foreach ($pages as $page) {
            foreach ($page['facts'] as $f) {
                [$l, $r] = [self::compare($f, $left), self::compare($f, $right)];
                $fitsL = $l['agree'] && ! $l['contradict'];
                $fitsR = $r['agree'] && ! $r['contradict'];
                $supports = match (true) {
                    $fitsL && $fitsR => 'same',
                    $fitsL && (bool) $r['contradict'] => 'left',
                    $fitsR && (bool) $l['contradict'] => 'right',
                    default => null,
                };
                if ($supports) {
                    $n[$supports]++;
                }
                $evidence[] = ['url' => $page['url'], 'facts' => $f, 'supports' => $supports,
                    'left' => $l, 'right' => $r];
            }
        }
        $verdict = match (true) {
            $n['same'] > 0 && ! $n['left'] && ! $n['right'] => 'same',
            $n['left'] > 0 && $n['right'] > 0 && ! $n['same'] => 'different',
            default => 'unsure',
        };

        return ['verdict' => $verdict, 'evidence' => $evidence] + $n;
    }

    /** Horsefax's own one-paragraph explanation; never text copied from a search result. */
    public static function summary(array $v, array $names, array $pages): string
    {
        if (! $pages) {
            return 'The search found no page on an approved source listing this name with a registration number, foal year, sire or dam.';
        }
        $host = fn ($u) => preg_replace('/^www\./', '', (string) parse_url($u, PHP_URL_HOST));
        $say = function (string $kind) use ($v, $host) {
            $e = collect($v['evidence'])->firstWhere('supports', $kind);

            return $e ? $host($e['url']).' lists it with '.self::describe($e['facts']) : '';
        };

        return match ($v['verdict']) {
            'same' => ucfirst($say('same')).', which agrees with both '.$names[0].' and '.$names[1].' and contradicts neither.',
            'different' => 'Two horses of this name are published: '.$say('left').' (fits '.$names[0].' only), and '.$say('right').' (fits '.$names[1].' only).',
            default => match (true) {
                $v['left'] > 0 && $v['same'] === 0 => ucfirst($say('left')).': fits '.$names[0].' and contradicts '.$names[1].'. Nothing published for the other one.',
                $v['right'] > 0 && $v['same'] === 0 => ucfirst($say('right')).': fits '.$names[1].' and contradicts '.$names[0].'. Nothing published for the other one.',
                $v['same'] > 0 => 'The pages disagree: some fit both records and some fit only one.',
                default => 'Pages on approved sources list this name, but what they print can\'t be compared with these records.',
            },
        };
    }

    public static function describe(array $f): string
    {
        return collect([
            isset($f['registration']) ? 'registration number '.$f['registration'] : null,
            isset($f['foal_year']) ? 'foal year '.$f['foal_year'] : null,
            $f['sex'] ?? null,
            isset($f['sire']) ? 'sire '.$f['sire'] : null,
            isset($f['dam']) ? 'dam '.$f['dam'] : null,
        ])->filter()->join(', ');
    }

    private function save(string $type, string $id, string $suggestion, ?string $horseId, string $summary, array $evidence, int $searches, int $fetched): ReviewSuggestion
    {
        return ReviewSuggestion::updateOrCreate(['subject_type' => $type, 'subject_id' => $id], [
            'suggestion' => $suggestion, 'horse_id' => $horseId, 'summary' => $summary, 'evidence' => $evidence,
            'searches' => $searches, 'pages_fetched' => $fetched, 'cost_cents' => $searches * (float) config('horsefax.search.cost_per_search_cents', 0.5),
            'status' => 'pending', 'decided_by' => null, 'decided_at' => null,
        ]);
    }
}
