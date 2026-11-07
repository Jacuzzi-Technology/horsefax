<?php

namespace App\Console\Commands;

use App\Actions\Record\CleanDreamHorsePedigrees as Clean;
use Illuminate\Console\Command;

/**
 * One-off repair of the first DreamHorse load (Oct 23 2026): sellers' notes taken for sire and dam names. See
 * App\Actions\Record\CleanDreamHorsePedigrees. Run it under the match lock, like the loaders.
 *
 *   php artisan hf:clean-dreamhorse-pedigrees --dry-run    real counts, then rolled back
 *   php artisan hf:clean-dreamhorse-pedigrees
 */
class CleanDreamHorsePedigrees extends Command
{
    protected $signature = 'hf:clean-dreamhorse-pedigrees {--dry-run}';

    protected $description = 'Take sellers\' notes out of DreamHorse pedigree names, fix the records and ancestors they made, propose duplicates to staff';

    public function handle(): int
    {
        $out = app(Clean::class)((bool) $this->option('dry-run'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
        $this->info(json_encode(['dry_run' => (bool) $this->option('dry-run')] + $out));

        return self::SUCCESS;
    }
}
