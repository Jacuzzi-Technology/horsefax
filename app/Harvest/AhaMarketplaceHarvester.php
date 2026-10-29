<?php

namespace App\Harvest;

use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Arabian Horse Association marketplace ads (arabianhorses.org: Horses for Sale, cpid=18; Stallions at Stud, cpid=19).
 * Checked Oct 28 2026: no robots.txt, no terms page, and the lists load with a plain request. The registry's own
 * DataSource database is a separate thing and stays ask-first; these are members' public ads, which Richard asked to
 * collect (Oct 28 2026: "seem to be complete with registration numbers, foal dates, and sire and dams, so it's
 * probably worth getting").
 *
 * Each ad page gives the registered name, AHA number (AHR = Arabian, HAHR = Half-Arabian, AAHR = Anglo-Arabian), foaling
 * date, color and sex, sire, dam and dam's sire with their numbers, breeder (a public field since Oct 27 2026) and
 * height. The record is found or made by ImportPublishedHorse (number first; fills only blanks; never an owner's
 * record). A for-sale ad also becomes an advertised asking price (sale_lots, status asking, never a sale); a stud fee is
 * not a sale price and is not stored. Never kept: the description, contact name, phone, email, address and photos.
 * One request at a time, at least 3 s apart, as HorsefaxBot; a 403/429 or a bot check stops the run.
 */
class AhaMarketplaceHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://www.arabianhorses.org';
    public const LISTS = [18 => 'sale', 19 => 'stud'];
    private const REGISTRIES = ['AHR' => 'Arabian', 'HAHR' => 'Half-Arabian', 'AAHR' => 'Anglo-Arabian'];

    private DataSource $source;
    private float $lastRequest = 0;
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY);
        }
        $this->dir = $dir ?? storage_path('app/private/aha-marketplace');
        @mkdir($this->dir.'/pages', 0775, true);
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'aha-marketplace'], [
            'name' => 'Arabian Horse Association marketplace ads', 'url' => self::BASE.'/login/marketplace/classifieds/marketplaceSearch?cpid=18', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 28 2026: no robots.txt, no terms page, no bot wall; lists load with a plain request. Members\' public ads (not the DataSource registry database). Facts only with a link back: descriptions, contact details and photos are never stored. Richard asked to collect them (Oct 28 2026).',
        ]);
    }

    public static function listUrl(int $cpid): string
    {
        return self::BASE.'/login/marketplace/classifieds/marketplaceSearch?breed=&stateId=&priceRange=&horseName=&onSubmit=Search&cpid='.$cpid;
    }

    public static function adUrl(string $horseId, string $caid): string
    {
        return self::BASE.'/login/marketplace/classifieds/marketplaceSearchResult?horseId='.$horseId.'&caid='.$caid;
    }

    /** Result rows: horseId, caid, name, number, foaled, sex, state, price. */
    public static function listRows(string $html): array
    {
        preg_match_all('#<tr>\s*<td><a href="[^"]*marketplaceSearchResult\?horseId=(\d+)&(?:amp;)?caid=(\d+)"[^>]*>(.*?)</a></td>\s*<td>(.*?)</td>\s*<td[^>]*>.*?</td>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>#s', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => ['horse_id' => $r[1], 'caid' => $r[2], 'name' => self::clean($r[3]), 'number' => self::clean($r[4]), 'foaled' => self::clean($r[5]),
            'sex' => self::clean($r[6]), 'state' => self::clean($r[7]), 'price' => self::clean($r[8])], $m);
    }

    /** An ad page's facts (no description or contact details). */
    public static function ad(string $html): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", self::clean(preg_replace('#<(br|/div|/span|/li|/td|/tr|/p|/h\d|/dt|/dd)\b[^>]*>#i', "\n", $html), true))), fn ($l) => $l !== ''));
        $after = function (string $label, int $n = 1) use ($lines) {
            $i = array_search($label, $lines, true);

            return $i === false ? [] : array_slice($lines, $i + 1, $n);
        };
        $head = null;
        foreach ($lines as $l) {
            if (preg_match('/^(.+?)\s+((?:AHR|HAHR|AAHR|CAHR|CPAR)\*[0-9A-Z]+)$/', $l, $m)) {
                $head = [$m[1], $m[2]];
                break;
            }
        }
        $parent = function (string $label) use ($after) {
            [$num, $name] = $after($label, 2) + [null, null];
            if (! $num || ! preg_match('/^[A-Z]{2,5}\*[0-9A-Z]+$/', $num)) {
                return null;
            }

            return ['name' => self::caps($name), 'number' => $num];
        };
        $colorSex = $after('Gender')[0] ?? '';
        $height = ($after('Height:')[0] ?? '');

        return [
            'name' => $head ? self::caps($head[0]) : null, 'number' => $head[1] ?? null,
            'foaled' => $after('Foaled')[0] ?? null,
            'color' => preg_match('/^(.*?)\s*(MARE|GELDING|STALLION|FILLY|COLT)$/i', $colorSex, $cs) && trim($cs[1]) !== '' ? ucfirst(strtolower(trim($cs[1]))) : null,
            'sex' => preg_match('/(MARE|GELDING|STALLION|FILLY|COLT)$/i', $colorSex, $sx) ? strtolower($sx[1]) : null,
            'sire' => $parent('Sire'), 'dam' => $parent('Dam'), 'dam_sire' => $parent("Dam's Sire"),
            'breeder' => ($b = $after('Breeder')[0] ?? null) && ! in_array($b, ['Groups', 'Ad Details'], true) ? self::caps($b) : null,
            'height' => preg_match('/^(\d{1,2}(?:\.[0-3])?)\s*Hands?$/i', $height, $h) ? (float) $h[1] : null,
            'price' => preg_match('/^\$?\s*([\d,]+)$/', $after('Price:')[0] ?? '', $p) ? (int) str_replace(',', '', $p[1]) : null,
        ];
    }

    /** "AHR*692103" -> [registry AHA, number 692103, breed Arabian]. */
    public static function number(?string $printed): array
    {
        if (! $printed || ! preg_match('/^([A-Z]{2,5})\*([0-9A-Z]+)$/', $printed, $m)) {
            return [null, null, null];
        }

        return ['AHA', $m[2], self::REGISTRIES[$m[1]] ?? null];
    }

    /** Read both lists and every ad page (kept gzipped; a rerun fetches only new ads). */
    public function collect(): array
    {
        $out = ['ads' => 0, 'fetched' => 0, 'cached' => 0];
        $index = [];
        foreach (self::LISTS as $cpid => $kind) {
            $html = $this->get(self::listUrl($cpid)) ?? '';
            foreach (self::listRows($html) as $r) {
                $index[$r['caid']] = $r + ['kind' => $kind];
            }
        }
        foreach ($index as $caid => $r) {
            $out['ads']++;
            $f = $this->dir.'/pages/'.$caid.'.html.gz';
            if (is_file($f)) {
                $out['cached']++;
                continue;
            }
            if (($html = $this->get(self::adUrl($r['horse_id'], $caid))) !== null) {
                file_put_contents($f, gzencode($html, 9));
                $out['fetched']++;
            }
        }
        file_put_contents($this->dir.'/index.json', json_encode(array_values($index), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    public function load(): array
    {
        $src = $this->source();
        $out = ['ads' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_owned' => 0, 'skipped_ambiguous' => 0, 'asking_prices' => 0, 'no_page' => 0];
        $index = is_file($this->dir.'/index.json') ? json_decode(file_get_contents($this->dir.'/index.json'), true) : [];
        foreach ($index as $r) {
            $f = $this->dir.'/pages/'.$r['caid'].'.html.gz';
            if (! is_file($f)) {
                $out['no_page']++;
                continue;
            }
            $a = self::ad(gzdecode(file_get_contents($f)));
            [$registry, $number, $breed] = self::number($a['number'] ?? $r['number']);
            $birth = $a['foaled'] && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $a['foaled'], $d) && checkdate((int) $d[1], (int) $d[2], (int) $d[3]) ? sprintf('%04d-%02d-%02d', $d[3], $d[1], $d[2]) : null;
            $line = fn ($p) => $p ? array_filter(['name' => $p['name'], 'registration_number' => self::number($p['number'])[1], 'registry' => self::number($p['number'])[0]]) : null;
            $pedigree = array_filter(['s' => $line($a['sire']), 'd' => $line($a['dam']), 'ds' => $line($a['dam_sire'])]);
            $url = self::adUrl($r['horse_id'], $r['caid']);
            $name = $a['name'] ?? self::caps($r['name']);
            $out['ads']++;
            $import = app(ImportPublishedHorse::class);
            $out[$import(['name' => $name, 'registry' => $registry, 'registration_number' => $number, 'foal_year' => $birth ? (int) substr($birth, 0, 4) : null,
                'birth_date' => $birth, 'sex' => $a['sex'], 'color' => $a['color'], 'breed' => $breed, 'breeder' => $a['breeder'], 'height' => $a['height'],
                'pedigree' => $pedigree], $url)]++;
            if ($r['kind'] !== 'sale' || ! $import->last) {
                continue; // a stud fee is not a sale price
            }
            $price = $a['price'] ?? (ctype_digit(str_replace(',', '', $r['price'])) ? (int) str_replace(',', '', $r['price']) : null);
            $lot = SaleLot::record($src->id, 'aha:'.$r['caid'], [
                'sale_name' => 'Arabian Horse Association marketplace ad', 'sale_date' => now()->toDateString(), 'date_precision' => 'day',
                'horse_name' => mb_substr($name, 0, 255), 'registered' => true, 'registry' => $registry, 'registration_number' => $number,
                'foal_year' => $birth ? (int) substr($birth, 0, 4) : null, 'birth_date' => $birth, 'sex' => $a['sex'], 'color' => $a['color'], 'breed' => $breed,
                'sire_name' => $a['sire']['name'] ?? null, 'dam_name' => $a['dam']['name'] ?? null, 'dam_sire_name' => $a['dam_sire']['name'] ?? null,
                'status' => 'asking', 'price_cents' => $price ? $price * 100 : null, 'buyers_premium' => 'none', 'source_url' => $url,
                'note' => 'Advertised asking price on the Arabian Horse Association marketplace',
            ]);
            if (! $lot->horse_id) {
                $lot->forceFill(['horse_id' => $import->last->id, 'match_tier' => 'registration', 'matched_at' => now(), 'review_status' => 'held',
                    'review_note' => 'Advertised asking prices are not published yet'])->save();
            }
            $out['asking_prices']++;
        }

        return $out;
    }

    private function get(string $url): ?string
    {
        for ($attempt = 0, $backoff = 30; ; $attempt++, $backoff *= 2) {
            $wait = $this->delay - (microtime(true) - $this->lastRequest);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $this->lastRequest = microtime(true);
            try {
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            if ($this->log) {
                ($this->log)($status.' '.$url);
            }
            $challenge = (bool) preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!|Access denied)|cf-chl-/i', substr($body, 0, 20000));
            if ($status === 200 && ! $challenge) {
                return $body;
            }
            if (in_array($status, [403, 429], true) || ($status === 200 && $challenge)) {
                throw new HarvestBlocked("Blocked or rate-limited at $url (HTTP $status): stopping. No workaround.");
            }
            if ($status === 404 || $attempt >= 3) {
                return null;
            }
            sleep(app()->runningUnitTests() ? 0 : $backoff);
        }
    }

    /** "AA-CORDIELLA" -> "AA-Cordiella", "AA-CINDERELLA-I" -> "AA-Cinderella-I", "AL REYHAN MAROU" -> "Al Reyhan Marou". */
    public static function caps(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || $name !== strtoupper($name)) {
            return $name === '' ? null : $name;
        }

        return preg_replace_callback('/[A-Z0-9\'’.]+/u', function ($m) use ($name) {
            $w = $m[0];
            $hyphenated = (bool) preg_match('/(^|-)'.preg_quote($w, '/').'(-|$)/', $name);
            if (preg_match('/^(I|II|III|IV|V|VI|VII|VIII|IX|X)$/', $w) || ($hyphenated && strlen($w) <= 2) || preg_match('/^[BCDFGHJKLMNPQRSTVWXZ]{2,3}$/', $w)) {
                return $w; // a roman numeral, a farm prefix ("AA-"), or vowelless initials ("JA" stays as letters only when hyphenated)
            }

            return ucfirst(strtolower($w));
        }, $name);
    }

    public static function clean(string $html, bool $keepLines = false): string
    {
        $s = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html)), ENT_QUOTES | ENT_HTML5);

        return trim($keepLines ? preg_replace('/[ \t]+/', ' ', $s) : preg_replace('/\s+/u', ' ', $s));
    }
}
