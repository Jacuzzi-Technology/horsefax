<?php

namespace App\Actions\Record;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\Media;
use App\Models\PedigreeEntry;
use App\Models\SaleLot;
use App\Models\ShowRecord;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Horse of My Dreams listings into Horsefax (Richard, Oct 18 2026: "Absolutely scrape all of the information of HOMD.
 * You can even pull the pictures and video links."). HOMD is Richard's own site; the data comes from its WordPress
 * export (bin/homd-export.php), which leaves out the buyer's name, vet exams and radiographs.
 *
 * Per horse: find the Horsefax record (registered name, ruled out by any contradicting sire, dam or foal year; only an
 * unowned record), or create one (source "published"; barn name only when HOMD has no registered name). Fill blank
 * identity fields and pedigree (historical, unverified), add the listing as an ownership entry linking back to HOMD,
 * keep the seller's description on the record for staff only (copying it publicly would compete with HOMD's own page
 * in search), keep the asking price as an unpublished sale lot, and add the photos and video link as released media.
 * Photos are re-saved without metadata under generic names, with no captions: no photographer credit travels with them.
 */
class ImportHomdHorse
{
    /** HOMD pedigree fields by Horsefax position. */
    public const PEDIGREE = [
        's' => 'parent-a', 'd' => 'parent-b', 'ss' => 'grandparent-a', 'sd' => 'grandparent-b', 'ds' => 'grandparent-c', 'dd' => 'grandparent-d',
        'sss' => 'great-grandparent-a', 'ssd' => 'great-grandparent-b', 'sds' => 'great-grandparent-c', 'sdd' => 'great-grandparent-d',
        'dss' => 'great-grandparent-e', 'dsd' => 'great-grandparent-f', 'dds' => 'great-grandparent-g', 'ddd' => 'great-grandparent-h',
    ];

    public const RIGHTS_NOTE = 'Richard Jacuzzi, owner of Horse of My Dreams, authorized publishing HOMD listing photos and videos on Horsefax (Oct 18 2026).';

    public static function source(): DataSource
    {
        return DataSource::firstOrCreate(['key' => 'homd'], [
            'name' => 'Horse of My Dreams (horseofmydreams.com)', 'url' => 'https://www.horseofmydreams.com/', 'permission' => 'granted',
            'terms_note' => 'Richard Jacuzzi\'s own site. He directed the import of all HOMD horse information, photos and video links (Oct 18 2026). Buyer names, vet exams and radiographs are not exported.',
        ]);
    }

    /** @return string created, updated, skipped_owned, skipped_ambiguous, skipped_done */
    public function __invoke(array $h, bool $photos = true, float $delay = 0.5, ?\Closure $log = null): string
    {
        $source = self::source();
        $page = HarvestPage::firstOrNew(['url' => $h['url']], ['data_source_id' => $source->id, 'kind' => 'lot', 'year' => (int) substr($h['listed_on'], 0, 4)]);
        if ($page->status === 'parsed') {
            return 'skipped_done';
        }
        $system = BuildRecordsFromResults::system();
        $d = self::normalize($h);

        [$horse, $status] = $this->record($d, $system);
        if (! $horse) {
            $page->fill(['status' => 'skipped', 'error' => $status, 'fetched_at' => now()])->save();

            return $status;
        }

        DB::transaction(function () use ($horse, $d, $system, $source) {
            $this->identity($horse, $d, $system);
            $this->listing($horse, $d, $system, $source);
        });
        if ($photos) {
            $this->media($horse, $d, $system, $delay, $log);
        }
        $file = 'harvest/homd/'.sha1($h['url']).'.json.gz';
        \Illuminate\Support\Facades\Storage::disk('local')->put($file, gzencode(json_encode($h), 9));
        $page->fill(['path' => $file, 'status' => 'parsed', 'rows' => 1, 'fetched_at' => now(), 'error' => null])->save();
        Audit::record('horse.imported_from_homd', $horse, null, ['url' => $h['url'], 'status' => $status], $horse->id, $system->id);

        return $status;
    }

