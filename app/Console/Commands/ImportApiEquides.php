<?php

namespace App\Console\Commands;

use App\Harvest\ApiEquidesImporter;
use Illuminate\Console\Command;

/**
 * Parents and breeders for the French (IFCE) records, from the API Équidés bulk file (Oct 27 2026, Richard approved).
 * See docs/apiequides-import.md.
 *
 *   php -d memory_limit=2G artisan hf:import-apiequides storage/app/private/harvest/apiequides/chevaux-20260816.jsonl.zst [--dry-run]
 */
class ImportApiEquides extends Command
{
    protected $signature = 'hf:import-apiequides {file} {--url=https://api-equides.org/v1/extractions/b7bac04816bd08e245e616de7f1cbd046ed53a823eeaf641f819555322da2f12} {--dry-run}';

    protected $description = 'Add sire, dam and breeder to French (IFCE) records from the API Équidés extraction';

    public function handle(): int
    {
        $out = (new ApiEquidesImporter(fn ($l) => $this->line($l)))->run($this->argument('file'), $this->option('url'), (bool) $this->option('dry-run'));
        $this->info('API Équidés: '.json_encode($out));

        return self::SUCCESS;
    }
}
