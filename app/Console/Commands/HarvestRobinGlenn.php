<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\RobinGlennHarvester;
use Illuminate\Console\Command;

/**
 * Robin Glenn / QData online catalogs (Richard, Oct 22 2026). See docs/sale-catalog-imports.md.
 *
 *   php artisan hf:harvest-robinglenn                     crawl new catIDs, write inventory + CSV/JSON, load
 *   php artisan hf:harvest-robinglenn --no-load           crawl + files only (no database)
 *   php artisan hf:harvest-robinglenn --offline           files + load from the kept pages, no requests
 *   php artisan hf:harvest-robinglenn --refresh-from=2850 re-read recent catalogs for new results
 *   php artisan hf:harvest-robinglenn --cats=3030,3106      re-read just these catalogs (hf:sale-watch uses this)
 */
class HarvestRobinGlenn extends Command
{
    protected $signature = 'hf:harvest-robinglenn {--delay=3} {--from=1} {--to=} {--max-requests=} {--refresh-from=} {--cats=} {--offline} {--no-load} {--dir=}';

    protected $description = 'Collect Robin Glenn QData online sale catalogs (facts only) into raw files and the sale-lots index';

    public function handle(): int
    {
        $h = new RobinGlennHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Crawl: '.json_encode($h->crawl((int) $this->option('from'), $this->option('to') ? (int) $this->option('to') : null,
                    $this->option('max-requests') ? (int) $this->option('max-requests') : null, $this->option('refresh-from') ? (int) $this->option('refresh-from') : null,
                    array_map('intval', array_filter(explode(',', (string) $this->option('cats')))))));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE; // what was kept is still exported and loaded below
            }
        }
        $this->info('Files: '.json_encode($h->export(), JSON_UNESCAPED_UNICODE));
        if (! $this->option('no-load')) {
            $this->info('Loaded: '.json_encode($h->load()));
        }

        return $status;
    }
}
