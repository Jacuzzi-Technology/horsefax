<?php

namespace App\Console\Commands;

use App\Support\AccountIps;
use App\Support\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monthly report on the daily record limit (Richard, Nov 10 2026): how many accounts got stopped, how often, and who
 * hit it most, so the 20-a-day cap can be judged. Also deletes sign-in addresses past their 12 months. Cron on the 1st of each month emails it to HORSEFAX_NOTIFY_EMAIL.
 */
class RecordLimitReport extends Command
{
    protected $signature = 'hf:record-limit-report {--month= : YYYY-MM, default last month} {--no-email}';

    protected $description = 'Report accounts and visitors stopped by the daily record limit';

    public function handle(): int
    {
        $start = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $this->option('month'), 'UTC')
            : CarbonImmutable::now('UTC')->subMonthNoOverflow()->startOfMonth();
        $end = $start->endOfMonth();
        $hits = DB::table('record_limit_hits')->whereBetween('day', [$start->toDateString(), $end->toDateString()]);

        $members = (clone $hits)->whereNotNull('user_id');
        $accounts = (clone $members)->distinct()->count('user_id');
        $accountDays = (clone $members)->count();
        $refused = (int) (clone $members)->sum('blocked');
        $caps = (clone $members)->distinct()->pluck('cap')->sort()->implode(', ');
        $guests = (clone $hits)->whereNull('user_id')->distinct()->count('ip');
        $guestRefused = (int) (clone $hits)->whereNull('user_id')->sum('blocked');
        $active = DB::table('users')->whereBetween('last_login_at', [$start, $end->endOfDay()])->count();

        $top = (clone $members)
            ->join('users', 'users.id', '=', 'record_limit_hits.user_id')
            ->groupBy('users.id', 'users.email', 'users.created_at', 'record_limit_hits.plan')
            ->selectRaw('users.email, users.created_at, record_limit_hits.plan, count(*) as days, sum(blocked) as refused, max(day) as last_day')
            ->orderByDesc('days')->orderByDesc('refused')->limit(15)->get();

        $lines = [
            'Horsefax daily record limit, '.$start->format('F Y'),
            '',
            "Accounts stopped by the limit: {$accounts}".($active ? " (of {$active} accounts that signed in this month)" : ''),
            "Account-days stopped: {$accountDays}",
            "Records refused to accounts: {$refused}".($caps !== '' ? " (cap: {$caps} a day)" : ''),
            "Signed-out addresses stopped: {$guests} ({$guestRefused} refused requests)",
        ];
        if ($top->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Accounts stopped most often (days stopped / records refused / last day / joined):';
            foreach ($top as $t) {
                $lines[] = sprintf('  %s [%s]  %d / %d / %s / %s', $t->email, $t->plan, $t->days, $t->refused, $t->last_day, substr((string) $t->created_at, 0, 10));
            }
            $lines[] = '';
            $lines[] = 'Many days in a row, or an account a few days old, can mean someone copying records. A real buyer stopped once or twice may mean the cap is too low.';

            $sharing = AccountIps::sharing((clone $members)->distinct()->pluck('user_id'));
            if ($sharing->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Stopped accounts sharing a sign-in address with other accounts (possible one person, several accounts):';
                foreach ($sharing as $s) {
                    $lines[] = "  {$s->email} at {$s->ip} also: {$s->others}";
                }
            }
        }
        $pruned = AccountIps::prune();
        if ($pruned) {
            $lines[] = '';
            $lines[] = "Deleted {$pruned} sign-in address rows older than ".AccountIps::KEEP_MONTHS.' months.';
        }
        $body = implode("\n", $lines);
        $this->line($body);

        if (! $this->option('no-email')) {
            Notify::staff('Horsefax record limit report: '.$start->format('F Y')." ({$accounts} accounts stopped)", $body);
        }

        return self::SUCCESS;
    }
}
