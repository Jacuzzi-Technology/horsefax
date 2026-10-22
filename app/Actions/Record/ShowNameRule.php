<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseName;
use App\Models\ReviewItem;
use App\Models\ShowRecord;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;

/**
 * Richard, Oct 20 2026: owners often rename a horse with AQHA before it's shown, but once a horse has a show record
 * the name can't be changed. So show results always carry the horse's final registered name, and two things are
 * contradictions that go to staff instead of being saved silently:
 *  - results published under one of the horse's previous names (a different horse with that name, or a wrong match);
 *  - a registered-name change on a horse whose results are already attached under its current name.
 */
class ShowNameRule
{
    public const KIND = 'name_rule';

    /** The horse's previous name these results were published under, or null when the results use its current name. */
    public static function formerName(ShowRecord $record, Horse $horse): ?string
    {
        if ($horse->registered_name && ShowRecord::key($horse->registered_name) === $record->name_key) {
            return null;
        }

        return HorseName::where('horse_id', $horse->id)->where('kind', 'previous')->pluck('name')
            ->first(fn ($n) => ShowRecord::key($n) === $record->name_key);
    }

    /** Horses that list the record's name as a previous registered name. */
    public static function horsesFormerlyNamed(ShowRecord $record)
    {
        return Horse::whereNull('merged_into_id')->whereIn('id', HorseName::where('kind', 'previous')
            ->whereRaw("upper(regexp_replace(name, '[^A-Za-z0-9]', '', 'g')) = ?", [$record->name_key])->select('horse_id'))->get();
    }

    /** Show results already attached to the horse under the given name. */
    public static function shownAs(Horse $horse, string $name): ?ShowRecord
    {
        return ShowRecord::where('horse_id', $horse->id)->where('name_key', ShowRecord::key($name))->first();
    }

    /** Results under a former name: held for staff, never attached automatically. */
    public static function flagFormerName(ShowRecord $record, Horse $horse, string $formerName, ?User $by = null): ReviewItem
    {
        return self::flag($horse, $by, 'ShowRecord', $record->id, 'former_name_results',
            $record->appearances.' published results ('.$record->yearsLabel().') are under "'.$record->display_name.'", a previous name of '
            .$horse->displayName().'. AQHA doesn\'t allow a name change once a horse has a show record, so these are probably a different horse '
            .'with that name, or the rename is recorded wrong. Approve only if you\'ve confirmed they are this horse.',
            ['show_record' => $record->display_name, 'former_name' => $formerName, 'current_name' => $horse->registered_name]);
    }

    /** A rename of a horse already shown under its old name: the rename stands, but staff check it. */
    public static function flagRename(Horse $horse, string $oldName, string $newName, ShowRecord $shown, ?User $by = null): ReviewItem
    {
        return self::flag($horse, $by, 'ShowRecord', $shown->id, 'renamed_after_shows',
            'Registered name changed from "'.$oldName.'" to "'.$newName.'", but this horse already has '.$shown->appearances.' show results ('
            .$shown->yearsLabel().') under "'.$oldName.'". AQHA doesn\'t allow a name change once a horse has a show record. Check the new name '
            .'against the papers: the results may belong to another horse, or the rename may be a mistake.',
            ['old_name' => $oldName, 'new_name' => $newName, 'show_record' => $shown->display_name]);
    }

    private static function flag(Horse $horse, ?User $by, string $type, string $id, string $case, string $statement, array $details): ReviewItem
    {
        $open = ReviewItem::where('kind', self::KIND)->where('horse_id', $horse->id)->where('subject_id', $id)->where('status', 'open')
            ->get()->first(fn ($i) => ($i->details['case'] ?? null) === $case);
        if ($open) {
            return $open;
        }
        $item = ReviewItem::create([
            'kind' => self::KIND, 'horse_id' => $horse->id, 'submitted_by' => $by?->id,
            'subject_type' => $type, 'subject_id' => $id, 'statement' => $statement, 'details' => ['case' => $case] + $details,
        ]);
        Audit::record('name_rule.flagged', $item, null, ['case' => $case] + $details, $horse->id, $by?->id);
        Notify::staff('Horsefax name-change check for '.$horse->horsefax_id, $statement);

        return $item;
    }
}
