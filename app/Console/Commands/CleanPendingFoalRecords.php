<?php

namespace App\Console\Commands;

use App\Actions\Record\CleanPendingFoalRecords as Clean;
use Illuminate\Console\Command;

/**
 * Repair of the Robin Glenn load (Oct 23 2026): remove records made from unnamed-foal labels ("Pending/<dam>") and detach
 * their lots. See App\Actions\Record\CleanPendingFoalRecords. Run it under the match lock, like the loaders.
 *
 *   php artisan hf:clean-pending-foal-records --dry-run              real counts, then rolled back
 *   php artisan hf:clean-pending-foal-records --dry-run --list=f.csv  also write the records it would remove to a CSV
 *   php artisan hf:clean-pending-foal-records
 */
class CleanPendingFoalRecords extends Command
{
    protected $signature = 'hf:clean-pending-foal-records {--dry-run} {--list= : write the records removed (or that would be) to this CSV file}';

    protected $description = 'Remove unverified records made from unnamed-foal labels like "Pending/<dam>" and leave their lots unmatched';

    public function handle(): int
    {
        [$out, $list] = app(Clean::class)((bool) $this->option('dry-run'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
        if ($path = $this->option('list')) {
            $f = fopen($path, 'w');
            fputcsv($f, ['horsefax_id', 'name', 'sale']);
            foreach ($list as $row) {
                fputcsv($f, $row);
            }
            fclose($f);
            $this->line(count($list).' records listed in '.$path);
        }
        $this->info(json_encode(['dry_run' => (bool) $this->option('dry-run')] + $out));

        return self::SUCCESS;
    }
}
