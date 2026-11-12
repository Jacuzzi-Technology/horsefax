<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\TopekaHarvester;
use Illuminate\Console\Command;

/**
 * Topeka Livestock Auction horse-sale catalogs and market reports (Oct 22 2026, Richard's standing approval).
 * See docs/topeka-harvest.md.
 *
 *   php artisan hf:harvest-topeka              download new PDFs, then load
 *   php artisan hf:harvest-topeka --offline    re-read the kept PDFs and load, no requests
 */
class HarvestTopeka extends Command
{
    protected $signature = 'hf:harvest-topeka {--delay=3} {--offline} {--no-load} {--dir=}';

    protected $description = 'Collect Topeka Livestock Auction horse-sale catalogs and market reports (facts only) into the sale-lots index';

    public function handle(): int
    {
        $h = new TopekaHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
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