    public static function normalize(array $h): array
    {
        $up = fn ($v) => \App\Harvest\NrchaHarvester::display(trim((string) $v)) ?: null;
        $breed = trim((string) $h['horse-breed']);
        [$breedName, $registry] = match (true) {
            (bool) preg_match('/^(AQHA|Quarter Horse)$/i', $breed) => ['Quarter Horse', 'AQHA'],
            (bool) preg_match('/^(APHA|Paint)$/i', $breed) => ['Paint', 'APHA'],
            (bool) preg_match('/^(ApHC|Appaloosa)$/i', $breed) => ['Appaloosa', 'ApHC'],
            default => [$breed ?: null, null],
        };
        $pedigree = [];
        foreach (self::PEDIGREE as $pos => $field) {
            if ($n = $up($h[$field] ?? '')) {
                $pedigree[$pos] = $n;
            }
        }
        $price = (int) preg_replace('/\D/', '', (string) $h['horse-price']);
        // Older listings put the nickname in the name: "Aliant's Boston Bay aka "Beijing"".
        $registered = trim(html_entity_decode((string) $h['registered_name'], ENT_QUOTES));
        $barn = (string) $h['barn_name'];
        if (preg_match('/^(.*?)\s+a\.?k\.?a\.?\s*(.+)$/iu', $registered, $aka)) {
            [$registered, $barn] = [trim($aka[1]), $barn ?: trim($aka[2], " \"'“”‘’")];
        }
        $registered = str_replace(['’', '‘'], "'", $registered);

        return [
            'url' => $h['url'], 'listed_on' => $h['listed_on'], 'registered_name' => $up($registered),
            'barn_name' => trim(preg_replace(['/\s+aka\s+.*$/i', '/["“”]/u'], '', html_entity_decode($barn, ENT_QUOTES))) ?: null,
            'foal_year' => preg_match('/^(19|20)\d\d$/', (string) $h['horse-year-foaled']) ? (int) $h['horse-year-foaled'] : null,
            'height' => preg_match('/^(\d{1,2}(?:\.\d)?)\s*h/i', (string) $h['horse-height'], $m) ? (float) $m[1] : null,
            'color' => trim((string) $h['horse-color']) ?: null, 'breed' => $breedName, 'registry' => $registry,
            'sex' => strtolower(trim((string) $h['horse-gender'])) ?: null,
            'pedigree' => $pedigree, 'description' => trim(preg_replace('/^\s*Description\s*/i', '', (string) $h['horse-description'])) ?: null,
            'video' => trim((string) $h['horse-video']) ?: null, 'photos' => $h['photos'] ?? [],
            'price_cents' => $price > 0 ? $price * 100 : null,
            'sold' => (bool) array_filter($h['categories'] ?? [], fn ($c) => str_starts_with($c, 'SOLD') || str_starts_with($c, 'Recent Sales')),
        ];
    }

