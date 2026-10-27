<?php

namespace App\Harvest;

use App\Actions\Record\BuildRecordsFromResults;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * API Équidés (api-equides.org, listed on data.gouv.fr): an independent mirror of IFCE's public horse pages on
 * infochevaux.ifce.fr, by the Collectif Pixel association; not IFCE. Kate found it; Richard approved it Oct 27 2026
 * ("Yes, download it and build the import"). The bulk file is the documented way to take everything (one download,
 * /v1/extractions/derniere); reuse is under the Licence Ouverte / CRPA L321-1 with the source and date credited.
 * Horsefax does not crawl infochevaux itself (Imperva).
 *
 * The IFCE open-data file already gave us the horses (source "ifce") but no parents. This adds, to those records:
 *  - sire and dam as pedigree lines LINKED to the parents' own IFCE records, so the chart builds four generations by
 *    following the links (Views::pedigreeChart); the dam's sire too when the dam has no record of her own;
 *  - the breeder ("naisseur"), when the record has none (Richard, Oct 27 2026: breeders are public).
 * A row is matched by its IFCE id (horses.ifce_id, set on the first run), else on exact name, foal year and sex when
 * exactly one record and one row share them (IFCE records are never matched by name alone, see Horse::scopeNamed).
 * A row with no French record of that name and sex (same foal year, or either unknown) becomes a new record, source
 * "ifce" (Richard, Oct 27 2026: get what we missed): mostly ancestors, imports and horses newer than the open-data
 * file. Other unmatched rows are left alone. Records that already have a pedigree keep it. Donkeys and mules are left
 * out. Performance indexes, breeding status and race records are not loaded yet.
 *
 * The file is staged in a temporary table and everything else is set-based SQL. Rerunnable: a second run adds nothing.
 */
class ApiEquidesImporter
{
    public const SOURCE = 'Extraction des fiches publiques d\'infochevaux.ifce.fr';

    /** French breed labels of the file => Horsefax names (the rest are kept as IFCE writes them). */
    public const BREEDS = [
        'Trotteur Francais' => 'French Trotter', 'Pur Sang' => 'Thoroughbred', 'Selle Francais' => 'Selle Français', 'Selle Francais Section A' => 'Selle Français (section A)',
        'Selle Francais Section B' => 'Selle Français (section B)', 'Origine Non Const. Poney' => 'Pony (breed not recorded)', 'Origine Non Const. Selle' => 'Riding horse (breed not recorded)',
        'Origine Non Const. Trait' => 'Draft horse (breed not recorded)', 'Cheval De Trait' => 'Draft horse', 'Origine Constatee' => 'Recorded origin', 'Origine Inconnue' => 'Unknown origin',
        'Trait Comtois' => 'Comtois', 'Trait Breton' => 'Breton', 'Arabe' => 'Arabian', 'Anglo-Arabe' => 'Anglo-Arabian', '*Anglo-Arabe*' => 'Anglo-Arabian',
        'Cheval De Selle' => 'Riding horse', 'Pura Raza Espanola' => 'Pura Raza Española', 'Poney Francais De Selle' => 'French Pony', 'Trait Percheron' => 'Percheron',
        'Shetland' => 'Shetland Pony', 'Quarter Horse' => 'Quarter Horse', 'Paint Horse' => 'Paint', 'Appaloosa' => 'Appaloosa', 'Quarter Horse Appendix' => 'Appendix',
        'Connemara' => 'Connemara Pony', 'Trait Ardennais' => 'Ardennes', 'Demi-Sang Arabe' => 'Half-Arabian', 'Pure Race Lusitanienne' => 'Lusitano',
        'Kon. Warm Paard Nederland' => 'KWPN (Dutch Warmblood)', 'Poney Haflinger' => 'Haflinger', 'Cheval De Merens' => 'Mérens', 'Cheval Autre Que Pur Sang' => 'AQPS (non-Thoroughbred racehorse)',
        'Cheval Frison' => 'Friesian', 'Islandais' => 'Icelandic Horse', 'Hannoveraner' => 'Hanoverian', 'Cheval Fjord' => 'Fjord', 'The Morgan Horse' => 'Morgan',
        'Trotteur Etranger' => 'Foreign Trotter', 'Race Inconnue' => 'Unknown breed', 'Miniature Americain' => 'American Miniature Horse', 'Rocky Mountain' => 'Rocky Mountain Horse',
    ];

    public function __construct(private ?\Closure $log = null) {}

