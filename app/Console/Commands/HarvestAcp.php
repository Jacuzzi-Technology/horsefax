<?php

namespace App\Console\Commands;

use App\Harvest\AcpHarvester;
use App\Harvest\HarvestBlocked;
use Illuminate\Console\Command;

/**
 * American Classic Pedigrees Thoroughbred profiles, facts only (Oct 27 2026, Richard). See docs/acp-harvest.md.
 *
 *   php artisan hf:harvest-acp              fetch profiles not kept yet, then load
 *   php artisan hf:harvest-acp --offline    load kept profiles, no requests
 */
class HarvestAcp extends Command
{
    protected $signature = 'hf:harvest-acp {--delay=3} {--offline} {--max=}';

    protected $description = 'Collect American Classic Pedigrees Thoroughbred profiles (facts only: pedigree, race record, honors, breeder)';

    public function handle(): int
    {
        $h = new AcpHarvester(max(3.0, (float) $this->option('delay')), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Collect: '.json_encode($h->collect($this->option('max') ? (int) $this->option('max') : null)));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE;
            }
        }
        $this->info('Loaded: '.json_encode($h->load()));

        return $status;
    }
}
