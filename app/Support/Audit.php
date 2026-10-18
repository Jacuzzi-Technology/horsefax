<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit trail. Every material change records who, when, and the before/after values.
 */
final class Audit
{
    public static function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?string $horseId = null, ?int $actorId = null): void
    {
        DB::table('audit_log')->insert([
            'actor_id' => $actorId ?? Auth::id(),
            'horse_id' => $horseId ?? ($subject?->getAttribute('horse_id') ?? ($subject instanceof \App\Models\Horse ? $subject->getKey() : null)),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before === null ? null : json_encode(self::clean($before)),
            'after' => $after === null ? null : json_encode(self::clean($after)),
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /** Record an update: only the fields that changed. */
    public static function changes(string $action, Model $model): void
    {
        $changed = array_diff_key($model->getChanges(), ['updated_at' => 1]);
        if ($changed === []) {
            return;
        }
        $before = array_intersect_key($model->getPrevious(), $changed);
        self::record($action, $model, $before, $changed);
    }

    private static function clean(array $values): array
    {
        unset($values['password'], $values['remember_token'], $values['two_factor_secret'], $values['two_factor_recovery_codes'], $values['token']);

        return $values;
    }
}
