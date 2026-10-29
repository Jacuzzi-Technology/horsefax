<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\LegacyCatalogs;
use Illuminate\Console\Command;

/**
 * Legacy Reining Breeders Sale catalogs (Oct 28 2026). See docs/legacy-sale-harvest.md, "Catalogs".
 *
 *   php artisan hf:legacy-catalogs             download catalog files not kept yet, then fill the results lots
 *   php artisan hf:legacy-catalogs --offline   only read the kept files
 */
class LegacyCatalogsCommand extends Command
{
    protected $signature = 'hf:legacy-catalogs {--offline} {--delay=3}';

    protected $description = 'Read Legacy Sale catalogs (AQHA numbers, foaling dates, pedigrees) onto the results lots';

    public function handle(): int
    {
        ini_set('memory_limit', '4G'); // the full catalogs run to 27 MB
        $h = new LegacyCatalogs((float) $this->option('delay'), fn ($l) => str_contains($l, ' 200 ') ? null : $this->line(now()->format('H:i:s').' '.$l));
        try {
            if (! $this->option('offline')) {
                $this->info('Collect: '.json_encode($h->collect()));
            }
            $this->info('Load: '.json_encode($h->load()));
        } catch (HarvestBlocked $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
