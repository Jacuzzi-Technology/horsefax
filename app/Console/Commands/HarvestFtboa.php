<?php

namespace App\Console\Commands;

use App\Harvest\FarmSiteHarvester;
use App\Harvest\FtboaHarvester;
use App\Harvest\HarvestBlocked;
use Illuminate\Console\Command;

/**
 * FTBOA's Florida-bred / Florida-sired registry and stallions, then the Horse Hosting farm sites they link to
 * (Oct 27 2026, Richard). See docs/ftboa-farm-sites-harvest.md.
 *
 *   php artisan hf:harvest-ftboa                  registry + stallions, then farm sites
 *   php artisan hf:harvest-ftboa --no-farms       FTBOA only
 *   php artisan hf:harvest-ftboa --offline        load kept files, no requests
 */
class HarvestFtboa extends Command
{
    protected $signature = 'hf:harvest-ftboa {--delay=3} {--offline} {--no-farms}';

    protected $description = 'Collect the FTBOA Florida-bred registry and stallions, then the Thoroughbred farm sites they link to';

    public function handle(): int
    {
        $log = fn ($l) => $this->line(now()->format('H:i:s').' '.$l);
        $delay = max(3.0, (float) $this->option('delay'));
        $f = new FtboaHarvester($delay, $log);
        if (! $this->option('offline')) {
            try {
                $this->info('FTBOA collect: '.json_encode($f->collect()));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
            }
        }
        $this->info('FTBOA loaded: '.json_encode($f->load()));
        if (! $this->option('no-farms')) {
            $farms = new FarmSiteHarvester($delay, $log);
            if (! $this->option('offline')) {
                $this->info('Farm sites collect: '.json_encode($farms->collect()));
            }
            $this->info('Farm sites loaded: '.json_encode($farms->load()));
        }

        return self::SUCCESS;
    }
}
