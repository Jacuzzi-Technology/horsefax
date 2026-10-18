<?php

namespace App\Harvest;

use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Nebraska Ranch Horse Association stallion directory (nebraskarha.org/stalliondirectory/): each stallion's foal
 * year, registry, color, sire and dam. No terms of use (Oct 17 2026; privacy policy only); robots.txt allows all.
 * One request every 5 seconds; each page read once and kept as evidence. Stud fees, owners and stallion stations are
 * never stored.
 */
class NerhaHarvester
{
    public const LIST = 'https://nebraskarha.org/stalliondirectory/';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 5.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'nerha'], [
            'name' => 'Nebraska Ranch Horse Association stallion directory', 'url' => self::LIST, 'permission' => 'no_terms_posted',
            'terms_note' => 'Oct 17 2026: no terms of use (privacy policy only); robots.txt allows all agents. Richard sent it to collect.',
        ]);
    }

    public function run(): array
    {
        $out = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_owned' => 0, 'skipped_ambiguous' => 0];
        $stallions = [];
        $lists = [self::LIST];
        for ($i = 0; $i < count($lists) && $i < 20; $i++) {
            $body = $this->fetch($lists[$i])?->body() ?? '';
            preg_match_all('#<a[^>]+href="(https://nebraskarha\.org/[a-z0-9-]+/)"[^>]*>\s*Read More#i', $body, $m);
            array_push($stallions, ...$m[1]);
            preg_match_all('#href="(https://nebraskarha\.org/stalliondirectory/\d+/)"#', $body, $p);
            foreach ($p[1] as $u) {
                if (! in_array($u, $lists, true)) {
                    $lists[] = $u;
                }
            }
        }
        foreach (array_unique($stallions) as $url) {
            $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'stallion']);
            if (in_array($page->status, ['parsed', 'skipped'], true)) {
                continue;
            }
            $html = $page->path && Storage::disk('local')->exists($page->path) ? gzdecode(Storage::disk('local')->get($page->path)) : $this->download($page);
            if ($html === null) {
                continue;
            }
            $h = self::page($html);
            if (! $h) {
                $page->fill(['status' => 'skipped', 'error' => 'No sire x dam line'])->save();
                continue;
            }
            $out[app(ImportPublishedHorse::class)($h, $url)]++;
            $page->fill(['status' => 'parsed', 'rows' => count($h['pedigree']), 'year' => $h['foal_year']])->save();
            $out['pages']++;
        }

        return $out;
    }

    /** The post's opening lines: name, year, registry, color, "SIRE x DAM". */
    public static function page(string $html): ?array
    {
        $body = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h\d|/li|/span)[^>]*>#i', "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l)), explode("\n", $text)), fn ($l) => $l !== ''));
        foreach ($lines as $i => $l) {
            if (! preg_match('/^(\d{4})$/', $l, $y) || $i === 0) {
                continue;
            }
            $after = array_slice($lines, $i + 1, 4);
            $registry = null;
            $color = null;
            $cross = null;
            foreach ($after as $a) {
                if (! $cross && preg_match('/^(.+?)\s+x\s+(.+)$/i', $a, $m)) {
                    $cross = [$m[1], $m[2]];
                } elseif (! $registry && preg_match('/\b(AQHA|APHA|ApHC|ARHA)\b/', $a, $r)) {
                    $registry = SuperSiresParser::registry($r[1]);
                } elseif (! $color && ! $cross) {
                    $color = SuperSiresParser::color($a);
                }
            }
            if (! $cross) {
                continue;
            }

            return [
                'name' => NrchaHarvester::display($lines[$i - 1]), 'foal_year' => (int) $y[1], 'registry' => $registry, 'color' => $color,
                'sex' => 'stallion', 'birth_date' => null, 'registration_number' => null, 'results' => [],
                'pedigree' => ['s' => NrchaHarvester::display(trim($cross[0])), 'd' => NrchaHarvester::display(trim($cross[1]))],
            ];
        }

        return null;
    }

    private function download(HarvestPage $page): ?string
    {
        $r = $this->fetch($page->url);
        $page->fill(['http_status' => $r?->status(), 'fetched_at' => now()]);
        if (! $r || ! $r->successful()) {
            $page->fill(['status' => $r?->status() === 404 ? 'skipped' : 'failed', 'error' => $r ? 'HTTP '.$r->status() : 'Request failed'])->save();

            return null;
        }
        $file = 'harvest/nerha/'.sha1($page->url).'.html.gz';
        Storage::disk('local')->put($file, gzencode($r->body(), 9));
        $page->fill(['path' => $file, 'sha256' => hash('sha256', $r->body()), 'status' => 'fetched'])->save();

        return $r->body();
    }

    private function fetch(string $url): ?\Illuminate\Http\Client\Response
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(60)->retry(2, 10000, throw: false)->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        if ($this->log) {
            ($this->log)($r->status().' '.$url);
        }

        return $r;
    }
}
