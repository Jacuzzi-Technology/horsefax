<?php

namespace App\Console\Commands;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\RecordFacts;
use App\Models\Horse;
use App\Models\RegistryRecord;
use App\Support\Audit;
use App\Support\NameCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Breeder (Richard, Oct 27 2026: added to every record, filled where we already hold it). Reads the "Breeder:" line of
 * the AQHA papers already on file (registry_records.raw_text) and fills the field where it is blank. Owners on the
 * same papers stay recorded, not shown. Like the papers import, a record someone owns is left to its owner.
 * Rerunnable; --dry-run only counts.
 *
 *   php artisan hf:backfill-breeders [--dry-run]
 */
class BackfillBreeders extends Command
{
    protected $signature = 'hf:backfill-breeders {--dry-run}';

    protected $description = 'Fill the Breeder field from AQHA papers on file (registry records)';

    public function handle(): int
    {
        $system = BuildRecordsFromResults::system();
        $out = ['papers' => 0, 'with_breeder' => 0, 'filled' => 0, 'already_set' => 0, 'skipped_owned' => 0];
        RegistryRecord::whereNotNull('raw_text')->orderBy('document_date')->each(function (RegistryRecord $rec) use ($system, &$out) {
            $out['papers']++;
            $breeder = self::breeder((string) $rec->raw_text);
            if (! $breeder) {
                return;
            }
            $out['with_breeder']++;
            $horse = Horse::find($rec->horse_id);
            if (! $horse || $horse->breeder) {
                $out['already_set']++;

                return;
            }
            if ($horse->activeStewards()->exists()) {
                $out['skipped_owned']++;

                return;
            }
            if ($this->option('dry-run')) {
                $out['filled']++;

                return;
            }
            DB::transaction(function () use ($horse, $breeder, $rec, $system) {
                $horse->forceFill(['breeder' => $breeder])->save();
                app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns(['breeder' => $breeder]), 'historical');
                Audit::record('horse.breeder_from_papers', $horse, null, ['breeder' => $breeder, 'document' => $rec->document_kind], $horse->id, $system->id);
            });
            $out['filled']++;
        });
        $this->info(json_encode($out));

        return self::SUCCESS;
    }

    /** "Breeder:   ROBERT R &/OR WENDY DEXTER      Owner: ..." → "Robert R &/or Wendy Dexter". */
    public static function breeder(string $text): ?string
    {
        if (! preg_match('/Breeder:[ \t]*(\S.*?)(?:[ \t]{2,}|\R|$)/', $text, $m)) {
            return null;
        }
        $name = trim($m[1]);
        if ($name === '' || str_starts_with(strtolower($name), 'owner')) {
            return null;
        }
        if ($name === mb_strtoupper($name)) {
            $name = implode(' ', array_map(fn ($w) => match ($w) {
                '&/OR' => '&/or', 'LLC', 'LP', 'LTD', 'II', 'III', 'IV' => $w, 'INC' => 'Inc', 'CO' => 'Co',
                default => NameCase::word($w),
            }, preg_split('/\s+/', $name)));
        }

        return mb_substr($name, 0, 160);
    }
}
