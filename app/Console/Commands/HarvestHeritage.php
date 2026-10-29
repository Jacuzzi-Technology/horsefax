<?php

namespace App\Console\Commands;

use App\Harvest\HarvestBlocked;
use App\Harvest\HeritageHarvester;
use Illuminate\Console\Command;

/**
 * Heritage Livestock catalogs + results (Richard, Oct 22 2026). See docs/sale-catalog-imports.md.
 *
 *   php artisan hf:harvest-heritage              find + download new PDFs and pages, write CSV/JSON, load
 *   php artisan hf:harvest-heritage --offline    re-parse the kept files and load, no requests
 *   php artisan hf:harvest-heritage --no-load    files only (no database)
 */
class HarvestHeritage extends Command
{
    protected $signature = 'hf:harvest-heritage {--delay=3} {--no-archive} {--offline} {--no-load} {--seed= : a folder of already-downloaded 1b95cd_*.pdf files} {--dir=}';

    protected $description = 'Collect Heritage Livestock sale catalogs and results (facts only) into raw files and the sale-lots index';

    public function handle(): int
    {
        $h = new HeritageHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
        $status = self::SUCCESS;
        if (! $this->option('offline')) {
            try {
                $this->info('Collect: '.json_encode($h->collect(! $this->option('no-archive'), $this->option('seed') ?: null)));
            } catch (HarvestBlocked $e) {
                $this->error($e->getMessage());
                $status = self::FAILURE;
            }
        }
        $files = $h->export();
        foreach ($files['catalog_list'] as $c) {
            $this->line(sprintf('  %-10s %-70s %3d hips (highest %3d), %3d reg #, %2d prices  [%s]', $c['sale_date'] ?? '?', mb_substr($c['sale_name'], 0, 70), $c['hips'], $c['highest_hip'], $c['with_reg'], $c['with_price'], $c['date_basis']));
        }
        unset($files['catalog_list']);
        $this->info('Files: '.json_encode($files));
        if (! $this->option('no-load')) {
            $this->info('Loaded: '.json_encode($h->load()));
        }

        return $status;
    }
}
