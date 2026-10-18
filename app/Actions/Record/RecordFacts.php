<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\HorseFact;
use App\Models\ReviewItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Identity fields (height, color, breed ...) are claims with history. A new value supersedes the old one, which stays
 * in the history. Exception, so stronger evidence is never silently overwritten: when the current value is Horsefax
 * Verified or came from a professional or official source, a different owner-reported value is kept as a
 * conflicting claim for staff review and the horse keeps its current value.
 */
class RecordFacts
{
    /** Horse columns in, one value per fact field out. */
    public static function fromColumns(array $c): array
    {
        $out = [];
        foreach (['registered_name', 'barn_name', 'breed', 'sex', 'color', 'markings'] as $f) {
            if (array_key_exists($f, $c)) {
                $out[$f] = [self::clean($c[$f]), null];
            }
        }
        if (array_key_exists('birth_precision', $c)) {
            $p = $c['birth_precision'] ?? 'unknown';
            $value = $p === 'day' ? (is_object($c['birth_date'] ?? null) ? $c['birth_date']->format('Y-m-d') : self::clean($c['birth_date'] ?? null))
                : ($p === 'year' ? self::clean($c['birth_year'] ?? null) : null);
            $out['birth'] = [$value, ['precision' => $p]];
        }
        if (array_key_exists('height_hands', $c)) {
            $h = self::clean($c['height_hands']);
            $m = $c['height_measured_on'] ?? null;
            $out['height'] = [$h === null ? null : rtrim(rtrim(number_format((float) $h, 1, '.', ''), '0'), '.'), ['measured_on' => is_object($m) ? $m->format('Y-m-d') : self::clean($m)]];
        }

        return $out;
    }

    private static function clean($v): ?string
    {
        $v = $v === null ? null : trim((string) $v);

        return $v === '' ? null : $v;
    }

    /**
     * Record the new values. Returns the fields that were held back as conflicts (the caller must not apply them).
     *
     * @param  array<string, array{0: ?string, 1: ?array}>  $values
     * @return list<string>
     */
    public function __invoke(Horse $horse, ?User $user, array $values, string $source, ?string $role = null): array
    {
        return DB::transaction(function () use ($horse, $user, $values, $source, $role) {
            $held = [];
            $current = $horse->currentFacts()->get()->keyBy('field');
            foreach ($values as $field => [$value, $meta]) {
                $old = $current->get($field);
                $metaClean = array_filter($meta ?? [], fn ($v) => $v !== null && $v !== '');
                if ($old && $old->value === $value && array_filter($old->meta ?? []) == $metaClean) {
                    continue;
                }
                if (! $old && $value === null) {
                    continue;
                }
                $strongOld = $old && ($old->status === 'verified' || in_array($old->source_type, ['professional_attested', 'official_source'], true));
                $conflict = $strongOld && $source === 'owner_reported';

                $fact = HorseFact::create([
                    'horse_id' => $horse->id, 'field' => $field, 'value' => $value, 'meta' => $metaClean ?: null,
                    'source_type' => $source, 'status' => 'unverified', 'is_current' => ! $conflict,
                    'contributor_id' => $user?->id, 'contributor_role' => $role,
                    'observed_on' => $field === 'height' ? ($metaClean['measured_on'] ?? null) : null,
                ]);
                if ($conflict) {
                    $held[] = $field;
                    ReviewItem::create([
                        'kind' => 'fact_conflict', 'horse_id' => $horse->id, 'submitted_by' => $user?->id,
                        'subject_type' => 'HorseFact', 'subject_id' => $fact->id,
                        'statement' => $fact->label().' changed to "'.($value ?? 'blank').'", but the current value "'.$old->value.'" is '.($old->status === 'verified' ? 'Horsefax Verified' : strtolower($old->sourceLabel())).'.',
                        'details' => ['current_fact_id' => $old->id],
                    ]);
                    Audit::record('fact.conflict', $fact, ['value' => $old->value], ['value' => $value], $horse->id);
                } else {
                    if ($old) {
                        $old->forceFill(['is_current' => false, 'superseded_by' => $fact->id, 'status' => $old->status === 'verified' ? 'superseded' : $old->status])->save();
                    }
                    Audit::record('fact.recorded', $fact, $old ? ['value' => $old->value] : null, ['field' => $field, 'value' => $value, 'source' => $source], $horse->id);
                }
            }

            return $held;
        });
    }

    /** Staff accept a held conflicting value: it becomes current and the horse is updated. */
    public static function accept(HorseFact $fact): void
    {
        $horse = $fact->horse;
        $old = $horse->currentFacts()->where('field', $fact->field)->first();
        if ($old) {
            $old->forceFill(['is_current' => false, 'superseded_by' => $fact->id, 'status' => $old->status === 'verified' ? 'superseded' : $old->status])->save();
        }
        $fact->forceFill(['is_current' => true])->save();
        $columns = match ($fact->field) {
            'birth' => ($fact->meta['precision'] ?? 'year') === 'day'
                ? ['birth_precision' => 'day', 'birth_date' => $fact->value, 'birth_year' => (int) substr((string) $fact->value, 0, 4)]
                : ['birth_precision' => $fact->value ? 'year' : 'unknown', 'birth_date' => null, 'birth_year' => $fact->value],
            'height' => ['height_hands' => $fact->value, 'height_measured_on' => $fact->meta['measured_on'] ?? null],
            default => [$fact->field => $fact->value],
        };
        $horse->forceFill($columns)->save();
    }
}
