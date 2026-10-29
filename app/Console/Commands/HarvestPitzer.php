<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\PitzerHarvester;
use Illuminate\Console\Command;

/**
 * Pitzer Ranch sale catalogs that Robin Glenn no longer has (Oct 23 2026, Richard's standing approval).
 * See docs/pitzer-harvest.md.
 *
 *   php artisan hf:harvest-pitzer              download missing PDFs, then load
 *   php artisan hf:harvest-pitzer --offline    re-read the kept PDFs and load, no requests
 */
class HarvestPitzer extends Command
{
    protected $signature = 'hf:harvest-pitzer {--delay=3} {--offline} {--no-load} {--dir=}';

    protected $description = 'Collect Pitzer Ranch sale catalogs (facts only) into the sale-lots index';

    public function handle(): int
    {
        $h = new PitzerHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
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
