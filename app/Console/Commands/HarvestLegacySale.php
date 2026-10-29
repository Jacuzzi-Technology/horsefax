<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\LegacySaleHarvester;
use Illuminate\Console\Command;

/**
 * Legacy Reining Breeders Sale results pages, 2014–2026 (Oct 23 2026, Richard's standing approval). See
 * docs/legacy-sale-harvest.md.
 *
 *   php artisan hf:harvest-legacysale              fetch pages not yet kept, then load
 *   php artisan hf:harvest-legacysale --offline    re-read the kept pages and load, no requests
 */
class HarvestLegacySale extends Command
{
    protected $signature = 'hf:harvest-legacysale {--delay=3} {--offline} {--no-load} {--dir=}';

    protected $description = 'Collect Legacy Reining Breeders Sale results (hip, name, price; facts only) into the sale-lots index';

    public function handle(): int
    {
        $h = new LegacySaleHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
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
