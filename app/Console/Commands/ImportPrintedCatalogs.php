<?php

namespace App\Console\Commands;

use App\Harvest\PrintedCatalogs;
use Illuminate\Console\Command;

/**
 * Richard's other printed sale catalogs (PDFNotes library). See docs/printed-catalogs.md.
 *
 *   php artisan hf:import-printed-catalogs --copy            copy new catalogs from Dropbox (Richard's Mac only), then load
 *   php artisan hf:import-printed-catalogs --sale=southwest  one sale company
 */
class ImportPrintedCatalogs extends Command
{
    protected $signature = 'hf:import-printed-catalogs {--sale=} {--copy} {--dry-run}';

    protected $description = 'Load the lots in Richard\'s printed sale catalogs (other than Lolli and Billings) into the sale-lots index';

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        $c = new PrintedCatalogs(fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
        if ($this->option('sale') && ! isset(PrintedCatalogs::SALES[$this->option('sale')])) {
            $this->error('Unknown sale: '.$this->option('sale').' (one of '.implode(', ', array_keys(PrintedCatalogs::SALES)).')');

            return self::FAILURE;
        }
        if ($this->option('copy')) {
            $this->info('Copied '.$c->copy($this->option('sale')).' catalogs');
        }
        if (! $this->option('dry-run') && in_array($this->option('sale'), [null, 'boone'], true)) {
            $this->info('Team lots: '.json_encode($c->cleanTeamLots()));
        }
        foreach ($c->load($this->option('sale'), (bool) $this->option('dry-run')) as $sale => $out) {
            $this->info($sale.': '.json_encode($out));
        }

        return self::SUCCESS;
    }
}
