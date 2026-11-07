<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\TriangleHarvester;
use Illuminate\Console\Command;

/**
 * Triangle Horse Sales catalogs (Oct 22 2026, Richard's standing approval). See docs/triangle-harvest.md.
 *
 *   php artisan hf:harvest-triangle              download new catalogs, then load
 *   php artisan hf:harvest-triangle --offline    re-read the kept PDFs and load, no requests
 *   php artisan hf:harvest-triangle --offline --ocr   also OCR the scrambled catalogs and fill numbers on Robin Glenn records
 */
class HarvestTriangle extends Command
{
    protected $signature = 'hf:harvest-triangle {--delay=3} {--offline} {--no-load} {--ocr : OCR the unreadable catalogs and add their numbers to the matching Robin Glenn records} {--dir=}';

    protected $description = 'Collect Triangle Horse Sales catalogs (facts only) into the sale-lots index';

    public function handle(): int
    {
        $h = new TriangleHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Collect: '.json_encode($h->collect()));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE;
            }
        }
        if (! $this->option('no-load')) {
            $this->info('Loaded: '.json_encode($h->load()));
        }
        if ($this->option('ocr')) {
            $this->info('OCR pages: '.json_encode($h->ocr()));
            $this->info('Enriched: '.json_encode($h->enrich()));
        }

        return $status;
    }
}
