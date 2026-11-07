<?php

namespace App\Console\Commands;

use App\Harvest\SaleWatch;
use App\Models\SaleEvent;
use Illuminate\Console\Command;

/**
 * Run sale harvesters by the sale calendar instead of weekly (Oct 24 2026). See App\Harvest\SaleWatch and
 * docs/sale-watch.md.
 *
 *   php artisan hf:sale-watch              run whatever is due today (cron: daily)
 *   php artisan hf:sale-watch --dry-run    show the calendar and what would run, no harvesting
 *   php artisan hf:sale-watch --source=pitzer
 */
class SaleWatchCommand extends Command
{
    protected $signature = 'hf:sale-watch {--dry-run} {--source=} {--list : print the calendar}';

    protected $description = 'Run sale-catalog harvesters only when a sale on the calendar is due a check';

    public function handle(): int
    {
        $w = new SaleWatch(fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
        if ($this->option('list')) {
            $w->sync();
            $this->table(['source', 'sale', 'dates', 'catalog', 'results', 'next check'], SaleEvent::orderBy('starts_on')->get()->map(fn ($e) => [
                $e->source, mb_strimwidth($e->name, 0, 50, '…'), $e->starts_on->toDateString().($e->ends_on && ! $e->ends_on->eq($e->starts_on) ? ' – '.$e->ends_on->toDateString() : ''),
                $e->catalog_seen_at ? 'yes' : '', $e->results_seen_at ? 'yes' : '', $e->next_check_at?->toDateString() ?? '-',
            ])->all());

            return self::SUCCESS;
        }
        $codes = $w->run((bool) $this->option('dry-run'), $this->option('source') ?: null);
        $this->info('Ran: '.json_encode($codes));

        return in_array(1, $codes, true) ? self::FAILURE : self::SUCCESS;
    }
}
