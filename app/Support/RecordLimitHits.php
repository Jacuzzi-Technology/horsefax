<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Logs a refusal by the daily record limit: one row per account (or signed-out address) per UTC day. */
final class RecordLimitHits
{
    public static function record(Request $request, int $cap): void
    {
        try {
            $user = $request->user();
            $now = now('UTC');
            DB::statement(
                'insert into record_limit_hits (day, who, user_id, ip, plan, cap, blocked, first_at, last_at)
                 values (?, ?, ?, ?, ?, ?, 1, ?, ?)
                 on conflict (day, who) do update set blocked = record_limit_hits.blocked + 1, last_at = excluded.last_at',
                [$now->toDateString(), $user ? 'u:'.$user->id : 'ip:'.$request->ip(), $user?->id, $request->ip(),
                    $user ? Plans::key($user) : 'guest', $cap, $now, $now]
            );
        } catch (\Throwable $e) {
            Log::warning('record_limit_hits insert failed: '.$e->getMessage()); // never break the limit page
        }
    }
}
