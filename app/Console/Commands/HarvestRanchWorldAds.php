<?php

namespace App\Console\Commands;

use App\Harvest\RanchWorldAdsBlocked;
use App\Harvest\RanchWorldAdsHarvester;
use Illuminate\Console\Command;

/**
 * RanchWorldAds.com horse ads (Richard, Oct 22 2026), facts only, active and sold. Raw file, HTML cache and CSV/JSON in
 * storage/app/private/ranchworldads (--dir to change). See docs/ranchworldads-harvest.md.
 *
 * php artisan hf:harvest-ranchworldads [--delay=3] [--max-ads=50] [--max-pages=] [--probe=500] [--refresh-days=60] [--no-index] [--no-load]
 * php artisan hf:harvest-ranchworldads --reparse     (re-read the HTML cache with the current parser; no requests)
 * php artisan hf:harvest-ranchworldads --load-only   (load the raw file into sale_lots; no requests)
 */
class HarvestRanchWorldAds extends Command
{
    protected $signature = 'hf:harvest-ranchworldads {--delay=3} {--max-ads=} {--max-pages=} {--probe=0} {--refresh-days=60}
        {--no-index} {--no-load} {--reparse} {--load-only} {--dir=}';

    protected $description = 'Collect RanchWorldAds.com horse ads (facts only, active and sold) into the raw file and the sale-lots index';

    public function handle(): int
    {
        ini_set('memory_limit', '1G'); // the whole raw file is held in memory: 128 MB ran out at ~16,000 ads (Oct 23 2026)
        $h = new RanchWorldAdsHarvester(max(3.0, (float) $this->option('delay')), // house rule: at least 3 s between requests
            fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
        if ($this->option('reparse')) {
            $this->info('Reparsed: '.json_encode($h->reparse()));
        } elseif (! $this->option('load-only')) {
            try {
                $out = $h->run(! $this->option('no-index'), $this->option('max-ads') !== null ? (int) $this->option('max-ads') : null,
                    (int) $this->option('refresh-days'), (int) $this->option('probe'), $this->option('max-pages') ? (int) $this->option('max-pages') : null);
            } catch (RanchWorldAdsBlocked $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info('RanchWorldAds: '.json_encode($out));
        }
        if (! $this->option('no-load') && ($this->option('load-only') || ! $this->option('reparse'))) {
            $this->info('Loaded: '.json_encode($h->load()));
        }

        return self::SUCCESS;
    }
}
