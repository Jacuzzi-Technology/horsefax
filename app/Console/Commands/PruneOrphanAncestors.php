<?php

namespace App\Console\Commands;

use App\Actions\Record\PruneOrphanAncestors as Prune;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove ancestor records (source "pedigree") left unreferenced after the records whose charts made them were removed.
 * Candidates come from the audit trail (pedigree.linked entries of records that no longer exist).
 *
 *   php artisan hf:prune-orphan-ancestors --dry-run    count only (runs in a transaction and rolls back)
 *   php artisan hf:prune-orphan-ancestors
 */
class PruneOrphanAncestors extends Command
{
    protected $signature = 'hf:prune-orphan-ancestors {--dry-run}';

    protected $description = 'Remove pedigree-made ancestor records that nothing refers to after their descendants were removed';

    public function handle(): int
    {
        $candidates = Prune::fromAuditTrail();
        DB::beginTransaction();
        try {
            $removed = app(Prune::class)($candidates);
            $this->option('dry-run') ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $this->info(json_encode(['dry_run' => (bool) $this->option('dry-run'), 'candidates' => count($candidates), 'ancestors_removed' => count($removed)]));

        return self::SUCCESS;
    }
}
