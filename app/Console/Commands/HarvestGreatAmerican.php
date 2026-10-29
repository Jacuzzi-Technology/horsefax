<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\TurnbullHarvester;
use Illuminate\Console\Command;

/**
 * Great American Ranch & Trail Horse Sale catalogs and High Sellers (Oct 28 2026). See docs/great-american-trail-sale.md.
 *
 *   php artisan hf:harvest-great-american             download catalogs not kept yet (one request a minute), then load
 *   php artisan hf:harvest-great-american --offline   only read the kept files
 */
class HarvestGreatAmerican extends Command
{
    protected $signature = 'hf:harvest-great-american {--offline}';

    protected $description = 'Collect Great American Ranch & Trail Horse Sale catalogs (facts only) into the sale-lots index';

    public function handle(): int
    {
        $h = new TurnbullHarvester(fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
        try {
            if (! $this->option('offline')) {
                $this->info('Collect: '.json_encode($h->collect()));
            }
            $this->info('Loaded: '.json_encode($h->load()));
        } catch (HarvestBlocked $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
