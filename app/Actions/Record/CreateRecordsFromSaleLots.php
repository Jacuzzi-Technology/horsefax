<?php

namespace App\Actions\Record;

use App\Models\DataSource;
use App\Models\Horse;
use App\Models\SaleLot;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Richard, Oct 19 2026: put a sale's horses into the database with what the sale published. A lot that no record
 * matches becomes a new record (source "published", identity unverified, no owner) carrying its registration number,
 * foal year, sex, color, breed and pedigree, and its sale entry; later lots of the same horse then match it by
 * registration number or pedigree in the usual way (MatchSaleLots).
 *
 * Only lots that identify a horse: a registration number, or a published sire. A barn name with a foal year and a
 * color ("Dusty, 2013 red roan gelding") can't be told apart from the next horse of that name, so those lots stay in
 * the index. Mules and donkeys, and lots with no name, are left out. A lot whose name is already on a record that
 * doesn't clearly match goes to staff (queued), as in matching; nothing is created beside it.
 */
class CreateRecordsFromSaleLots
{
    private const NOT_HORSES = ['molly', 'john', 'jack', 'jenny', 'mule', 'donkey'];
    private const SEXES = ['gelding' => 'gelding', 'g' => 'gelding', 'mare' => 'mare', 'm' => 'mare', 'stallion' => 'stallion', 's' => 'stallion',
        'filly' => 'filly', 'f' => 'filly', 'colt' => 'colt', 'c' => 'colt'];
    private const BREEDS = ['AQHA' => 'Quarter Horse', 'APHA' => 'Paint', 'ApHC' => 'Appaloosa'];

    /** @return array{created:int, attached:int, linked:int, queued:int, skipped_unidentified:int, skipped_not_horse:int} */
    public function __invoke(string $sourceKey, bool $dryRun = false, ?\Closure $log = null): array
    {
        $out = ['created' => 0, 'attached' => 0, 'linked' => 0, 'queued' => 0, 'skipped_unidentified' => 0, 'skipped_not_horse' => 0];
        $sourceId = DataSource::where('key', $sourceKey)->value('id');
        if (! $sourceId) {
            return $out;
        }
        $system = BuildRecordsFromResults::system();
        $attach = app(AttachSaleLot::class);

        // Best-identified lots first, so later lots of the same horse match the record they made.
        $lots = SaleLot::where('data_source_id', $sourceId)->whereNull('horse_id')->whereNull('review_status')
            ->whereIn('status', ['sold', 'high_bid', 'no_sale', 'offered', 'catalog'])
            ->orderByRaw('registration_number is null, dam_name is null, sire_name is null, sale_date desc nulls last')->get();

        // One transaction per lot (a single one for a whole sale runs Postgres out of locks). A dry run rolls each lot
        // back, so a horse sold twice counts as created twice there.
        foreach ($lots as $i => $lot) {
            DB::beginTransaction();
            try {
                if (in_array(strtolower(trim((string) $lot->sex)), self::NOT_HORSES, true) || in_array(strtolower(trim($lot->horse_name)), ['', 'hip'], true)) {
                    $out['skipped_not_horse']++;
                    continue;
                }
                if (! $lot->registration_number && ! $lot->sire_name) {
                    $out['skipped_unidentified']++;
                    continue;
                }
                [$horse, $tier, $review, $note] = (new MatchSaleLots)->match($lot); // fresh each time: it sees the records made so far
                if (! $horse && $review) {
                    $lot->forceFill(['review_status' => $review, 'review_note' => $note])->save();
                    $out['queued']++;
                    continue;
                }
                if ($horse && ! in_array($tier, MatchSaleLots::AUTO, true)) {
                    continue;
                }
                if (! $horse) {
                    $horse = $this->create($lot, $system);
                    $tier = 'created';
                    $out['created']++;
                }
                $this->identity($horse, $lot, $system);
                if ($lot->publishable() && $attach($lot, $horse, $system, $tier)) {
                    $out['attached']++;
                } else {
                    // A catalog lot, or a kind not published yet: tied to the record, nothing on it until there's a result.
                    $lot->forceFill(['horse_id' => $horse->id, 'match_tier' => $tier, 'matched_at' => now(),
                        'review_status' => $lot->priceKind() && ! $lot->publishable() ? 'held' : null])->save();
                    app(AddPublishedIdentity::class)($horse, $system, ['registry' => $lot->registry, 'registration_number' => $lot->registration_number,
                        'foal_year' => $lot->foal_year, 'pedigree' => $lot->pedigree ?: array_filter(['s' => $lot->sire_name, 'd' => $lot->dam_name, 'ds' => $lot->dam_sire_name])], 'sale lot '.$lot->id);
                    $out['linked']++;
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            } finally {
                if (DB::transactionLevel() > 0) {
                    $dryRun ? DB::rollBack() : DB::commit();
                }
            }
            if ($log && ($i + 1) % 250 === 0) {
                $log(($i + 1).' lots: '.json_encode($out));
            }
        }

        return $out;
    }

    private function create(SaleLot $lot, $system): Horse
    {
        $horse = new Horse(['registered_name' => trim($lot->horse_name), 'birth_precision' => 'unknown']);
        $horse->horsefax_id = CreateHorse::newId();
        $horse->created_by = $system->id;
        $horse->forceFill(['source' => 'published', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns($horse->only(['registered_name', 'birth_precision'])), 'historical');
        Audit::record('horse.created_from_published', $horse, null, ['horsefax_id' => $horse->horsefax_id, 'name' => $horse->registered_name, 'source_url' => $lot->source_url], $horse->id, $system->id);

        return $horse;
    }

    /** Sex, color and breed as the sale printed them, into blanks only, on records nobody looks after yet. */
    private function identity(Horse $horse, SaleLot $lot, $system): void
    {
        if ($horse->activeStewards()->exists()) {
            return;
        }
        $d = [
            'sex' => self::SEXES[strtolower(trim((string) $lot->sex))] ?? null,
            'color' => $lot->color ? trim($lot->color) : null,
            'breed' => self::BREEDS[$lot->registry] ?? ($lot->breed ? trim($lot->breed) : null),
        ];
        $fill = array_filter($d, fn ($v, $f) => $v && ! $horse->$f, ARRAY_FILTER_USE_BOTH);
        if ($fill) {
            $horse->forceFill($fill + ['listed' => true, 'public_summary' => true])->save();
            app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns($fill), 'historical');
        }
    }
}
