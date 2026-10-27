<?php

namespace App\Console\Commands;

use App\Harvest\ApiEquidesImporter;
use App\Harvest\CongressHarvester;
use App\Harvest\IfceImporter;
use App\Models\HarvestPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The French sources, checked on a schedule (Richard, Oct 27 2026: "make sure both of these are in a regular check and
 * download list for new additions"). Weekly cron on the server.
 *
 *  1. IFCE "Fichier des équidés" on data.gouv.fr: every CSV edition not imported yet is downloaded and imported, newest
 *     first. That takes in the older editions (2025, 2023, 2018, 2014) once, then each new yearly edition. Editions after
 *     the first add a horse only when no French record has its name, foal date and sex (IfceImporter $loose).
 *  2. API Équidés: when /v1/extractions serves an extraction not imported yet, the file is downloaded once and loaded
 *     (parents, breeders, new horses; see ApiEquidesImporter).
 *
 * HorsefaxBot, at least 3 s between requests. Files are kept in storage/app/private/harvest/{ifce,apiequides}.
 *
 *   php -d memory_limit=1G artisan hf:check-france [--dry-run]
 */
class CheckFrance extends Command
{
    protected $signature = 'hf:check-france {--dry-run : list what is new, download and import nothing}';

    protected $description = 'Import new or missed editions of the French national equine files (IFCE open data, API Équidés)';

    public const DATASET = 'https://www.data.gouv.fr/api/1/datasets/fichier-des-equides/';
    public const EXTRACTIONS = 'https://api-equides.org/v1/extractions';

    private float $last = 0;

    public function handle(): int
    {
        $log = fn ($l) => $this->line(now()->format('H:i:s').' '.$l);

        // 1. IFCE open-data editions.
        $editions = collect($this->json(self::DATASET)['resources'] ?? [])
            ->filter(fn ($r) => strtolower((string) ($r['format'] ?? '')) === 'csv')->sortByDesc('last_modified')->values();
        foreach ($editions as $r) {
            if (HarvestPage::where('url', $r['url'])->where('status', 'parsed')->exists()) {
                continue;
            }
            $date = substr((string) $r['last_modified'], 0, 10);
            $this->info('IFCE edition '.$date.' not imported: '.$r['url']);
            if ($this->option('dry-run')) {
                continue;
            }
            $file = storage_path('app/private/harvest/ifce/ifce-'.str_replace('-', '', $date).'.csv');
            if (! $this->download($r['url'], $file, $r['filesize'] ?? null)) {
                continue;
            }
            $out = (new IfceImporter($log))->run($file, $r['url'], loose: true);
            $this->info('IFCE '.$date.': '.json_encode($out));
        }

        // 2. API Équidés extraction.
        $x = collect($this->json(self::EXTRACTIONS)['donnees'] ?? [])->firstWhere('servie', true);
        if ($x) {
            $url = 'https://api-equides.org'.$x['telechargement'];
            if (HarvestPage::where('url', $url)->where('status', 'parsed')->exists()) {
                $this->info('API Équidés: extraction of '.$x['ingere_le'].' already imported.');
            } else {
                $this->info('API Équidés: extraction of '.$x['ingere_le'].' not imported.');
                $file = storage_path('app/private/harvest/apiequides/chevaux-'.str_replace('-', '', $x['ingere_le']).'.jsonl.zst');
                if (! $this->option('dry-run') && $this->download($url, $file, $x['octets'] ?? null)) {
                    $out = (new ApiEquidesImporter($log))->run($file, $url, false, $x['ingere_le']);
                    $this->info('API Équidés: '.json_encode($out));
                }
            }
        }

        return self::SUCCESS;
    }

    private function wait(): void
    {
        $gap = 3.0 - (microtime(true) - $this->last);
        if ($gap > 0) {
            usleep((int) ($gap * 1_000_000));
        }
        $this->last = microtime(true);
    }

    private function json(string $url): array
    {
        $this->wait();
        $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA, 'Accept' => 'application/json'])->timeout(60)->retry(2, 10000, throw: false)->get($url);
        if (! $r->successful()) {
            $this->error('HTTP '.$r->status().' '.$url);

            return [];
        }

        return $r->json() ?? [];
    }

    /** Download once; a file already on disk with the expected size is reused. */
    private function download(string $url, string $file, ?int $size): bool
    {
        if (is_file($file) && (! $size || filesize($file) === (int) $size)) {
            return true;
        }
        @mkdir(dirname($file), 0775, true);
        $this->wait();
        $r = Http::withHeaders(['User-Agent' => CongressHarvester::UA])->withOptions(['sink' => $file.'.part'])->timeout(3600)->get($url);
        if (! $r->successful() || ($size && filesize($file.'.part') !== (int) $size)) {
            $this->error('Download failed or incomplete: '.$url);
            @unlink($file.'.part');

            return false;
        }
        rename($file.'.part', $file);

        return true;
    }
}