    /** The credit line for an extraction date (Y-m-d). */
    public static function credit(string $date = '2026-08-16'): string
    {
        return 'IFCE public horse records (infochevaux.ifce.fr), extraction of '.\Illuminate\Support\Carbon::parse($date)->format('j M Y').', via API Équidés (independent service, Collectif Pixel)';
    }

    public function run(string $file, string $url, bool $dryRun = false, string $extractedOn = '2026-08-16'): array
    {
        $system = BuildRecordsFromResults::system();
        $out = ['rows' => 0, 'skipped_donkey_mule' => 0, 'with_parents' => 0, 'matched_by_id' => 0, 'matched' => 0, 'created' => 0, 'left_alone' => 0,
            'breeders' => 0, 'pedigree_lines' => 0, 'linked_lines' => 0];

        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS ae_rows (api_id text PRIMARY KEY, name text NOT NULL, k text NOT NULL, y int NOT NULL, s text NOT NULL,
            breed text, color text, sire_id text, sire text, dam_id text, dam text, damsire_id text, damsire text, breeder text, horse_id uuid, make_new boolean DEFAULT false)');
        DB::statement('TRUNCATE ae_rows');
        $this->stage($file, $out);
        $this->say('staged '.$out['rows'].' rows');
        DB::statement('CREATE INDEX IF NOT EXISTS ae_rows_key ON ae_rows (k, y, s)');
        DB::statement('ANALYZE ae_rows');

        // Set-based steps over millions of rows: small indexed lookup tables first, and room to hash in memory (the first
        // production run's single UPDATE joining a GROUP BY over 4.3M horses ran for hours).
        DB::statement("SET work_mem = '256MB'");
        // A record that already carries the IFCE id (from an earlier extraction).
        $out['matched_by_id'] = DB::affectingStatement('UPDATE ae_rows a SET horse_id = h.id FROM horses h WHERE h.ifce_id = a.api_id AND h.merged_into_id IS NULL');
        $this->say('matched by id '.$out['matched_by_id']);
        DB::statement('DROP TABLE IF EXISTS ae_ifce');
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE ae_ifce AS
            SELECT lower(registered_name) k, coalesce(birth_year, 0) y, coalesce(sex, '') s, count(*) n,
                   min(id::text) FILTER (WHERE ifce_id IS NULL) free_id, count(*) FILTER (WHERE ifce_id IS NULL) free
            FROM horses WHERE source = 'ifce' AND merged_into_id IS NULL GROUP BY 1, 2, 3
            SQL);
        DB::statement('CREATE INDEX ae_ifce_key ON ae_ifce (k, s, y)');
        DB::statement('ANALYZE ae_ifce');
        DB::statement('DROP TABLE IF EXISTS ae_keys');
        DB::statement('CREATE TEMPORARY TABLE ae_keys AS SELECT k, y, s, count(*) n FROM ae_rows WHERE horse_id IS NULL GROUP BY 1, 2, 3');
        DB::statement('CREATE INDEX ae_keys_key ON ae_keys (k, s, y)');
        DB::statement('ANALYZE ae_keys');
        $this->say('lookup tables ready');
        // Otherwise one record and one row per (name, foal year, sex).
        $out['matched'] = DB::affectingStatement(<<<'SQL'
            UPDATE ae_rows a SET horse_id = i.free_id::uuid
            FROM ae_ifce i JOIN ae_keys u ON u.k = i.k AND u.s = i.s AND u.y = i.y
            WHERE a.horse_id IS NULL AND a.k = i.k AND a.s = i.s AND a.y = i.y AND i.n = 1 AND i.free = 1 AND u.n = 1
            SQL);
        // New to Horsefax: no French record of that name and sex, with the same foal year or one of them unknown.
        $new = DB::affectingStatement(<<<'SQL'
            UPDATE ae_rows a SET make_new = true
            WHERE a.horse_id IS NULL AND NOT EXISTS (SELECT 1 FROM ae_ifce h WHERE h.k = a.k AND h.s = a.s AND (h.y = a.y OR h.y = 0 OR a.y = 0))
            SQL);
        $this->say('matched '.($out['matched_by_id'] + $out['matched']).', new '.$new);
        if ($dryRun) {
            $out['created'] = $new;
            $out['left_alone'] = $out['rows'] - $out['matched_by_id'] - $out['matched'] - $new;
            $out['breeders'] = (int) DB::scalar('SELECT count(*) FROM ae_rows a JOIN horses h ON h.id = a.horse_id WHERE a.breeder IS NOT NULL AND h.breeder IS NULL');
            DB::statement('DROP TABLE IF EXISTS ae_rows, ae_ifce, ae_keys');

            return $out + ['dry_run' => true];
        }

