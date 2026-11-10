<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Sign-up and sign-in addresses per account (Nov 10 2026). Staff-only; kept 12 months. */
final class AccountIps
{
    public const KEEP_MONTHS = 12;

    public static function record(User $user, string $event): void
    {
        try {
            $request = request();
            $ip = (string) $request->ip();
            DB::table('account_ips')->insert([
                'user_id' => $user->id, 'ip' => $ip, 'event' => $event,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null, 'created_at' => now(),
            ]);
            $user->forceFill($event === 'signup' ? ['signup_ip' => $ip, 'last_login_ip' => $ip] : ['last_login_ip' => $ip])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('account_ips insert failed: '.$e->getMessage()); // never block a sign-in
        }
    }

    /** One row per address this account used: first/last seen, how often, and the other accounts on it. */
    public static function forUser(User $user): Collection
    {
        $rows = DB::table('account_ips')->where('user_id', $user->id)
            ->selectRaw('ip, min(created_at) first_at, max(created_at) last_at, count(*) n, bool_or(event = \'signup\') signup')
            ->groupBy('ip')->orderByDesc('last_at')->get();
        $others = DB::table('account_ips')->join('users', 'users.id', '=', 'account_ips.user_id')
            ->whereIn('account_ips.ip', $rows->pluck('ip'))->where('account_ips.user_id', '!=', $user->id)
            ->select('account_ips.ip', 'users.id', 'users.email')->distinct()->get()->groupBy('ip');

        foreach ($rows as $r) {
            $r->others = $others->get($r->ip, collect());
        }

        return $rows;
    }

    /** Accounts that share an address with at least one other account, among the given user ids. */
    public static function sharing(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return DB::table('account_ips as a')
            ->join('account_ips as b', fn ($j) => $j->on('a.ip', '=', 'b.ip')->on('a.user_id', '!=', 'b.user_id'))
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('users as o', 'o.id', '=', 'b.user_id')
            ->whereIn('a.user_id', $userIds)
            ->selectRaw('u.email, a.ip, string_agg(distinct o.email, \', \') as others')
            ->groupBy('u.email', 'a.ip')->orderBy('u.email')->get();
    }

    public static function prune(): int
    {
        return DB::table('account_ips')->where('created_at', '<', now()->subMonths(self::KEEP_MONTHS))->delete();
    }
}
