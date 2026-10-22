<?php

namespace App\Search;

use App\Harvest\CongressHarvester;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\ShowRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Finds published facts about a horse name on the web, within the house rules (Richard, Oct 19 2026):
 *  - Brave Search API results are used only to pick which pages to read; nothing from them (titles, snippets, AI
 *    answers, URLs) is kept.
 *  - Only pages on config horsefax.search.fetch_hosts (sources checked ALLOWED) are fetched: as HorsefaxBot, robots.txt
 *    obeyed, 3 s apart per site, each kept as a gzipped evidence copy (harvest_pages, source "web-search").
 *  - From a page, only identity facts printed beside the name are read: registration number, foal year, sex, sire, dam.
 *    Never owner, rider, breeder, buyer or consignor names.
 */
class WebEvidence
{
    public int $searches = 0;
    public int $fetched = 0;

    /** @var array<string, float> host => last request time */
    private array $last = [];
    /** @var array<string, ?array{disallow: list<string>, allow: list<string>}> */
    private array $robots = [];

    public function __construct(private ?\Closure $log = null) {}

    /** Result URLs on allowed hosts for a query, in rank order. */
    public function search(string $query): array
    {
        $key = config('horsefax.search.brave_key');
        if (! $key) {
            throw new \RuntimeException('BRAVE_SEARCH_API_KEY is not set in .env.');
        }
        $this->wait('api.search.brave.com', 1.1);
        $this->searches++;
        $r = Http::withHeaders(['X-Subscription-Token' => $key, 'Accept' => 'application/json'])->timeout(30)
            ->get('https://api.search.brave.com/res/v1/web/search', ['q' => $query, 'count' => 20, 'country' => 'US', 'safesearch' => 'off']);
        if (! $r->successful()) {
            throw new \RuntimeException('Brave search failed: HTTP '.$r->status());
        }
        $urls = collect($r->json('web.results', []))->pluck('url')->filter(fn ($u) => is_string($u) && self::fetchable($u))->unique()->values()->all();
        $this->say('search "'.$query.'": '.count($urls).' allowed pages');

        return $urls;
    }

    public static function fetchable(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! $host || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return false;
        }
        foreach (config('horsefax.search.fetch_hosts', []) as $ok) {
            if ($host === $ok || str_ends_with($host, '.'.$ok)) {
                return true;
            }
        }

