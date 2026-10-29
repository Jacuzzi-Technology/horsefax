<?php

namespace App\Console\Commands;

use App\Harvest\BuckeyeClassicHarvester;
use App\Harvest\HarvestBlocked;
use Illuminate\Console\Command;

/**
 * Buckeye Classic Yearling Sale results and hip pages (Oct 28 2026). See docs/buckeye-classic.md.
 *
 *   php artisan hf:harvest-buckeye-classic           results pages 2015-now (kept pages are reread, not refetched)
 *   php artisan hf:harvest-buckeye-classic --hips    then each 2020+ hip page: foaling date, USTA number, pedigree
 */
class HarvestBuckeyeClassic extends Command
{
    protected $signature = 'hf:harvest-buckeye-classic {--hips} {--site=buckeye : buckeye, hoosier, midwest, hoosier-archive or hoosier-heritage} {--year=} {--max-hips=} {--delay=3}';

    protected $description = 'Collect Buckeye Classic Standardbred yearling sale results (and hip pages) into the sale-lots index';

    public function handle(): int
    {
        $h = new BuckeyeClassicHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('site'));
        try {
            $this->info('Results: '.json_encode($h->run($this->option('year') ? [(int) $this->option('year')] : null)));
            if ($this->option('hips')) {
                $this->info('Hip pages: '.json_encode($h->enrich($this->option('year') ? (int) $this->option('year') : null, $this->option('max-hips') ? (int) $this->option('max-hips') : null)));
            }
        } catch (HarvestBlocked $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
