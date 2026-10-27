<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\MloszHarvester;
use Illuminate\Console\Command;

/**
 * MLOSZ (Hungarian horse breeding database) stud-book search: American Quarter Horses, Paints and Appaloosas registered
 * in Hungary, with pedigrees and breeders (Oct 27 2026, Richard's standing approval; Kate found it).
 * See docs/mlosz-harvest.md.
 *
 *   php artisan hf:harvest-mlosz                 fetch result pages not kept yet, then load
 *   php artisan hf:harvest-mlosz --offline       load the kept pages, no requests
 *   php artisan hf:harvest-mlosz --breed=quart   one breed (MLOSZ code)
 *   php artisan hf:harvest-mlosz --refresh       refetch every page; only changed pages are loaded again (monthly cron)
 */
class HarvestMlosz extends Command
{
    protected $signature = 'hf:harvest-mlosz {--delay=3} {--offline} {--no-load} {--refresh} {--reload : load every kept page again, not only new or changed ones} {--breed=*}';

    protected $description = 'Collect MLOSZ (Hungary) stud-book records of American breeds: identity, pedigree, breeder; owners are not published';

    public function handle(): int
    {
        $h = new MloszHarvester(max(3.0, (float) $this->option('delay')), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('breed'));
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Collect: '.json_encode($h->collect((bool) $this->option('refresh'))));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE;
            }
        }
        if (! $this->option('no-load')) {
            $this->info('Loaded: '.json_encode($h->load((bool) $this->option('reload'))));
        }

        return $status;
    }
}
