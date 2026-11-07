<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\WaverlyHarvester;
use Illuminate\Console\Command;

/**
 * Waverly Sales Company Midwest Horse Sale catalog PDFs, current and archived (Oct 23 2026, Richard's standing
 * approval). See docs/waverly-harvest.md.
 *
 *   php artisan hf:harvest-waverly              list and download new PDFs, then load
 *   php artisan hf:harvest-waverly --offline    re-read the kept PDFs and load, no requests
 */
class HarvestWaverly extends Command
{
    protected $signature = 'hf:harvest-waverly {--delay=3} {--offline} {--no-load} {--dir=}';

    protected $description = 'Collect Waverly Sales Company horse-sale catalogs (facts only) into the sale-lots index';

    public function handle(): int
    {
        $h = new WaverlyHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
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
