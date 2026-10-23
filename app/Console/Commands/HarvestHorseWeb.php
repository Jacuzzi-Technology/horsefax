<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\HorseWebHarvester;
use Illuminate\Console\Command;

/**
 * HorseWeb.com classified ads (Oct 22 2026, Richard's standing approval). See docs/horseweb-harvest.md.
 *
 *   php artisan hf:harvest-horseweb              read the listing and the ad pages it calls for, then load
 *   php artisan hf:harvest-horseweb --offline    load from ads.jsonl, no requests
 */
class HarvestHorseWeb extends Command
{
    protected $signature = 'hf:harvest-horseweb {--delay=3} {--offline} {--no-load} {--max-pages=} {--max-ads=} {--dir=}';

    protected $description = 'Collect HorseWeb.com horse ads (facts only) into the sale-lots index';

    public function handle(): int
    {
        $h = new HorseWebHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Collect: '.json_encode($h->run($this->option('max-pages') ? (int) $this->option('max-pages') : null, $this->option('max-ads') !== null ? (int) $this->option('max-ads') : null)));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE;
            }
        }
        $this->info('Ads known: '.$h->count());
        if (! $this->option('no-load')) {
            $this->info('Loaded: '.json_encode($h->load()));
        }

        return $status;
    }
}