    /** @return array{0:?Horse, 1:string} */
    private function record(array $d, $system): array
    {
        if ($d['registered_name']) {
            // A same-named record is ruled out by any contradiction: different sire, dam, foal year, or shown before foaled.
            $probe = new SaleLot(['horse_name' => $d['registered_name'], 'sire_name' => $d['pedigree']['s'] ?? null,
                'dam_name' => $d['pedigree']['d'] ?? null, 'foal_year' => $d['foal_year']]);
            $match = app(MatchSaleLots::class);
            $fits = Horse::named($d['registered_name'])->get()->reject(fn ($x) => $match->contradiction($probe, $x) !== null)->values();
            if ($fits->count() > 1) {
                $strong = $fits->filter(function ($x) use ($d) {
                    $p = $x->pedigree()->whereIn('position', ['s', 'd'])->pluck('name', 'position');

                    return isset($d['pedigree']['s'], $d['pedigree']['d'], $p['s'], $p['d'])
                        && ShowRecord::key($p['s']) === ShowRecord::key($d['pedigree']['s']) && ShowRecord::key($p['d']) === ShowRecord::key($d['pedigree']['d']);
                });
                if ($strong->count() !== 1) {
                    return [null, 'skipped_ambiguous'];
                }
                $fits = $strong->values();
            }
            if ($fits->count() === 1) {
                return $fits[0]->activeStewards()->exists() ? [null, 'skipped_owned'] : [$fits[0], 'updated'];
            }
        }
        $horse = new Horse(['registered_name' => $d['registered_name'], 'barn_name' => $d['registered_name'] ? null : $d['barn_name'], 'birth_precision' => 'unknown']);
        $horse->horsefax_id = CreateHorse::newId();
        $horse->created_by = $system->id;
        $horse->forceFill(['source' => 'published', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns($horse->only(['registered_name', 'barn_name', 'birth_precision'])), 'historical');
        Audit::record('horse.created_from_published', $horse, null, ['horsefax_id' => $horse->horsefax_id, 'name' => $horse->displayName(), 'source_url' => $d['url']], $horse->id, $system->id);

        return [$horse, 'created'];
    }

    /** Blank identity fields and pedigree only; never over what's there. */
    private function identity(Horse $horse, array $d, $system): void
    {
        $fill = [];
        foreach (['sex', 'color', 'breed'] as $f) {
            if (! $horse->$f && $d[$f]) {
                $fill[$f] = $d[$f];
            }
        }
        if (! $horse->barn_name && $d['barn_name'] && $d['registered_name'] && ShowRecord::key($d['barn_name']) !== ShowRecord::key($d['registered_name'])) {
            $fill['barn_name'] = $d['barn_name'];
        }
        if (($horse->birth_precision ?? 'unknown') === 'unknown' && $d['foal_year']) {
            $fill += ['birth_precision' => 'year', 'birth_year' => $d['foal_year']];
        }
        if (! $horse->height_hands && $d['height']) {
            $fill['height_hands'] = $d['height'];
        }
        if ($fill) {
            $horse->forceFill($fill + ['listed' => true, 'public_summary' => true])->save();
            app(RecordFacts::class)($horse, $system, RecordFacts::fromColumns($horse->only(array_merge(array_keys($fill), ['birth_precision', 'birth_year', 'birth_date']))), 'historical');
        }
        $have = $horse->pedigree()->pluck('position')->flip();
        $added = 0;
        foreach ($d['pedigree'] as $pos => $name) {
            if (! isset($have[$pos])) {
                PedigreeEntry::create(['horse_id' => $horse->id, 'position' => $pos, 'name' => $name, 'visibility' => 'public',
                    'added_by' => $system->id, 'source_type' => 'historical', 'status' => 'unverified']);
                $added++;
            }
        }
        if ($added) {
            app(CreateAncestorRecords::class)($horse, $system);
        }
    }

    /** The listing (public, links to HOMD), the seller's description (staff only) and the asking price (held). */
    private function listing(Horse $horse, array $d, $system, DataSource $source): void
    {
        if (HorseEvent::where('horse_id', $horse->id)->where('details', 'like', '%'.$d['url'].'%')->exists()) {
            return;
        }
        HorseEvent::create([
            'horse_id' => $horse->id, 'category' => 'ownership', 'type' => 'sale',
            'title' => $d['sold'] ? 'Reported sold by Horse of My Dreams' : 'Listed for sale by Horse of My Dreams', // Kate: listed / reported sold / confirmed sale
            'sale_venue' => 'Horse of My Dreams', 'occurred_on' => $d['listed_on'], 'date_precision' => 'month',
            'details' => (str_starts_with($d['url'], 'http') ? 'From the published listing: '.$d['url'] : 'From an archived Horse of My Dreams listing (earlier version of the website, no longer online).')
                ."\nDate shown is when the listing was posted.",
            'contributor_id' => $system->id, 'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'public',
        ]);
        if ($d['description']) {
            HorseEvent::create([
                'horse_id' => $horse->id, 'category' => 'training', 'type' => 'evaluation', 'title' => 'Seller\'s description (Horse of My Dreams)',
                'occurred_on' => $d['listed_on'], 'date_precision' => 'month', 'details' => $d['description'].(str_starts_with($d['url'], 'http') ? "\n\nFrom the listing: ".$d['url'] : "\n\nFrom an archived Horse of My Dreams listing."),
                'contributor_id' => $system->id, 'contributor_role' => 'staff', 'source_type' => 'historical', 'status' => 'unverified', 'visibility' => 'private',
            ]);
        }
        if ($d['price_cents']) {
            SaleLot::record($source->id, 'homd:'.$d['url'], [
                'sale_name' => 'Horse of My Dreams (listing)', 'sale_date' => $d['listed_on'], 'date_precision' => 'month',
                'horse_name' => $horse->displayName(), 'registry' => $d['registry'], 'foal_year' => $d['foal_year'], 'sex' => $d['sex'], 'color' => $d['color'], 'breed' => $d['breed'],
                'sire_name' => $d['pedigree']['s'] ?? null, 'dam_name' => $d['pedigree']['d'] ?? null, 'dam_sire_name' => $d['pedigree']['ds'] ?? null,
                'status' => 'asking', 'price_cents' => $d['price_cents'], 'source_url' => $d['url'],
                'horse_id' => $horse->id, 'match_tier' => 'homd', 'matched_at' => now(), 'review_status' => 'held',
                'review_note' => 'Advertised asking prices are not published yet',
            ]);
        }
    }

    private function media(Horse $horse, array $d, $system, float $delay, ?\Closure $log): void
    {
        $add = app(AddMedia::class);
        if ($d['video'] && ! Media::where('horse_id', $horse->id)->where('url', $d['video'])->exists()) {
            $add($horse, $system, ['caption' => null, 'url' => $d['video'], 'rights_attested' => true, 'visibility' => 'public'], null, null);
        }
        if (Media::where('horse_id', $horse->id)->where('kind', 'photo')->where('uploaded_by', $system->id)->exists()) {
            return; // photos already brought over
        }
        foreach ($d['photos'] as $i => $url) {
            if (str_starts_with($url, '/')) {
                $img = is_file($url) ? @imagecreatefromstring(file_get_contents($url)) : false; // copied from the archived site
            } else {
                usleep((int) ($delay * 1_000_000));
                try {
                    $r = Http::withHeaders(['User-Agent' => \App\Harvest\CongressHarvester::UA])->timeout(60)->get($url);
                } catch (\Throwable) {
                    continue;
                }
                $img = $r->successful() ? @imagecreatefromstring($r->body()) : false;
            }
            if (! $img) {
                $log && $log('photo failed: '.$url);
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'hfimg').'.jpg';
            imagejpeg($img, $tmp, 86); // re-encoded: no EXIF/IPTC metadata survives
            imagedestroy($img);
            $file = new UploadedFile($tmp, 'photo-'.($i + 1).'.jpg', 'image/jpeg', null, true);
            $add($horse, $system, ['caption' => null, 'rights_attested' => true, 'visibility' => 'public'], $file, null);
            @unlink($tmp);
        }
        Audit::record('media.rights_note', $horse, null, ['note' => self::RIGHTS_NOTE, 'photos' => count($d['photos'])], $horse->id, $system->id);
    }
}
