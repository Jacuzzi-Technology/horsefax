<?php

namespace App\Console\Commands;

use App\Harvest\AuctioneerSoftwareHarvester;
use App\Harvest\HarvestBlocked;
use Illuminate\Console\Command;

/**
 * Horse auctions on AuctioneerSoftware sites (Oct 22 2026, Richard's standing approval). See docs/auctioneersoftware-harvest.md.
 *
 *   php artisan hf:harvest-auctioneersoftware                 every site: fetch new finished auctions, then load
 *   php artisan hf:harvest-auctioneersoftware bigsky waverly  only these sites
 *   php artisan hf:harvest-auctioneersoftware --offline       re-read the kept pages and load, no requests
 *   php artisan hf:harvest-auctioneersoftware --purge=premieronline   remove a retired site's lots and the records only they made
 */
class HarvestAuctioneerSoftware extends Command
{
    protected $signature = 'hf:harvest-auctioneersoftware {sites?*} {--purge= : remove everything loaded from a retired site (e.g. premieronline)} {--delay=3} {--offline} {--no-load} {--max-auctions=} {--dir=}';

    protected $description = 'Collect finished horse auctions (facts only) from AuctioneerSoftware sale sites into the sale-lots index';

    public function handle(): int
    {
        if ($key = $this->option('purge')) {
            $this->info('Purged '.$key.': '.json_encode(AuctioneerSoftwareHarvester::purge($key)));

            return self::SUCCESS;
        }
        $status = self::SUCCESS;
        foreach ($this->argument('sites') ?: array_keys(AuctioneerSoftwareHarvester::SITES) as $key) {
            $h = new AuctioneerSoftwareHarvester($key, (float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
            $this->info("== $key (".$h->base().')');
            if (! $this->option('offline')) {
                try {
                    $this->info('Collect: '.json_encode($h->collect($this->option('max-auctions') ? (int) $this->option('max-auctions') : null)));
                } catch (HarvestBlocked $e) {
                    $this->error($e->getMessage());
                    $status = self::FAILURE;
                    continue;
                }
            }
            if (! $this->option('no-load')) {
                $this->info('Loaded: '.json_encode($h->load()));
            }
        }

        return $status;
    }
}
