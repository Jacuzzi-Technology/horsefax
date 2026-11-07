<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Actions\Record\FindNameVariants;
use App\Actions\Record\MatchSaleLots;
use App\Actions\Record\MergeDuplicates;
use App\Actions\Record\NamePairRules;
use App\Actions\Record\UnmergeHorses;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseMerge;
use App\Models\NameReview;
use App\Models\PedigreeEntry;
use App\Models\ReviewItem;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The rules that sort possible duplicates, sale matching that stops splitting one horse, and undoing a merge. Names fictional. */
class DuplicateRulesTest extends TestCase
{
    use RefreshDatabase;

    private function side(string $name, array $extra = []): array
    {
        return $extra + ['name' => $name, 'sex' => null, 'year' => null, 'breed' => null, 'sire' => null, 'dam' => null, 'regs' => []];
    }

    private function horse(string $name, array $fields = [], ?string $sire = null, ?string $dam = null, string $source = 'published'): Horse
    {
        $by = BuildRecordsFromResults::system()->id;
        $h = new Horse(['registered_name' => $name, 'birth_precision' => isset($fields['birth_year']) ? 'year' : 'unknown']);
        $h->horsefax_id = CreateHorse::newId();
        $h->created_by = $by;
        $h->forceFill($fields + ['source' => $source, 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        foreach (['s' => $sire, 'd' => $dam] as $position => $parent) {
            if ($parent) {
                PedigreeEntry::create(['horse_id' => $h->id, 'position' => $position, 'name' => $parent, 'visibility' => 'public', 'added_by' => $by]);
            }
        }

        return $h;
    }

    private function lot(string $name, array $extra): SaleLot
    {
        $ds = DataSource::firstOrCreate(['key' => 'billings'], ['name' => 'Billings Livestock', 'url' => 'https://www.billingslivestock.com']);

        return SaleLot::record($ds->id, uniqid(), $extra + ['sale_name' => 'Sample Sale', 'sale_date' => '2010-09-01', 'lot' => '89', 'horse_name' => $name,
            'status' => 'sold', 'price_cents' => 500000, 'source_url' => 'https://www.billingslivestock.com/sample.pdf']);
    }

    public function test_pairs_sort_by_what_the_records_say(): void
    {
        $cases = [
            'same_number' => [$this->side('Sample Dun It', ['regs' => ['AQHA' => ['1' => true]]]), $this->side('Sample Dunit', ['regs' => ['AQHA' => ['1' => true]]]), NamePairRules::SAME],
            'same_name_year_parent' => [$this->side('Sample Flashy', ['year' => 2009, 'sire' => 'Sample Step', 'dam' => 'Sample Tackie', 'sex' => 'gelding']),
                $this->side('SAMPLE FLASHY', ['year' => 2009, 'sire' => 'SAMPLE STEP', 'dam' => 'SAMPLE LADY JOE', 'sex' => 'stallion']), NamePairRules::SAME],
            'same_name_both_parents' => [$this->side('Stop Spot Stop (P)', ['sire' => 'Sample Peppy (Q)', 'dam' => 'Sample Commander']), $this->side('Stop Spot Stop', ['sire' => 'Sample Peppy', 'dam' => 'Sample Comander', 'year' => 2010]), NamePairRules::SAME],
            'near_name_both_parents_year' => [$this->side('Sample Driftwood', ['year' => 2003, 'sire' => 'Sample Man', 'dam' => 'Sample Girl']), $this->side('Sample Drifwood', ['year' => 2003, 'sire' => 'Sample Man', 'dam' => 'Sample Girl']), NamePairRules::SAME],
            'same_name_empty_record' => [$this->side('Sample Treasured Too', ['year' => 1996, 'sire' => 'Sample Jet']), $this->side('Sample Treasured Too (P)'), NamePairRules::SAME],
            'near_name_both_parents' => [$this->side('Sample Painted I', ['sire' => 'Sample Dream', 'dam' => 'Sample Lady']), $this->side('Sample Painted J', ['sire' => 'Sample Dream', 'dam' => 'Sample Lady']), NamePairRules::APPROVE],
            'placeholder_name' => [$this->side('ON APPLICATION'), $this->side('On Application'), NamePairRules::DIFFERENT],
            'different_number_in_name' => [$this->side('Sample Blackburn 1911', ['sire' => 'Sample Sire', 'dam' => 'Sample 080']), $this->side('Sample Blackburn 1912', ['sire' => 'Sample Sire', 'dam' => 'Sample 081']), NamePairRules::DIFFERENT],
            'parent_differs' => [$this->side('Sample Coco', ['sire' => 'Munnings']), $this->side('Sample Coco', ['sire' => 'Casco']), NamePairRules::DIFFERENT],
            'opposite_sex' => [$this->side('Sample Olympia', ['sex' => 'mare']), $this->side('Sample Olympiad', ['sex' => 'stallion']), NamePairRules::DIFFERENT],
            'foal_years_apart' => [$this->side('Sample Domino', ['year' => 1891]), $this->side('Sample Domino', ['year' => 1959]), NamePairRules::DIFFERENT],
            'different_numbers' => [$this->side('Sample Hun', ['regs' => ['MLOSZ' => ['A' => true]]]), $this->side('Sample Hun', ['regs' => ['MLOSZ' => ['B' => true]]]), NamePairRules::DIFFERENT],
            'numbers_differ_parents_match' => [$this->side('Sample Quincy', ['sire' => 'Sample Cougar', 'dam' => 'Sample Wood', 'regs' => ['AQHA' => ['4965176' => true]]]),
                $this->side('Sample Quincy', ['sire' => 'Sample Cougar', 'dam' => 'Sample Wood', 'regs' => ['AQHA' => ['4965173' => true]]]), NamePairRules::STAFF],
            'near_stud_book_name' => [$this->side('Sample Mistik', ['regs' => ['MLOSZ' => ['A' => true]]]), $this->side('Sample Mystik'), NamePairRules::NO_EVIDENCE],
            'near_short_name' => [$this->side('Octane'), $this->side('Octave'), NamePairRules::NO_EVIDENCE],
            'near_name_nothing_to_compare' => [$this->side('Sample Hancocks Boy'), $this->side('Sample Hankcocks Boy'), NamePairRules::STAFF],
            'same_name_nothing_to_compare' => [$this->side('Sample Roxy', ['year' => 2010]), $this->side('Sample Roxy', ['year' => 2011]), NamePairRules::STAFF],
        ];
        foreach ($cases as $rule => [$a, $b, $outcome]) {
            $this->assertSame([$outcome, $rule], NamePairRules::classify($a, $b), $rule);
            $this->assertSame([$outcome, $rule], NamePairRules::classify($b, $a), $rule.' (other way round)');
        }
    }

    public function test_stud_book_numbers_in_the_name_with_different_registrations_are_two_horses(): void
    {
        $parents = ['sire' => 'Sample Sire', 'dam' => 'Sample Dam'];

        $this->assertSame([NamePairRules::DIFFERENT, 'different_numbers'], NamePairRules::classify(
            $this->side('Sample Nonius XXXV', $parents + ['regs' => ['MLOSZ' => ['A' => true]]]),
            $this->side('Sample Nonius XXIV', $parents + ['regs' => ['MLOSZ' => ['B' => true]]]),
        ));
    }

    public function test_a_lot_with_one_wrong_dam_joins_the_record_and_the_other_dam_goes_to_staff(): void
    {
        $horse = $this->horse('Sample Flashy Jac', ['birth_year' => 2009, 'sex' => 'gelding'], 'Sample Little Step', 'Sample Tackie Jac');
        $lot = $this->lot('SAMPLE FLASHY JAC', ['foal_year' => 2009, 'sire_name' => 'SAMPLE LITTLE STEP', 'dam_name' => 'SAMPLE LADY JOE', 'registry' => 'AQHA', 'registration_number' => '5000002']);

        app(MatchSaleLots::class)();

        $this->assertSame($horse->id, $lot->fresh()->horse_id);
        $this->assertSame('foal_year_parent', $lot->fresh()->match_tier);
        $this->assertSame('Sample Tackie Jac', $horse->pedigree()->where('position', 'd')->value('name'));
        $item = ReviewItem::where('horse_id', $horse->id)->where('kind', 'pedigree_conflict')->sole();
        $this->assertStringContainsString('SAMPLE LADY JOE', $item->statement);
    }

    public function test_a_misspelled_or_tagged_parent_or_name_does_not_split_a_horse(): void
    {
        $horse = $this->horse('Sample Spot Stop', [], 'Sample Skeets', 'Sample Custon Spook');
        $lot = $this->lot('Sample Spot Stop (P)', ['sire_name' => 'Sample Skeets (Q)', 'dam_name' => 'Sample Custom Spook', 'registry' => 'APHA']);
        $unrelated = $this->lot('Sample Spot Stop', ['sire_name' => 'Sample Someone Else', 'dam_name' => 'Sample Another Mare']);

        app(MatchSaleLots::class)();

        $this->assertSame($horse->id, $lot->fresh()->horse_id);
        $this->assertSame('conflict', $unrelated->fresh()->review_status);
    }

    public function test_the_daily_sort_closes_contradictions_reopens_wrong_splits_and_leaves_staff_decisions(): void
    {
        $by = BuildRecordsFromResults::system()->id;
        $pair = function (Horse $x, Horse $y, array $row) {
            [$a, $b] = strcmp($x->id, $y->id) < 0 ? [$x, $y] : [$y, $x];
            DB::table('name_reviews')->insert($row + ['horse_a_id' => $a->id, 'horse_b_id' => $b->id, 'match' => 'same_letters', 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);

            return NameReview::where('horse_a_id', $a->id)->where('horse_b_id', $b->id)->sole();
        };
        $open = $pair($this->horse('Sample Vermont', ['birth_year' => 2024], 'Sample Vekoma'), $this->horse('Sample Vermont', ['birth_year' => 1943], 'Sample Javari'), ['status' => 'open']);
        $split = $pair($this->horse('Sample Wrong Split', ['birth_year' => 2009], 'Sample Step', 'Sample Tackie'), $this->horse('SAMPLE WRONG SPLIT', ['birth_year' => 2009], 'Sample Step', 'Sample Joe'),
            ['status' => 'different_horses', 'note' => 'Automatic: the Sample Sale lot contradicts HF-AAAA-AAAA (dam).']);
        $staff = $pair($this->horse('Sample Staff Said', ['birth_year' => 2009], 'Sample Step', 'Sample Tackie'), $this->horse('SAMPLE STAFF SAID', ['birth_year' => 2009], 'Sample Step', 'Sample Joe'),
            ['status' => 'different_horses', 'note' => 'Checked the papers.', 'decided_by' => $by]);
        $this->horse('Sample Contradicted', ['birth_year' => 1990], 'Sample First');
        $this->horse('Sample Contradicted', ['birth_year' => 2020], 'Sample Second');

        $find = app(FindNameVariants::class);
        $find();

        $this->assertSame('closed', $open->fresh()->status);
        $this->assertSame(['open', 'same_name_year_parent'], [$split->fresh()->status, $split->fresh()->rule]);
        $this->assertSame('different_horses', $staff->fresh()->status);
        $this->assertSame(0, NameReview::whereHas('a', fn ($q) => $q->where('registered_name', 'Sample Contradicted'))->count());
    }

    public function test_same_horse_pairs_merge_owned_ones_wait_and_a_merge_can_be_undone(): void
    {
        $keep = $this->horse('Sample Merge Me', ['birth_year' => 2009, 'sex' => 'stallion', 'color' => 'Dun'], 'Sample Step', 'Sample Lady Joe');
        DB::table('horse_identifiers')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'horse_id' => $keep->id, 'kind' => 'registration', 'registry' => 'AQHA', 'value' => '5000003',
            'value_normalized' => '5000003', 'visibility' => 'public', 'source_type' => 'historical', 'status' => 'unverified', 'in_conflict' => false, 'added_by' => $keep->created_by, 'created_at' => now(), 'updated_at' => now()]);
        $gone = $this->horse("Sample Merge-Me", ['birth_year' => 2009, 'sex' => 'gelding', 'color' => 'Buckskin'], 'Sample Step', 'Sample Tackie');
        $event = HorseEvent::create(['horse_id' => $gone->id, 'category' => 'competition', 'type' => 'show', 'title' => 'Sample Class', 'contributor_id' => $gone->created_by,
            'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public', 'occurred_on' => '2020-10-01', 'date_precision' => 'year']);
        $foal = $this->horse('Sample Foal Of Him');
        $chart = PedigreeEntry::create(['horse_id' => $foal->id, 'position' => 's', 'name' => 'Sample Merge-Me', 'linked_horse_id' => $gone->id, 'visibility' => 'public', 'added_by' => $foal->created_by]);

        $owner = \App\Models\User::factory()->create();
        $owned = app(CreateHorse::class)($owner, ['registered_name' => 'Sample Owned Twice', 'birth_precision' => 'unknown']);
        $this->horse('Sample Owned Twice', ['birth_year' => 2001], 'Sample Owner Sire');

        app(FindNameVariants::class)();
        $this->assertSame(1, NameReview::where('rule', 'same_name_year_parent')->count());

        $dry = app(MergeDuplicates::class)(null, true);
        $this->assertSame(1, $dry['merged']);
        $this->assertNull($gone->fresh()->merged_into_id);

        $out = app(MergeDuplicates::class)(null, false, null, 'sample-batch');
        $this->assertSame(1, $out['merged']);
        $this->assertSame(1, $out['left_for_staff']);
        $this->assertNull($owned->fresh()->merged_into_id);

        $this->assertSame($keep->id, $gone->fresh()->merged_into_id);
        $this->assertSame($keep->id, $event->fresh()->horse_id);
        $this->assertSame('gelding', $keep->fresh()->sex); // a later state, no conflict
        $this->assertSame($keep->id, $chart->fresh()->linked_horse_id);
        $kinds = ReviewItem::where('horse_id', $keep->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['fact_conflict', 'pedigree_conflict'], $kinds); // color and dam are raised, sex isn't

        $merge = HorseMerge::where('batch', 'sample-batch')->sole();
        $this->assertNull(app(UnmergeHorses::class)($merge, BuildRecordsFromResults::system()));

        $this->assertNull($gone->fresh()->merged_into_id);
        $this->assertSame($gone->id, $event->fresh()->horse_id);
        $this->assertSame('stallion', $keep->fresh()->sex);
        $this->assertSame([$gone->id, 'Sample Merge-Me'], [$chart->fresh()->linked_horse_id, $chart->fresh()->name]);
        $this->assertSame('Sample Tackie', $gone->pedigree()->where('position', 'd')->value('name'));
        $this->assertSame(0, ReviewItem::where('horse_id', $keep->id)->count());
        $this->assertFalse($keep->names()->where('name', 'Sample Merge-Me')->exists());

        // It goes back to staff and the rules leave it there.
        $review = NameReview::where('rule', 'undone')->sole();
        $this->assertSame('open', $review->status);
        app(FindNameVariants::class)();
        $this->assertSame('undone', $review->fresh()->rule);
        $this->assertSame(0, app(MergeDuplicates::class)()['merged']);
    }
}
