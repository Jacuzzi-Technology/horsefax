<?php

namespace App\Console\Commands;

use App\Harvest\LolliHarvester;
use Illuminate\Console\Command;

/**
 * Lolli Bros. Livestock Market horse-sale catalogs, 2013-2026 (Oct 28 2026). See docs/lolli-catalogs.md.
 *
 *   php artisan hf:import-lolli-catalogs             read every catalog in storage/app/private/lolli and load
 *   php artisan hf:import-lolli-catalogs --dry-run   count lots and pedigrees, change nothing
 */
class ImportLolliCatalogs extends Command
{
    protected $signature = 'hf:import-lolli-catalogs {--dry-run} {--dir=}';

    protected $description = 'Load Lolli Bros. horse-sale catalogs (Richard\'s copies and archived PDFs) into the sale-lots index';

    public function handle(): int
    {
        $h = new LolliHarvester(fn ($l) => $this->line(now()->format('H:i:s').' '.$l), $this->option('dir') ?: null);
        $this->info(($this->option('dry-run') ? 'Dry run: ' : 'Loaded: ').json_encode($h->load((bool) $this->option('dry-run'))));

        return self::SUCCESS;
    }
}
