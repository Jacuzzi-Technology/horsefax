<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\StallionCompareHarvester;
use Illuminate\Console\Command;

/**
 * StallionCompare stallions and the horses they link to (Oct 27 2026, Richard). See docs/stallioncompare-harvest.md.
 *
 *   php artisan hf:harvest-stallioncompare             fetch new pages, then load new or changed ones
 *   php artisan hf:harvest-stallioncompare --refresh   refetch pages older than 25 days (monthly cron)
 *   php artisan hf:harvest-stallioncompare --offline   load kept pages, no requests
 */
class HarvestStallionCompare extends Command
{
    protected $signature = 'hf:harvest-stallioncompare {--delay=3} {--offline} {--refresh} {--reload} {--max-pages=}';

    protected $description = 'Collect StallionCompare stallions: pedigree, genetics, fees, standing farm, earnings, highlights, progeny, article mentions';

    public function handle(): int
    {
        $h = new StallionCompareHarvester(max(3.0, (float) $this->option('delay')), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Collect: '.json_encode($h->collect((bool) $this->option('refresh'), $this->option('max-pages') ? (int) $this->option('max-pages') : null)));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE;
            }
        }
        $this->info('Loaded: '.json_encode($h->load((bool) $this->option('reload'))));

        return $status;
    }
}