        return false;
    }

    /** Page text from an allowed host (the kept evidence copy if there is one), or null. */
    public function text(string $url): ?string
    {
        if (! self::fetchable($url)) {
            return null;
        }
        $page = HarvestPage::firstOrNew(['url' => mb_substr($url, 0, 600)], ['data_source_id' => self::source()->id, 'kind' => 'search']);
        if ($page->exists && $page->path && Storage::disk('local')->exists($page->path)) {
            $body = gzdecode(Storage::disk('local')->get($page->path));
        } else {
            if ($page->exists && in_array($page->status, ['skipped', 'failed'], true)) {
                return null;
            }
            if (! $this->robotsAllow($url)) {
                $page->fill(['status' => 'skipped', 'error' => 'robots.txt disallows', 'fetched_at' => now()])->save();
                $this->say('robots.txt disallows '.$url);

                return null;
            }
            $this->wait((string) parse_url($url, PHP_URL_HOST), (float) config('horsefax.search.delay', 3));
            try {
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)->get($url);
            } catch (\Throwable $e) {
                $page->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'fetched_at' => now()])->save();

                return null;
            }
            $this->fetched++;
            $this->say($r->status().' '.$url);
            if (! $r->successful()) {
                $page->fill(['status' => $r->status() === 404 ? 'skipped' : 'failed', 'http_status' => $r->status(), 'error' => 'HTTP '.$r->status(), 'fetched_at' => now()])->save();

                return null;
            }
            $body = $r->body();
            $ext = str_contains((string) $r->header('Content-Type'), 'pdf') || str_starts_with($body, '%PDF') ? 'pdf' : 'html';
            $file = 'harvest/web-search/'.sha1($url).'.'.$ext.'.gz';
            Storage::disk('local')->put($file, gzencode($body, 9));
            $page->fill(['path' => $file, 'sha256' => hash('sha256', $body), 'status' => 'fetched', 'http_status' => $r->status(), 'fetched_at' => now()])->save();
        }

        return str_starts_with($body, '%PDF') ? self::pdfText($body) : self::htmlText($body);
    }

    public static function htmlText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/tr|/li|/h\d|/td|/th|/dd|/dt)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace(["/[ \t\x{a0}]+/u", "/\n\s*\n+/"], [' ', "\n"], $text));
    }

    private static function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hfpdf');
        file_put_contents($tmp, $pdf);
        $p = new Process(['pdftotext', '-layout', $tmp, '-']);
        $p->setTimeout(60)->run();
        @unlink($tmp);

        return preg_replace('/[ \t]{2,}/', '  ', $p->getOutput());
    }

    /**
     * Identity facts printed with this name on a page: one set per mention that carries any. Reads only what follows
     * the name on the same entry (up to a blank line or ~300 characters).
     *
     * @return list<array{registration?:string, foal_year?:int, sex?:string, sire?:string, dam?:string}>
     */
    public static function facts(string $text, string $name): array
    {
        $key = ShowRecord::key($name);
        if (strlen($key) < 3) {
            return [];
        }
        $pattern = '/(?<![A-Za-z0-9])'.implode('[^A-Za-z0-9\n]{0,2}', array_map(fn ($c) => preg_quote($c, '/'), str_split($key))).'(?![A-Za-z0-9])/i';
        preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$hit, $at]) {
            $after = substr($text, $at + strlen($hit), 300);
            $after = preg_split('/\n\s*\n|(?=\n\s*(?:Hip|Lot)\s*#?\s*\d)/i', $after)[0];
            $after = preg_split($pattern, $after)[0]; // stop where the name comes up again
            $f = [];
            if (preg_match('/(?:AQHA|APHA|ApHC|Reg(?:istration|istered)?\.?(?:\s*(?:No\.?|Number|#))?)\s*[:#]?\s*(\d{6,8})\b/i', $after, $x)) {
                $f['registration'] = $x[1];
            }
            if (preg_match('/^\s*[,(]?\s*\(?((?:19|20)\d{2})\)?/', $after, $x)
                || preg_match('/(?:foaled|foal(?:ing)?\s+(?:date|year)|YOB|DOB|born)\s*:?\s*(?:[A-Za-z]{3,9}\.?\s+\d{1,2},?\s+|\d{1,2}[\/-]\d{1,2}[\/-])?((?:19|20)\d{2})\b/i', $after, $x)
                || preg_match('/\b((?:19|20)\d{2})\s+(?:[A-Za-z]+\s+){0,3}?(?:gelding|mare|stallion|filly|colt)\b/i', $after, $x)) {
                $y = (int) $x[1];
                if ($y >= 1950 && $y <= (int) date('Y')) {
                    $f['foal_year'] = $y;
                }
            }
            if (preg_match('/\b(gelding|mare|stallion|filly|colt)\b/i', $after, $x)) {
                $f['sex'] = strtolower($x[1]);
            }
            if (preg_match('/\bSire\s*:?\s+([A-Z0-9][A-Za-z0-9\' .&-]{2,40}?)\s*(?=\bDam\b|[\n|,;(]|$)/', $after, $x)) {
                $f['sire'] = trim($x[1], ' .');
            }
            if (preg_match('/\bDam\s*:?\s+([A-Z0-9][A-Za-z0-9\' .&-]{2,40}?)\s*(?=\b(?:Sire|Dam\'?s?\s+Sire|Damsire|by)\b|[\n|,;(]|$)/', $after, $x)) {
                $f['dam'] = trim($x[1], ' .');
            }
            if (! isset($f['sire']) && preg_match('/\bby\s+([A-Z0-9][A-Za-z0-9\' .&-]{2,40}?)\s+(?:and\s+)?out of\s+([A-Z0-9][A-Za-z0-9\' .&-]{2,40}?)\s*(?=[\n,;(.]|\bby\b|$)/', $after, $x)) {
                [$f['sire'], $f['dam']] = [trim($x[1]), trim($x[2], ' .')];
            }
            // A mention with only a sex says nothing about which horse it is.
            if (array_intersect_key($f, array_flip(['registration', 'foal_year', 'sire', 'dam'])) && ! in_array($f, $out, true)) {
                $out[] = $f;
            }
        }

        return $out;
    }

    private function robotsAllow(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $base = $scheme.'://'.$host;
        if (! array_key_exists($base, $this->robots)) {
            $this->wait($host, (float) config('horsefax.search.delay', 3));
            try {
                $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(30)->get($base.'/robots.txt');
                $this->robots[$base] = $r->successful() ? self::parseRobots($r->body()) : ($r->status() >= 400 && $r->status() < 500 ? ['disallow' => [], 'allow' => []] : null);
            } catch (\Throwable) {
                $this->robots[$base] = null;
            }
        }
        $rules = $this->robots[$base];
        if ($rules === null) {
            return false; // robots.txt unreachable: don't guess
        }
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');
        $match = fn (array $list) => max(array_map(fn ($p) => $p !== '' && str_starts_with($path, rtrim($p, '*$')) ? strlen($p) : -1, $list ?: ['']));

        return $match($rules['allow']) >= $match($rules['disallow']);
    }

    /** The rules for HorsefaxBot if it's named, else for "*". */
    public static function parseRobots(string $body): array
    {
        $groups = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($v);
                foreach ($agents as $a) {
                    $groups[$a] ??= ['disallow' => [], 'allow' => []];
                }
            } elseif (in_array($k, ['disallow', 'allow'], true)) {
                $inRules = true;
                foreach ($agents as $a) {
                    if ($v !== '') {
                        $groups[$a][$k][] = $v;
                    }
                }
            }
        }

        return $groups['horsefaxbot'] ?? $groups['*'] ?? ['disallow' => [], 'allow' => []];
    }

    public static function source(): DataSource
    {
        return DataSource::firstOrCreate(['key' => 'web-search'], ['name' => 'Pages found by web search', 'url' => 'https://search.brave.com',
            'terms_note' => 'Only pages on sources already checked ALLOWED are read; facts kept with their URL.']);
    }

    private function wait(string $host, float $seconds): void
    {
        $gap = $seconds - (microtime(true) - ($this->last[$host] ?? 0));
        if ($gap > 0 && ! app()->runningUnitTests()) {
            usleep((int) ($gap * 1_000_000));
        }
        $this->last[$host] = microtime(true);
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
