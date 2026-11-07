<?php

namespace App\Harvest;

use App\Actions\Record\BuildRecordsFromResults;
use App\Actions\Record\CreateHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IFCE–SIRE "Fichier des équidés" (data.gouv.fr, Licence Ouverte / Etalab): the public data of every equine registered
 * in France since 1976, about 4.4 million rows of name, breed, sex, color, birth date, birth country and death date.
 * No registration numbers or parents. Kate confirmed reuse is allowed; the licence requires naming the source and date.
 *
 * Each horse and pony becomes a record (source "ifce", unverified, unowned); a death date becomes a death entry.
 * Donkeys and mules are left out for now. These records are never matched to others by name alone (names repeat and
 * there's no number to tell them apart). Bulk inserts in chunks; resumable from the last row done; a row fingerprint
 * keeps a later edition of the file from duplicating horses already imported.
 *
 * Older editions (Oct 27 2026, Richard: "if we missed anything ... go back and get that"): 2025, 2023, 2018 and 2014 are
 * read too, to add horses that later editions dropped, and every new edition from now on. Their layouts differ (";" or "," delimiters, no header in 2014,
 * a stray quote at line ends in 2023, Windows-1252 text). With $loose (every edition after the first): a horse is added only when no French record
 * has the same name, foal date and sex, so a corrected color or country in a later edition never makes a duplicate.
 */
class IfceImporter
{
    public const FILE_DATE = '2026-08-24';
    public const CREDIT = 'IFCE – SIRE, Fichier des équidés, data.gouv.fr (Licence Ouverte), edition of 24 Aug 2026';

    public function __construct(private ?\Closure $log = null) {}

    /** Returns counts. $limit caps rows read this run (testing). */
    public function run(string $file, string $url, ?int $limit = null, int $chunk = 2000, bool $loose = false): array
    {
        $this->loose = $loose;
        $source = DataSource::firstOrCreate(['key' => 'ifce'], [
            'name' => 'IFCE – SIRE Fichier des équidés (France)', 'url' => 'https://www.data.gouv.fr/datasets/fichier-des-equides', 'permission' => 'granted',
            'terms_note' => 'Open data under the Licence Ouverte / Etalab (reuse, including commercial, allowed with credit to the source and date). Kate confirmed (Oct 18 2026). Info chevaux itself is behind Imperva bot protection and is not crawled.',
        ]);
        $page = HarvestPage::firstOrCreate(['url' => $url], ['data_source_id' => $source->id, 'kind' => 'open_data_file', 'status' => 'fetched']);
        $page->fill(['path' => 'harvest/ifce/'.basename($file), 'sha256' => hash_file('sha256', $file), 'fetched_at' => $page->fetched_at ?? now()])->save();
        $done = (int) $page->rows; // rows already processed in an earlier run
        $system = BuildRecordsFromResults::system();
        $out = ['read' => 0, 'skipped_donkey_mule' => 0, 'skipped_no_name' => 0, 'created' => 0, 'already_there' => 0, 'deaths' => 0];

        $fh = fopen($file, 'r');
        $first = (string) fgets($fh);
        $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        if (! preg_match('/^(\xEF\xBB\xBF)?"?RACE"?\s*[;,]/', $first)) {
            rewind($fh); // no header row (2014)
        }
        $row = 0;
        $batch = [];
        $seen = []; // rows with no birth date can be identical yet different horses: number repeats in file order
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $r = array_map(self::clean(...), $r);
            $row++;
            if (trim($r[3] ?? '') === '') {
                $b = md5(strtoupper(implode('|', array_map('trim', array_slice($r, 0, 6)))), true); // fields that don't change between editions
                $r[] = $seen[$b] = ($seen[$b] ?? 0) + 1;
            }
            if ($row <= $done) {
                continue;
            }
            if ($limit !== null && $out['read'] >= $limit) {
                break;
            }
            $out['read']++;
            $batch[] = $r;
            if (count($batch) >= $chunk) {
                $this->chunk($batch, $system->id, $out);
                $page->forceFill(['rows' => $row])->save();
                $batch = [];
                if ($this->log) {
                    ($this->log)($row.' rows: '.json_encode($out));
                }
            }
        }
        if ($batch) {
            $this->chunk($batch, $system->id, $out);
            $page->forceFill(['rows' => $row])->save();
        }
        fclose($fh);
        $finished = $limit === null || $out['read'] < $limit;
        $page->forceFill(['status' => $finished ? 'parsed' : 'fetched'])->save();
        Audit::record('horses.imported_ifce', $page, null, $out + ['through_row' => $row], null, $system->id);

        return $out;
    }

    private bool $loose = false;

    /** Trimmed, stray quotes removed, in UTF-8 (older editions are Windows-1252). */
    public static function clean(?string $v): string
    {
        $v = trim(trim((string) $v), '" ');

        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }

    /** One row: RACE; SEXE; ROBE; DATE_DE_NAISSANCE; PAYS_DE_NAISSANCE; NOM; CHE_COCONSO (not stored); DATE_DE_DECES. */
    public static function parse(array $r): ?array
    {
        [$race, $sexe, $robe, $born, $pays, $nom] = array_pad($r, 8, '');
        $deces = $r[7] ?? '';
        $nth = $r[8] ?? null; // which repeat of an identical undated row this is (death date and food-chain status are left out: they change)
        if (IfceLabels::isDonkeyOrMule($race)) {
            return ['skip' => 'skipped_donkey_mule'];
        }
        $name = trim($nom);
        if ($name === '') {
            return ['skip' => 'skipped_no_name'];
        }
        $date = fn ($d) => preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($d), $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "$m[3]-$m[2]-$m[1]" : null;
        $birth = $date($born);

        return [
            'registered_name' => NrchaHarvester::display(preg_replace('/\s+/', ' ', $name)),
            'breed' => IfceLabels::breed($race), 'sex' => IfceLabels::sex($sexe), 'color' => IfceLabels::color($robe),
            'birth_date' => $birth, 'birth_year' => $birth ? (int) substr($birth, 0, 4) : null, 'birth_precision' => $birth ? 'day' : 'unknown',
            'birth_country' => IfceLabels::country($pays), 'died_on' => $date($deces),
            'external_key' => 'ifce:'.sha1(implode('|', array_map(fn ($v) => strtoupper(trim((string) $v)), [$race, $sexe, $robe, $born, $pays, $nom]))).($nth ? ':'.$nth : ''),
        ];
    }

    private function chunk(array $rows, int $systemId, array &$out): void
    {
        $horses = [];
        foreach ($rows as $r) {
            $h = self::parse($r);
            if (isset($h['skip'])) {
                $out[$h['skip']]++;
                continue;
            }
            $horses[$h['external_key']] = $h; // identical rows in one chunk are one horse
        }
        if (! $horses) {
            return;
        }
        $existing = DB::table('horses')->whereIn('external_key', array_keys($horses))->pluck('external_key')->flip();
        if ($this->loose) {
            // Loose: the same name, foal date and sex already on a French record counts as already there.
            $same = DB::table('horses')->where('source', 'ifce')
                ->whereIn(DB::raw('lower(registered_name)'), array_unique(array_map(fn ($h) => mb_strtolower($h['registered_name']), $horses)))
                ->get(['registered_name', 'birth_date', 'birth_year', 'sex'])
                // Records from API Équidés carry the foal year only: same name, year and sex counts too.
                ->flatMap(fn ($x) => array_filter([mb_strtolower($x->registered_name).'|'.$x->birth_date.'|'.$x->sex => true,
                    ...($x->birth_date ? [] : [mb_strtolower($x->registered_name).'|Y'.$x->birth_year.'|'.$x->sex => true])]));
            foreach ($horses as $key => $h) {
                $n = mb_strtolower($h['registered_name']);
                if (isset($same[$n.'|'.$h['birth_date'].'|'.$h['sex']]) || ($h['birth_year'] && isset($same[$n.'|Y'.$h['birth_year'].'|'.$h['sex']]))) {
                    $existing[$key] = true;
                }
            }
        }
        $now = now();
        $insert = [];
        $deaths = [];
        $ids = $this->newIds(count($horses));
        foreach ($horses as $key => $h) {
            if (isset($existing[$key])) {
                $out['already_there']++;
                continue;
            }
            $id = (string) Str::uuid();
            $insert[] = [
                'id' => $id, 'horsefax_id' => array_pop($ids), 'species' => 'horse', 'registered_name' => $h['registered_name'], 'breed' => $h['breed'],
                'sex' => $h['sex'], 'color' => $h['color'], 'birth_date' => $h['birth_date'], 'birth_year' => $h['birth_year'], 'birth_precision' => $h['birth_precision'],
                'birth_country' => $h['birth_country'], 'external_key' => $key, 'identity_status' => 'unverified', 'listed' => true, 'public_summary' => true,
                'source' => 'ifce', 'created_by' => $systemId, 'created_at' => $now, 'updated_at' => $now,
            ];
            if ($h['died_on']) {
                $deaths[] = [
                    'id' => (string) Str::uuid(), 'horse_id' => $id, 'category' => 'other', 'type' => 'death', 'title' => 'Death', 'occurred_on' => $h['died_on'],
                    'date_precision' => 'day', 'details' => 'Date of death in the French national equine database. Source: '.self::CREDIT.'.',
                    'contributor_id' => $systemId, 'contributor_role' => 'staff', 'source_type' => 'official_source', 'status' => 'unverified', 'visibility' => 'public',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        DB::transaction(function () use ($insert, $deaths) {
            foreach (array_chunk($insert, 500) as $c) {
                DB::table('horses')->insert($c);
            }
            foreach (array_chunk($deaths, 500) as $c) {
                DB::table('horse_events')->insert($c);
            }
        });
        $out['created'] += count($insert);
        $out['deaths'] += count($deaths);
    }

    /** Fresh, unused Horsefax IDs. */
    public function newIds(int $n): array
    {
        $ids = [];
        while (count($ids) < $n) {
            $want = [];
            for ($i = count($ids); $i < $n; $i++) {
                $want[CreateHorse::randomId()] = true;
            }
            $taken = DB::table('horses')->whereIn('horsefax_id', array_keys($want))->pluck('horsefax_id')->flip();
            foreach (array_keys($want) as $id) {
                if (! isset($taken[$id])) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
