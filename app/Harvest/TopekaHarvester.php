<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Http;

/**
 * Topeka Livestock Auction horse sales (topekalivestock.com, Topeka IN), found Oct 22 2026 under Richard's standing
 * approval. GoDaddy site: robots.txt disallows only /404; no terms of use (a copyright line only); no bot wall.
 *
 * The sale pages link catalog PDFs (draft horses, the Seymour draft sale, the Northern Indiana yearling Standardbred
 * speed sale, Friesian and Clydesdale sales) and "market report" PDFs (lot, description, name, price). A catalog lot
 * gives the name, registration number, foaling date, color, sex, breed and a 3-generation pedigree. A market report
 * row whose sale and lot agree with a catalogued horse gives that horse's price.
 *
 * Facts only: catalog remarks, "Bred by", consignor, buyer and their towns are never kept (house rule; the kept PDFs
 * still print them). One request at a time, $delay seconds apart (at least 3), as HorsefaxBot; a 403/429 or a bot
 * check stops the run.
 */
class TopekaHarvester
{
    public const MIN_DELAY = 3.0;
    public const BASE = 'https://topekalivestock.com';
    public const PAGES = ['/', '/horse-sales%2Fshows', '/draft-horse-sale', '/northern-in-yearling-spee', '/seymour-sale', '/northern-in-riding-horse'];

