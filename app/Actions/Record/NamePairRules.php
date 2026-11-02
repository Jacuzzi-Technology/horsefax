<?php

namespace App\Actions\Record;

use App\Support\HorseNames;

/**
 * Sorts a possible-duplicate pair by what the two records say (Richard, Nov 2 2026): same horse, merged automatically;
 * same horse but a sibling is possible, one click from staff; contradicted, so two horses; nothing to go on and not
 * worth anyone's time; or left for staff. Pure: the caller loads the facts, here and again just before merging.
 *
 * A side is ['name', 'sex', 'year', 'breed', 'sire', 'dam', 'regs' => [registry => [number => true]]].
 */
class NamePairRules
{
    public const SAME = 'same';
    public const APPROVE = 'approve';
    public const DIFFERENT = 'different';
    public const NO_EVIDENCE = 'no_evidence';
    public const STAFF = 'staff';

    /** Registered names are unique within a registry, so the same name with a pedigree that agrees is one horse. */
    public const LABELS = [
        'same_number' => 'The same registration number',
        'same_name_year_parent' => 'Same name, same foal year, a parent matches',
        'same_name_both_parents' => 'Same name, both parents match',
        'near_name_both_parents_year' => 'A letter or two apart, both parents match, same foal year',
        'same_name_empty_record' => 'Same name, one record has nothing else on it',
        'same_name_one_parent' => 'Same name, one parent matches, the other unknown',
        'near_name_both_parents' => 'A letter or two apart, both parents match, a foal year is missing',
    ];

    /** @return array{0: string, 1: string} outcome, rule */
    public static function classify(array $a, array $b): array
    {
        if (HorseNames::notAName($a['name']) || HorseNames::notAName($b['name'])) {
            return [self::DIFFERENT, 'placeholder_name'];
        }
        $sameLetters = HorseNames::key($a['name']) === HorseNames::key($b['name']);
        $sire = HorseNames::compareParents($a['sire'], $b['sire']);
        $dam = HorseNames::compareParents($a['dam'], $b['dam']);
        $both = HorseNames::parentsAgree($sire) && HorseNames::parentsAgree($dam);
        $one = HorseNames::parentsAgree($sire) || HorseNames::parentsAgree($dam);
        $gap = $a['year'] && $b['year'] ? abs($a['year'] - $b['year']) : null;
        $opposite = HorseNames::oppositeSex($a['sex'], $b['sex']);
        $numbered = HorseNames::differOnlyByNumber($a['name'], $b['name']);

        $registries = array_intersect_key($a['regs'], $b['regs']);
        foreach ($registries as $registry => $values) {
            if (array_intersect_key($values, $b['regs'][$registry])) {
                return [self::SAME, 'same_number'];
            }
        }
        if ($registries) {
            // One digit apart in a catalog, or a stud book's two entries for one imported horse: parents settle which.
            return $both && ($gap === null || $gap < 2) ? [self::STAFF, 'numbers_differ_parents_match'] : [self::DIFFERENT, 'different_numbers'];
        }
        if ($numbered && ! $both) {
            return [self::DIFFERENT, 'different_number_in_name'];
        }
        if (! $opposite && ! $numbered) {
            if ($sameLetters && $gap === 0 && $one) {
                return [self::SAME, 'same_name_year_parent'];
            }
            if ($sameLetters && $both && ($gap === null || $gap === 1)) {
                return [self::SAME, 'same_name_both_parents'];
            }
            if (! $sameLetters && $both && $gap === 0) {
                return [self::SAME, 'near_name_both_parents_year'];
            }
            if (! $sameLetters && $both && ($gap === null || $gap === 1)) {
                return [self::APPROVE, 'near_name_both_parents'];
            }
        }
        if (($sire === 'diff' || $dam === 'diff') && ! $one) {
            return [self::DIFFERENT, 'parent_differs'];
        }
        if ($sire === 'diff' || $dam === 'diff') {
            return [self::STAFF, 'one_parent_differs'];
        }
        if ($opposite) {
            return $sameLetters ? [self::STAFF, 'same_name_opposite_sex'] : [self::DIFFERENT, 'opposite_sex'];
        }
        if ($gap !== null && $gap >= 2) {
            return $both ? [self::STAFF, 'parents_match_years_apart'] : [self::DIFFERENT, 'foal_years_apart'];
        }
        if ($a['breed'] && $b['breed'] && HorseNames::key($a['breed']) !== HorseNames::key($b['breed']) && ! $one) {
            return [self::DIFFERENT, 'different_breeds'];
        }
        if ($sameLetters && strlen(HorseNames::key($a['name'])) >= 10 && (self::empty($a) || self::empty($b))) {
            return [self::SAME, 'same_name_empty_record'];
        }
        if ($one) {
            return $sameLetters ? [self::SAME, 'same_name_one_parent'] : [self::STAFF, 'near_name_one_parent'];
        }
        if ($sameLetters) {
            return [self::STAFF, 'same_name_nothing_to_compare'];
        }
        // The stud book is a national list: a near match to one of its names, with nothing else to compare, is chance.
        if (isset($a['regs']['MLOSZ']) !== isset($b['regs']['MLOSZ'])) {
            return [self::NO_EVIDENCE, 'near_stud_book_name'];
        }
        if (min(strlen(HorseNames::key($a['name'])), strlen(HorseNames::key($b['name']))) < 8) {
            return [self::NO_EVIDENCE, 'near_short_name'];
        }

        return [self::STAFF, 'near_name_nothing_to_compare'];
    }

    private static function empty(array $side): bool
    {
        return ! $side['year'] && ! $side['regs'] && HorseNames::parent($side['sire']) === null && HorseNames::parent($side['dam']) === null;
    }

    /**
     * The facts classify() needs, for many records at once.
     *
     * @param  list<string>  $ids
     * @return array<string, array>
     */
    public static function load(array $ids): array
    {
        $parents = [];
        foreach (\Illuminate\Support\Facades\DB::table('pedigree_entries')->whereIn('horse_id', $ids)->whereIn('position', ['s', 'd'])->whereNull('superseded_by')->select('horse_id', 'position', 'name')->get() as $p) {
            $parents[$p->horse_id][$p->position] = $p->name;
        }
        $regs = [];
        foreach (\Illuminate\Support\Facades\DB::table('horse_identifiers')->whereIn('horse_id', $ids)->where('kind', 'registration')->whereNull('superseded_by')->select('horse_id', 'registry', 'value_normalized')->get() as $n) {
            $regs[$n->horse_id][(string) $n->registry][$n->value_normalized] = true;
        }
        $out = [];
        foreach (\Illuminate\Support\Facades\DB::table('horses')->whereIn('id', $ids)->whereNull('merged_into_id')->select('id', 'registered_name', 'sex', 'breed', 'birth_year', 'birth_precision')->get() as $h) {
            $out[$h->id] = ['name' => $h->registered_name, 'sex' => $h->sex, 'breed' => $h->breed, 'year' => $h->birth_precision !== 'unknown' ? (int) $h->birth_year : null,
                'sire' => $parents[$h->id]['s'] ?? null, 'dam' => $parents[$h->id]['d'] ?? null, 'regs' => $regs[$h->id] ?? []];
        }

        return $out;
    }
}
