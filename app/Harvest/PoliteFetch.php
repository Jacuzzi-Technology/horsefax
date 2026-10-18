<?php

namespace App\Harvest;

use App\Models\HarvestPage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The house rules every harvester follows: identify as HorsefaxBot, wait $delay seconds between requests, never
 * refetch a page already kept, and keep a gzipped evidence copy of each page read. Expects $delay, $log, $source.
 */
trait PoliteFetch
{
    private float $lastRequest = 0;

    protected function fetch(string $url): ?Response
    {
        $wait = $this->delay - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
        try {
            $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->timeout(90)->retry(2, 10000, throw: false)->get($url);
        } catch (\Throwable $e) {
            $this->say('failed '.$url.': '.$e->getMessage());

            return null;
        }
        $this->say($r->status().' '.$url);

        return $r;
    }

    /** The page's body: the kept evidence copy when there is one, otherwise fetched once and kept. Null when unavailable. */
    protected function page(string $url, string $kind, ?int $year = null, string $ext = 'html'): ?string
    {
        $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => $kind, 'year' => $year]);
        if ($page->exists && $page->path && Storage::disk('local')->exists($page->path)) {
            return gzdecode(Storage::disk('local')->get($page->path));
        }
        if ($page->exists && $page->status === 'skipped') {
            return null;
        }
        $r = $this->fetch($url);
        if ($r === null || ! $r->successful()) {
            $page->fill(['status' => $r?->status() === 404 ? 'skipped' : 'failed', 'http_status' => $r?->status(), 'error' => $r ? 'HTTP '.$r->status() : 'Request failed', 'fetched_at' => now()])->save();

            return null;
        }
        $file = 'harvest/'.$this->source->key.'/'.($year ?? 'index').'/'.sha1($url).'.'.$ext.'.gz';
        Storage::disk('local')->put($file, gzencode($r->body(), 9));
        $page->fill(['path' => $file, 'sha256' => hash('sha256', $r->body()), 'status' => 'fetched', 'http_status' => $r->status(), 'fetched_at' => now()])->save();

        return $r->body();
    }

    protected function harvestPage(string $url): ?HarvestPage
    {
        return HarvestPage::where('url', $url)->first();
    }

    protected function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