    private DataSource $source;
    private float $lastRequest = 0;
    private int $requests = 0;
    private string $dir;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null, ?string $dir = null)
    {
        if (! app()->runningUnitTests()) {
            $this->delay = max($this->delay, self::MIN_DELAY);
        }
        $this->dir = $dir ?? storage_path('app/private/topeka');
        @mkdir($this->dir.'/pdf', 0775, true);
        @mkdir($this->dir.'/pages', 0775, true);
    }

    public function source(): DataSource
    {
        return $this->source ??= DataSource::firstOrCreate(['key' => 'topeka'], [
            'name' => 'Topeka Livestock Auction horse sales', 'url' => self::BASE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 22 2026: robots.txt disallows only /404; no terms of use posted (copyright line only); no bot protection. Catalog and market-report PDFs linked from the sale pages. Facts only with a link back: remarks, breeders, consignors, buyers and towns are never stored (the kept PDFs still print them). Collected under Richard\'s standing approval (Oct 22 2026).',
        ]);
    }

    /** What a linked PDF is, from its file name: catalog, report, or null (forms, ads, magazines). */
    public static function kind(string $file): ?string
    {
        $f = strtolower(urldecode($file));
        if (preg_match('/consign|form|\bad\b|ad 20|journal|averages/', $f)) {
            return null;
        }
        if (preg_match('/market ?report/', $f)) {
            return 'report';
        }

        return preg_match('/catalog|book/', $f) ? 'catalog' : null;
    }

    /** Download every linked catalog and market report not already kept. */
    public function collect(): array
    {
        $out = ['pages' => 0, 'pdfs_found' => 0, 'downloaded' => 0, 'cached' => 0, 'unavailable' => 0];
        $pdfs = [];
        foreach (self::PAGES as $p) {
            $html = $this->get(self::BASE.$p);
            if ($html === null) {
                continue;
            }
            $out['pages']++;
            file_put_contents($this->dir.'/pages/'.(trim(str_replace(['/', '%2F'], '__', $p), '_') ?: 'home').'.html.gz', gzencode($html, 9));
            preg_match_all('#(?:https:)?//img1\.wsimg\.com/blobby/go/[^"\'\s<>]+?\.pdf#i', html_entity_decode($html), $m);
            foreach ($m[0] as $u) {
                $u = str_starts_with($u, '//') ? 'https:'.$u : $u;
                if ($k = self::kind(basename($u))) {
                    $pdfs[$u] = ['url' => $u, 'kind' => $k, 'page' => $p, 'file' => self::fileName($u)];
                }
            }
        }
        $out['pdfs_found'] = count($pdfs);
        foreach ($pdfs as $info) {
            $path = $this->dir.'/pdf/'.$info['file'];
            if (is_file($path) && filesize($path) > 0) {
                $out['cached']++;
                continue;
            }
            $body = $this->get($info['url']);
            if ($body === null || ! str_starts_with($body, '%PDF')) {
                $out['unavailable']++;
                continue;
            }
            file_put_contents($path, $body);
            $out['downloaded']++;
        }
        $index = is_file($this->dir.'/pdf-index.json') ? json_decode(file_get_contents($this->dir.'/pdf-index.json'), true) : [];
        foreach ($pdfs as $info) {
            $index[$info['file']] = $info;
        }
        file_put_contents($this->dir.'/pdf-index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    /** A stable local name: the GoDaddy folder id (when there is one) + the file name. */
    public static function fileName(string $url): string
    {
        $parts = explode('/', parse_url($url, PHP_URL_PATH));
        $name = urldecode(array_pop($parts));
        $folder = end($parts);
        $prefix = preg_match('/^[0-9a-f-]{36}$/', $folder) ? substr($folder, 0, 8).'_' : '';

        return $prefix.preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    }

    /** @return array<string, array{url:string, kind:string, page:string, file:string}> */
    public function index(): array
    {
        return is_file($this->dir.'/pdf-index.json') ? json_decode(file_get_contents($this->dir.'/pdf-index.json'), true) : [];
    }

    public function pdfPath(string $file): string
    {
        return $this->dir.'/pdf/'.$file;
    }

    /** Catalog lots with any matching market-report price: rows for sale_lots. */
    public function rows(): array
    {
        $catalogs = [];
        $reports = [];
        foreach ($this->index() as $file => $info) {
            $path = $this->pdfPath($file);
            if (! is_file($path)) {
                continue;
            }
            $text = StatePdfHarvester::text(file_get_contents($path));
            $sale = TopekaParser::sale($file, $text);
            if ($info['kind'] === 'catalog') {
                $catalogs[] = ['info' => $info, 'sale' => $sale, 'lots' => TopekaParser::catalog($text)];
            } else {
                $reports[] = ['info' => $info, 'sale' => $sale, 'rows' => TopekaParser::report($text)];
            }
        }
        $rows = [];
        foreach ($catalogs as $c) {
            if (! $c['sale']['date'] || count($c['lots']) < 5) {
                continue;
            }
            $prices = TopekaParser::pricesFor($c['sale'], $reports);
            foreach ($c['lots'] as $l) {
                $p = $prices[$l['lot']] ?? null;
                if ($p && ! TopekaParser::sameName($p['name'], $l['name'])) {
                    $p = null; // the report names a different horse at that lot number
                }
                $past = $c['sale']['date'] < now()->subDays(3)->toDateString();
                $rows[] = $l + ['sale_name' => $c['sale']['name'], 'sale_date' => $c['sale']['date'], 'sale_key' => $c['sale']['key'],
                    'status' => $p ? 'sold' : ($past ? 'offered' : 'catalog'), 'price_cents' => $p['price_cents'] ?? null,
                    'price_source' => $p['source'] ?? null, 'source_url' => $c['info']['url']];
            }
        }

        return $rows;
    }

    public function load(): array
    {
        $src = $this->source();
        $out = ['loaded' => 0, 'sold' => 0, 'offered' => 0, 'catalog' => 0, 'with_reg_number' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($this->rows() as $r) {
            $lot = SaleLot::record($src->id, 'tla:'.$r['sale_key'].':'.$r['lot'], [
                'sale_name' => mb_substr($r['sale_name'], 0, 255), 'sale_date' => $r['sale_date'], 'date_precision' => 'month', 'lot' => $r['lot'], // the cover gives the sale week's first day
                'horse_name' => mb_substr($r['name'], 0, 255), 'registered' => $r['registration_number'] ? true : null, 'registry' => null,
                'registration_number' => $r['registration_number'], 'foal_year' => $r['foal_year'], 'birth_date' => $r['birth_date'],
                'sex' => $r['sex'], 'color' => $r['color'], 'breed' => $r['breed'],
                'sire_name' => $r['pedigree']['s'] ?? null, 'dam_name' => $r['pedigree']['d'] ?? null, 'dam_sire_name' => $r['pedigree']['ds'] ?? null, 'pedigree' => $r['pedigree'] ?: null,
                'status' => $r['status'], 'price_cents' => $r['price_cents'], 'buyers_premium' => 'unknown', 'source_url' => $r['source_url'],
                'note' => mb_substr($r['price_source'] ? 'Price from Topeka Livestock\'s market report: '.$r['price_source'] : ($r['status'] === 'offered' ? 'Catalogued for the sale; no price found in a published market report' : 'Catalogued'), 0, 255),
            ]);
            $out['loaded']++;
            $out[$r['status']]++;
            $out['with_reg_number'] += $r['registration_number'] ? 1 : 0;
            $out[RegistrationOnlyMatch::place($lot, 'Topeka Livestock Auction')]++;
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
            $this->requests++;
            try {
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(180)->get($url);
                [$status, $body] = [$r->status(), $r->body()];
            } catch (\Throwable $e) {
                [$status, $body] = [0, $e->getMessage()];
            }
            $this->say($status.' '.$url);
            $challenge = ! str_starts_with($body, '%PDF') && preg_match('/<title>\s*(Just a moment\.\.\.|Attention Required!|Access denied)|cf-chl-|id="challenge-form"/i', substr($body, 0, 20000));
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

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
