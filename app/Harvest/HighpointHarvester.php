<?php

namespace App\Harvest;

use App\Actions\Record\ImportPublishedHorse;
use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\Horse;
use App\Models\ShowRecord;
use App\Support\Record;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Highpoint Performance (highpointperformance.com) stallion pages: foal year, color, sire and dam in text, and a
 * 2-generation pedigree image whose grandparents are read with tesseract. No terms of use (Oct 17 2026; footer
 * "Copyright 2018 – All Rights Reserved"); robots.txt allows all. One request every 5 seconds, each page and image
 * read once and kept as evidence. Stud fees, contracts, owners and contact details are never stored.
 */
class HighpointHarvester
{
    public const LIST = 'https://highpointperformance.com/highpoint-performance-stallions/';

    private DataSource $source;
    private float $lastRequest = 0;

    public function __construct(private float $delay = 5.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'highpoint'], [
            'name' => 'Highpoint Performance stallions', 'url' => self::LIST, 'permission' => 'no_terms_posted',
            'terms_note' => 'Oct 17 2026: no terms of use (footer "Copyright 2018 - All Rights Reserved"); robots.txt allows all agents; reCAPTCHA only on the contact form. Richard approved collecting the stallions and their pedigrees.',
        ]);
    }

    public function run(): array
    {
        $out = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_owned' => 0, 'skipped_ambiguous' => 0, 'no_chart' => 0];
        $list = $this->fetch(self::LIST)?->body() ?? '';
        $main = substr($list, max(0, (int) strpos($list, '<main')));
        preg_match_all('#href="(https://highpointperformance\.com/portfolio-items/[^"/?]+/)(?:\?[^"]*)?"#', $main, $m);
        foreach (array_unique($m[1]) as $url) {
            $page = HarvestPage::firstOrNew(['url' => $url], ['data_source_id' => $this->source->id, 'kind' => 'stallion']);
            if (in_array($page->status, ['parsed', 'skipped'], true)) {
                continue;
            }
            $html = $this->stored($page) ?? $this->download($page, 'html');
            if ($html === null) {
                continue;
            }
            $h = self::page($html);
            if (! $h) {
                $page->fill(['status' => 'skipped', 'error' => 'No sire and dam on the page'])->save();
                continue;
            }
            if ($h['chart_url']) {
                $img = HarvestPage::firstOrNew(['url' => $h['chart_url']], ['data_source_id' => $this->source->id, 'kind' => 'pedigree_image']);
                $png = $this->stored($img) ?? $this->download($img, 'png');
                $grand = $png !== null ? self::chart(self::ocr($png)) : [];
                if (count($grand) === 4) {
                    $h['pedigree'] += array_map([self::class, 'spelling'], $grand);
                    $img->fill(['status' => 'parsed', 'rows' => 4])->save();
                } else {
                    $out['no_chart']++;
                    $img->exists && $img->fill(['status' => 'skipped', 'error' => 'Chart not read'])->save();
                }
            }
            unset($h['chart_url']);
            $out[app(ImportPublishedHorse::class)($h, $url)]++;
            $page->fill(['status' => 'parsed', 'rows' => count($h['pedigree']), 'year' => $h['foal_year']])->save();
            $out['pages']++;
        }

        return $out;
    }

    /** Name, "2016 Sorrel Stallion", "Sire: X", "Dam: Y" and the pedigree image. */
    public static function page(string $html): ?array
    {
        $chart = preg_match('#<img[^>]+title="[^"]*Pedigree"[^>]+(?:data-orig-src|src)="(https://[^"]+\.(?:png|jpe?g))"#i', $html, $c) ? $c[1] : null;
        $body = preg_replace('#<(script|style)\b.*?</\1>#is', '', substr($html, max(0, (int) strpos($html, '<main'))));
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h\d|/li)[^>]*>#i', "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l)), explode("\n", $text))));
        $sire = $dam = $name = null;
        $year = $color = $registry = null;
        foreach ($lines as $i => $l) {
            if (! $sire && preg_match('/^Sire:\s*(.+)$/i', $l, $m)) {
                $sire = $m[1];
            } elseif (! $dam && preg_match('/^Dam:\s*(.+)$/i', $l, $m)) {
                $dam = $m[1];
            } elseif (! $year && preg_match('/^(\d{4})\s+(.*?)\s*(?:stallion)?$/i', $l, $m) && $i > 0) {
                $year = (int) $m[1];
                $name = $lines[$i - 1];
                $rest = $m[2];
                $registry = preg_match('/\bAQHA\b|Quarter Horse/i', $rest) ? 'AQHA' : null;
                $rest = trim(preg_replace('/\bAQHA\b|Quarter Horse/i', '', $rest));
                foreach (collect(Record::COLORS)->sortByDesc(fn ($c) => strlen($c)) as $col) {
                    if ($rest !== '' && stripos($rest, $col) === 0) {
                        $color = $col;
                        break;
                    }
                }
            }
        }
        if (! $name || ! $sire || ! $dam) {
            return null;
        }

        return [
            'name' => NrchaHarvester::display($name), 'foal_year' => $year, 'registry' => $registry, 'color' => $color, 'sex' => 'stallion',
            'birth_date' => null, 'registration_number' => null, 'results' => [], 'chart_url' => $chart,
            'pedigree' => ['s' => NrchaHarvester::display($sire), 'd' => NrchaHarvester::display($dam)],
        ];
    }

    /**
     * Tesseract TSV of a two-column chart: parents on the left, four grandparents on the right, connector lines in
     * between. Returns ss, sd, ds, dd (top to bottom) or [] if it doesn't read as exactly four names.
     */
    public static function chart(string $tsv): array
    {
        $words = [];
        $width = 0;
        foreach (preg_split('/\R/', trim($tsv)) as $row) {
            $c = explode("\t", $row);
            if (count($c) < 12 || ! is_numeric($c[0])) {
                continue;
            }
            if ((int) $c[0] === 1) {
                $width = (int) $c[8];
            }
            if ((int) $c[0] === 5 && (float) $c[10] > 0 && preg_match("/^[A-Za-z0-9][A-Za-z0-9'.&-]*$/", $c[11])) {
                $words[] = ['left' => (int) $c[6], 'top' => (int) $c[7], 'text' => $c[11]];
            }
        }
        if (! $width) {
            return [];
        }
        $right = array_filter($words, fn ($w) => $w['left'] > $width * 0.55);
        usort($right, fn ($a, $b) => [$a['top'], $a['left']] <=> [$b['top'], $b['left']]);
        $lines = [];
        foreach ($right as $w) {
            $k = array_key_last($lines);
            if ($k !== null && abs($lines[$k]['top'] - $w['top']) <= 8) {
                $lines[$k]['words'][] = $w['text'];
            } else {
                $lines[] = ['top' => $w['top'], 'words' => [$w['text']]];
            }
        }
        $names = array_map(fn ($l) => implode(' ', $l['words']), $lines);

        return count($names) === 4 ? array_combine(['ss', 'sd', 'ds', 'dd'], $names) : [];
    }

    /** Use the spelling Horsefax already has for the same name (OCR loses spaces: "NMA" for "NM A"). */
    public static function spelling(string $ocr): string
    {
        $key = ShowRecord::key($ocr);
        $known = Horse::whereRaw("regexp_replace(upper(registered_name), '[^A-Z0-9]', '', 'g') = ?", [$key])->value('registered_name')
            ?? ShowRecord::where('name_key', $key)->value('display_name');

        return $known ?? NrchaHarvester::display($ocr);
    }

    public static function ocr(string $png): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hfocr');
        file_put_contents($tmp, $png);
        try {
            return Process::timeout(60)->run(['tesseract', $tmp, '-', 'tsv'])->output();
        } finally {
            @unlink($tmp);
        }
    }

    private function stored(HarvestPage $page): ?string
    {
        return $page->path && Storage::disk('local')->exists($page->path) ? gzdecode(Storage::disk('local')->get($page->path)) : null;
    }

    private function download(HarvestPage $page, string $ext): ?string
    {
        $r = $this->fetch($page->url);
        $page->fill(['http_status' => $r?->status(), 'fetched_at' => now()]);
        if (! $r || ! $r->successful()) {
            $page->fill(['status' => $r?->status() === 404 ? 'skipped' : 'failed', 'error' => $r ? 'HTTP '.$r->status() : 'Request failed'])->save();

            return null;
        }
        $file = 'harvest/highpoint/'.sha1($page->url).'.'.$ext.'.gz';
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
