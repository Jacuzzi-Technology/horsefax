<?php

namespace App\Http\Middleware;

use App\Models\Horse;
use App\Support\Plans;
use App\Support\RecordLimitHits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Daily cap on full horse records for signed-in accounts (Richard, Nov 10 2026: 20 a day on Free, set in
 * config/plans.php). Counts distinct horses per UTC day, so reopening a horse already seen today is free, and the
 * account's own horses never count. Staff are unlimited. Every refusal is logged for hf:record-limit-report.
 * Signed-out visitors (who only see summaries) keep the per-request daily limit in the 'records' rate limiter.
 */
class RecordDailyCap
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $horse = $request->route('horse');
        if (! $user || ! $horse instanceof Horse || $horse->canBeViewedPrivatelyBy($user)) {
            return $next($request);
        }
        $cap = Plans::limit($user, 'record_views_per_day');
        if ($cap === null) {
            return $next($request);
        }

        $key = 'record-cap:'.$user->id.':'.now('UTC')->toDateString();
        $seen = Cache::get($key, []);
        if (! in_array($horse->id, $seen, true)) {
            if (count($seen) >= $cap) {
                RecordLimitHits::record($request, $cap);

                return response()->view('errors.record-limit', ['daily' => true, 'limit' => $cap], 429);
            }
            $seen[] = $horse->id;
            Cache::put($key, $seen, now('UTC')->endOfDay()->addHour());
        }

        return $next($request);
    }
}
