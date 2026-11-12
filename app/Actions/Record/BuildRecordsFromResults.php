<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\ShowRecord;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Richard, Oct 17 2026: accept the registered-name index and build the first horse records from it. Each name gets a
 * Horsefax record (identity unverified, source "results", no owner until someone claims it) with its published results
 * attached as historical entries; published parentage (NRCHA) fills the sire, dam and dam's sire, and those horses get
 * records too. Rerunnable: new results for a matched name are attached on the next run.
 *
 * Left for a person instead:
 *  - names only seen as hunter/jumper show names (EquestrianLive): show names aren't registered names;
 *  - names whose results span more than MAX_SPAN years: almost certainly more than one horse;
 *  - names that match an owned record, or more than one record;
 *  - names that are a horse's previous registered name (ShowNameRule: a shown horse can't be renamed).
 * A name with no registered-name match may still be a HOMD listing known only by its barn name: linked to it when
 * LinkBarnNameRecords' checks pass (Oct 22 2026), instead of making a second record.
 */
class BuildRecordsFromResults
{
    public const SHOW_NAME_SOURCES = ['equestrianlive'];
    public const MAX_SPAN = 25;

    public static function system(): User
    {
        return User::firstOrCreate(['email' => 'records@system.horsefax.invalid'], [
            'name' => 'Horsefax Records', 'password' => Hash::make(Str::random(64)),
        ]);
    }

    /** @return array{created:int, linked:int, attached:int, skipped_span:int, skipped_ambiguous:int, held_former_name:int} */
    public function __invoke(?int $limit = null, ?\Closure $log = null): array
    {
        $system = self::system();
        $out = ['created' => 0, 'linked' => 0, 'attached' => 0, 'skipped_span' => 0, 'skipped_ambiguous' => 0, 'held_former_name' => 0];
        $attach = app(AttachShowResults::class);

        // New results for names already on a record.
        ShowRecord::whereNotNull('horse_id')->whereHas('appearances', fn ($q) => $q->whereNull('horse_event_id'))
            ->with('horse')->lazyById(200, 'id')->each(function ($r) use (&$out, $attach, $system) {
                if ($r->horse) {
                    $out['attached'] += $attach($r, $r->horse, $system, false);
                }
            });

        $names = ShowRecord::whereNull('horse_id')
            ->whereHas('appearances', fn ($q) => $q->whereNotIn('data_source_id', \App\Models\DataSource::whereIn('key', self::SHOW_NAME_SOURCES)->select('id')));
        $done = 0;
        foreach ($names->lazyById(200, 'id') as $record) {
            if ($limit !== null && $done >= $limit) {
                break;
            }
            $done++;
            if ($record->first_year && $record->last_year && $record->last_year - $record->first_year > self::MAX_SPAN) {
                $out['skipped_span']++;
                continue;
            }
            if (count($record->registrationNumbers()) > 1) {
                $out['skipped_ambiguous']++; // published with two registration numbers: two horses share the name
                continue;
            }
            // A horse's old name: never a new record (it would duplicate the horse); staff decide.
            if (($formerly = ShowNameRule::horsesFormerlyNamed($record))->isNotEmpty()) {
                foreach ($formerly as $h) {
                    ShowNameRule::flagFormerName($record, $h, $record->display_name);
                }
                $out['held_former_name']++;
                continue;
            }
            $same = Horse::named($record->display_name)->get();
            if ($same->count() > 1 || ($same->count() === 1 && $same->first()->activeStewards()->exists())) {
                $out['skipped_ambiguous']++; // an owner's horse, or several: the owner asks for the match, staff confirm
                continue;
            }
            // No registered-name match: a HOMD listing known only by a barn name may be this horse (LinkBarnNameRecords).
            $barn = null;
            if ($same->isEmpty()) {
                [$barn] = LinkBarnNameRecords::candidate($record->display_name);
                if ($barn && LinkBarnNameRecords::conflict($barn, [$record])) {
                    $barn = null;
                }
            }
            DB::transaction(function () use ($record, $same, $barn, $system, $attach, &$out) {
                $horse = $same->first();
                if (! $horse && $barn) {
                    LinkBarnNameRecords::adoptRegisteredName($barn, $record->display_name, $system);
                    $horse = $barn;
                }
                if ($horse) {
                    $horse->forceFill(['listed' => true, 'public_summary' => true])->save();
                    $out['linked']++;
                } else {
                    $horse = new Horse(['registered_name' => $record->display_name, 'birth_precision' => 'unknown']);
                    $horse->horsefax_id = CreateHorse::newId();
                    $horse->created_by = $system->id;
                    $horse->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
                    app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns($horse->only(['registered_name', 'birth_precision'])), 'historical');
                    Audit::record('horse.created_from_results', $horse, null, ['horsefax_id' => $horse->horsefax_id, 'registered_name' => $horse->registered_name, 'results' => $record->appearances], $horse->id, $system->id);
                    $out['created']++;
                }
                $out['attached'] += $attach($record, $horse, $system, false);
            });
            if ($log && $done % 500 === 0) {
                $log("$done names: ".json_encode($out));
            }
        }

        return $out;
    }
}
