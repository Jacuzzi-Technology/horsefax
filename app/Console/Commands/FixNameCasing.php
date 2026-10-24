<?php

namespace App\Console\Commands;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\RecordFacts;
use App\Models\Horse;
use App\Support\Audit;
use App\Support\NameCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair initials title-cased by imports ("E.l. Tremor" → "E.L. Tremor", "J.d.'s Marlene" → "J.D.'s Marlene",
 * "Bp's Megan" → "BP's Megan"); Richard, Oct 23 2026. European register names (source ifce) only get initials at the
 * start or before 's, so particles like "Miesje v.d. Ellekamp" stay. Owner-managed and Identity-verified records are
 * skipped (counted), as are lines owners added. Every rename is audited.
 *
 *   php artisan hf:fix-name-casing --dry-run    count and sample (runs in a transaction and rolls back)
 *   php artisan hf:fix-name-casing
 */
class FixNameCasing extends Command
{
    protected $signature = 'hf:fix-name-casing {--dry-run} {--sample=15}';

    protected $description = 'Put initials in imported registered names back in capitals';

    private const PATTERN = "[A-Za-z]\\.[a-z]\\.|\\m[BCDFGHJKLMNPQRSTVWXZ][bcdfghjklmnpqrstvwxz]{1,2}['’]s\\M";

    public function handle(): int
    {
        $system = BuildRecordsFromResults::system();
        $out = ['horses_renamed' => 0, 'skipped_owner_or_verified' => 0, 'names_renamed' => 0, 'pedigree_lines_renamed' => 0];
        $sample = [];
        DB::beginTransaction();
        try {
            Horse::whereNull('merged_into_id')->whereRaw('registered_name ~ ?', [self::PATTERN])->orderBy('id')
                ->chunkById(500, function ($horses) use (&$out, &$sample, $system) {
                    foreach ($horses as $h) {
                        $european = $h->source === 'ifce';
                        $new = NameCase::repair($h->registered_name, ! $european, ! $european);
                        if ($new === $h->registered_name) {
                            continue;
                        }
                        if ($h->identity_status === 'verified' || $h->activeStewards()->exists()) {
                            $out['skipped_owner_or_verified']++;

                            continue;
                        }
                        $old = $h->registered_name;
                        $h->forceFill(['registered_name' => $new])->save();
                        app(RecordFacts::class)($h, $system, RecordFacts::fromColumns(['registered_name' => $new]), 'historical');
                        $out['names_renamed'] += DB::table('horse_names')->where('horse_id', $h->id)->where('name', $old)->update(['name' => $new, 'updated_at' => now()]);
                        Audit::record('horse.renamed_casing', $h, ['registered_name' => $old], ['registered_name' => $new, 'reason' => 'Initials restored to capitals'], $h->id, $system->id);
                        $out['horses_renamed']++;
                        count($sample) < (int) $this->option('sample') && $sample[] = "$old → $new";
                    }
                });

            // Chart lines the system added (owners' own entries are left alone).
            DB::table('pedigree_entries as p')->join('horses as h', 'h.id', '=', 'p.horse_id')
                ->where(fn ($q) => $q->where('p.added_by', $system->id)->orWhereNull('p.added_by'))
                ->whereRaw('p.name ~ ?', [self::PATTERN])->orderBy('p.id')->select('p.id', 'p.name', 'p.horse_id', 'h.source')
                ->chunkById(1000, function ($lines) use (&$out, $system) {
                    foreach ($lines as $l) {
                        $new = NameCase::repair($l->name, $l->source !== 'ifce', $l->source !== 'ifce');
                        if ($new !== $l->name) {
                            DB::table('pedigree_entries')->where('id', $l->id)->update(['name' => $new, 'updated_at' => now()]);
                            Audit::record('pedigree.renamed_casing', null, ['name' => $l->name], ['name' => $new], $l->horse_id, $system->id);
                            $out['pedigree_lines_renamed']++;
                        }
                    }
                }, 'p.id', 'id');

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        foreach ($sample as $s) {
            $this->line('  '.$s);
        }
        $this->info(json_encode(['dry_run' => (bool) $this->option('dry-run')] + $out, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