        DB::statement('UPDATE horses h SET ifce_id = a.api_id FROM ae_rows a WHERE a.horse_id = h.id AND h.ifce_id IS NULL');
        $out['created'] = $this->create($system->id);
        $out['left_alone'] = $out['rows'] - $out['matched_by_id'] - $out['matched'] - $out['created'];
        $this->say('created '.$out['created']);

        DB::transaction(function () use ($system, &$out) {
            $out['breeders'] = DB::affectingStatement(<<<'SQL'
                UPDATE horses h SET breeder = a.breeder, updated_at = now() FROM ae_rows a
                WHERE a.horse_id = h.id AND a.breeder IS NOT NULL AND h.breeder IS NULL
                SQL);
            // Sire and dam (and the dam's sire when the dam has no record), only for records with no pedigree yet.
            $out['pedigree_lines'] = DB::affectingStatement(<<<'SQL'
                INSERT INTO pedigree_entries (id, horse_id, position, name, linked_horse_id, status, visibility, added_by, source_type, created_at, updated_at)
                SELECT gen_random_uuid(), a.horse_id, l.pos, l.name, p.horse_id, 'unverified', 'public', ?, 'historical', now(), now()
                FROM ae_rows a
                CROSS JOIN LATERAL (VALUES ('s', a.sire_id, a.sire), ('d', a.dam_id, a.dam), ('ds', a.damsire_id, a.damsire)) l(pos, api_id, name)
                LEFT JOIN ae_rows p ON p.api_id = l.api_id
                WHERE a.horse_id IS NOT NULL AND l.name IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM pedigree_entries e WHERE e.horse_id = a.horse_id)
                  AND (l.pos <> 'ds' OR NOT EXISTS (SELECT 1 FROM ae_rows dm WHERE dm.api_id = a.dam_id AND dm.horse_id IS NOT NULL))
                SQL, [$system->id]);
        });
        $out['linked_lines'] = (int) DB::scalar("SELECT count(*) FROM pedigree_entries WHERE added_by = ? AND source_type = 'historical' AND linked_horse_id IS NOT NULL
            AND horse_id IN (SELECT horse_id FROM ae_rows WHERE horse_id IS NOT NULL)", [$system->id]);
        DB::statement('DROP TABLE IF EXISTS ae_rows, ae_ifce, ae_keys');

        $source = DataSource::firstOrCreate(['key' => 'apiequides'], [
            'name' => 'API Équidés (mirror of IFCE infochevaux public pages)', 'url' => 'https://www.data.gouv.fr/dataservices/api-equides', 'permission' => 'granted',
            'terms_note' => 'Open API on data.gouv.fr, no key; bulk file offered for full extraction. Reuse under the Licence Ouverte / CRPA L321-1, crediting the source and date. Independent service, not IFCE. Richard approved Oct 27 2026.',
        ]);
        $page = HarvestPage::firstOrCreate(['url' => $url], ['data_source_id' => $source->id, 'kind' => 'open_data_file', 'status' => 'fetched']);
        $page->fill(['path' => 'harvest/apiequides/'.basename($file), 'sha256' => hash_file('sha256', $file), 'fetched_at' => $page->fetched_at ?? now(),
            'rows' => $out['rows'], 'status' => 'parsed'])->save();
        Audit::record('horses.imported_apiequides', $page, null, $out + ['credit' => self::credit($extractedOn)], null, $system->id);

        return $out;
    }

    /** Records for the rows marked make_new (source "ifce", unverified, unowned), in batches. */
    private function create(int $systemId): int
    {
        $made = 0;
        $last = '';
        $ids = new IfceImporter;
        while (true) {
            $rows = DB::table('ae_rows')->where('make_new', true)->where('api_id', '>', $last)->orderBy('api_id')->limit(5000)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $hf = $ids->newIds($rows->count());
            $now = now();
            $insert = $rows->values()->map(fn ($r, $i) => [
                'id' => (string) \Illuminate\Support\Str::uuid(), 'horsefax_id' => $hf[$i], 'species' => 'horse', 'registered_name' => $r->name,
                'breed' => $r->breed, 'sex' => $r->s ?: null, 'color' => $r->color, 'birth_year' => $r->y ?: null, 'birth_precision' => $r->y ? 'year' : 'unknown',
                'breeder' => $r->breeder, 'ifce_id' => $r->api_id, 'external_key' => 'ifceapi:'.$r->api_id, 'identity_status' => 'unverified',
                'listed' => true, 'public_summary' => true, 'source' => 'ifce', 'created_by' => $systemId, 'created_at' => $now, 'updated_at' => $now,
            ])->all();
            DB::transaction(function () use ($insert) {
                foreach (array_chunk($insert, 500) as $c) {
                    DB::table('horses')->insert($c);
                }
            });
            $made += count($insert);
            $last = $rows->last()->api_id;
            if ($made % 50000 === 0) {
                $this->say('created '.$made);
            }
        }
        DB::statement('UPDATE ae_rows a SET horse_id = h.id FROM horses h WHERE a.make_new AND h.ifce_id = a.api_id');

        return $made;
    }

    /** Read the file (JSONL, plain or zstd-compressed) into ae_rows in batches. */
    private function stage(string $file, array &$out): void
    {
        $zst = file_get_contents($file, false, null, 0, 4) === "\x28\xB5\x2F\xFD"; // zstd frame magic
        $fh = $zst ? popen('zstd -dc '.escapeshellarg($file), 'r') : fopen($file, 'r');
        if (! $fh) {
            throw new \RuntimeException('Cannot read '.$file);
        }
        $batch = [];
        while (($line = fgets($fh)) !== false) {
            $r = json_decode($line, true);
            if (! is_array($r) || empty($r['id'])) {
                continue;
            }
            if (self::isDonkeyOrMule((string) ($r['race'] ?? ''))) {
                $out['skipped_donkey_mule']++;
                continue;
            }
            $row = self::row($r);
            if (! $row) {
                continue;
            }
            $out['rows']++;
            $out['with_parents'] += ($row['sire'] || $row['dam']) ? 1 : 0;
            $batch[] = $row;
            if (count($batch) >= 2000) {
                DB::table('ae_rows')->insertOrIgnore($batch); // a repeated id is kept once
                $batch = [];
                if ($out['rows'] % 200000 === 0) {
                    $this->say('staged '.$out['rows']);
                }
            }
        }
        if ($batch) {
            DB::table('ae_rows')->insertOrIgnore($batch);
        }
        $zst ? pclose($fh) : fclose($fh);
    }

    /** One file row as a staging row; the name is written the way the IFCE import wrote it. */
    public static function row(array $r): ?array
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($r['nom'] ?? '')));
        if ($name === '') {
            return null;
        }
        // Foreign horses carry their country code, "ONE HOT BLU BOON (USA)": kept in capitals.
        $parent = fn (?array $p) => $p && trim((string) ($p['nom'] ?? '')) !== ''
            ? preg_replace_callback('/\(([a-z]{2,3})\)$/i', fn ($m) => '('.strtoupper($m[1]).')', NrchaHarvester::display(trim(preg_replace('/\s+/', ' ', $p['nom'])))) : null;
        $breeder = trim((string) ($r['naisseur'] ?? ''));

        $race = trim((string) ($r['race'] ?? ''));
        $robe = trim((string) ($r['robe'] ?? ''));

        return [
            'api_id' => $r['id'], 'name' => NrchaHarvester::display($name), 'k' => mb_strtolower(NrchaHarvester::display($name)), 'y' => (int) ($r['annee_naissance'] ?? 0),
            'breed' => $race === '' ? null : (self::BREEDS[$race] ?? $race), 'color' => $robe === '' ? null : IfceLabels::color(mb_strtoupper($robe)),
            's' => ['Male' => 'stallion', 'Femelle' => 'mare', 'Hongre' => 'gelding'][$r['sexe'] ?? ''] ?? '',
            'sire_id' => $r['pere']['id'] ?? null, 'sire' => $parent($r['pere'] ?? null),
            'dam_id' => $r['mere']['id'] ?? null, 'dam' => $parent($r['mere'] ?? null),
            'damsire_id' => $r['pere_de_mere']['id'] ?? null, 'damsire' => $parent($r['pere_de_mere'] ?? null),
            'breeder' => $breeder === '' ? null : mb_substr($breeder, 0, 160),
        ];
    }

    /** Donkeys, mules, hinnies and zebras are left out, as in the IFCE import ("Poitevin Mulassier" is a horse breed). */
    public static function isDonkeyOrMule(string $race): bool
    {
        return (bool) preg_match('/\b(ane|baudet|donkey|mule|mulet|bardot|zebre)\b/i', $race);
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)(now()->format('H:i:s').' '.$line);
        }
    }
}
