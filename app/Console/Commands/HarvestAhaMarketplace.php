<?php

namespace App\Console\Commands;

use App\Harvest\AhaMarketplaceHarvester;
use App\Harvest\HarvestBlocked;
use Illuminate\Console\Command;

/**
 * Arabian Horse Association marketplace ads (Oct 28 2026, Richard). See app/Harvest/AhaMarketplaceHarvester.php.
 *
 *   php artisan hf:harvest-aha-marketplace              read both lists and new ad pages, then load
 *   php artisan hf:harvest-aha-marketplace --offline    load from the kept pages, no requests
 */
class HarvestAhaMarketplace extends Command
{
    protected $signature = 'hf:harvest-aha-marketplace {--delay=3} {--offline} {--no-load} {--dir=}';

    protected $description = 'Collect Arabian Horse Association marketplace ads (horses for sale, stallions at stud; facts only)';

    public function handle(): int
    {
        $h = new AhaMarketplaceHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Collect: '.json_encode($h->collect()));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE;
            }
        }
        if (! $this->option('no-load')) {
            $this->info('Loaded: '.json_encode($h->load()));
        }

        return $status;
    }
}
