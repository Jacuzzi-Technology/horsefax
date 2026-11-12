<?php

namespace App\Support;

use App\Models\User;

/**
 * The one place that answers "may this account do X?" from its plan (config/plans.php). Visitors who aren't signed
 * in get the default plan. A plan whose end date has passed falls back to the default. Staff always have everything.
 */
final class Plans
{
    public static function all(): array
    {
        return config('plans.plans');
    }

    public static function key(?User $user): string
    {
        $default = config('plans.default', 'free');
        if (! $user || ! isset(self::all()[$user->plan])) {
            return $default;
        }
        if ($user->plan_ends_at && $user->plan_ends_at->isPast()) {
            return $default;
        }

        return $user->plan;
    }

    public static function name(?User $user): string
    {
        return self::all()[self::key($user)]['name'];
    }

    public static function allows(?User $user, string $feature): bool
    {
        if ($user?->isStaff()) {
            return true;
        }

        return in_array($feature, self::all()[self::key($user)]['features'] ?? [], true);
    }

    /** A plan limit; null means unlimited. */
    public static function limit(?User $user, string $limit): ?int
    {
        if ($user?->isStaff()) {
            return null;
        }

        return self::all()[self::key($user)]['limits'][$limit] ?? null;
    }
}
