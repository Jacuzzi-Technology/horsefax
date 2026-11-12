<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\SaleLot;

/**
 * Billings Livestock Commission horse sales (Richard, Oct 18 2026). robots.txt allows the sale pages and uploads
 * (Crawl-delay 3); no terms of use posted; no bot wall. Its bidding platform, Northern Livestock Video (ABC Live
 * Auction), is NOT used: ABC's buyer agreement forbids storing auction data. Richard knows the sale manager and will
 * ask about full results later.
 *
 * Reads every catalog and Top 20/40 list linked from the horse-sale pages, and earlier ones whose names the Internet
 * Archive (Wayback Machine) recorded, read from Billings' own server. Catalog hips become sale lots with registration number and pedigree ("offered" once
 * the sale is past); a Top list sets that hip's reported sale price. One lot per sale month and hip.
 */
class BillingsHarvester
{
    use PoliteFetch;

    public const BASE = 'https://www.billingslivestock.com';
    public const PAGES = ['/horse-sales/', '/horse-sales/sales/catalog/', '/horse-sales/sales/sale-horses/',
        '/horse-sales/sales/2023-sale-results/', '/horse-sales/sales/2024-sale-results/', '/horse-sales/sales/2025-sales-results/', '/horse-sales/sales/2026-sales-results/'];

    private DataSource $source;

    /** @var array<string,string> live URL => Internet Archive copy */
    private array $fallback = [];

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'billings'], [
            'name' => 'Billings Livestock Commission horse sales', 'url' => self::BASE.'/horse-sales/', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 18 2026: robots.txt allows the sale pages and uploads (Crawl-delay 3); no terms of use posted; no bot protection. Catalogs and Top 20/40 lists only; earlier copies from the Internet Archive. Northern Livestock Video results are not collected (ABC Live Auction buyer agreement bans storing auction data). Richard approved (Oct 18 2026).',
        ]);
    }

    /** @return array{catalogs:int, lists:int, lots:int, prices:int} */
    public function run(bool $archive = true, ?int $maxFiles = null): array
    {
        $files = $this->livePdfs();
        if ($archive) {
            $files += $this->archivedPdfs();
        }
        // Catalogs first, so a Top list finds its hips.
        uksort($files, fn ($a, $b) => (int) self::isTopList($a) <=> (int) self::isTopList($b));
        $out = ['catalogs' => 0, 'lists' => 0, 'lots' => 0, 'prices' => 0];
        $n = 0;
        foreach ($files as $name => $url) {
            if ($maxFiles !== null && $n >= $maxFiles) {
                break;
            }
            if (in_array(HarvestPage::where('url', $url)->value('status'), ['parsed', 'skipped'], true)) {
                continue;
            }
            $n++;
            $pdf = $this->page($url, 'class', null, 'pdf');
            if ($pdf === null && isset($this->fallback[$url]) && ! in_array(HarvestPage::where('url', $this->fallback[$url])->value('status'), ['parsed', 'skipped'], true)) {
                $url = $this->fallback[$url];
                $pdf = $this->page($url, 'class', null, 'pdf');
            }
            $page = $this->harvestPage($url);
            if ($pdf === null || ! $page) {
                continue;
            }
            $text = StatePdfHarvester::text($pdf);
            if (self::isTopList($name) || preg_match('/TOP\s+(20|40|25|30)\s+LIST/i', substr($text, 0, 600))) {
                $out['prices'] += $this->topList($page, PremierParser::words($pdf), $text);
                $out['lists']++;
            } else {
                $hips = BillingsParser::catalog(PremierParser::words($pdf));
                if (! $hips) {
                    $page->fill(['status' => 'skipped', 'error' => 'Not a catalog or Top list'])->save();
                    continue;
                }
                $out['lots'] += $this->catalog($page, $name, $text, $hips);
                $out['catalogs']++;
            }
        }
        // Catalogued hips of a sale that's over, with no published price: offered at auction.
        SaleLot::where('data_source_id', $this->source->id)->where('status', 'catalog')->where('sale_date', '<', now()->subDays(3)->toDateString())
            ->update(['status' => 'offered', 'note' => 'Offered at the sale; Billings publishes prices for its top sellers only']);

        return $out;
    }

    /**
     * A catalog PDF from Richard's own copies (Richard, Oct 27 2026: the 2013-2024 catalogs he kept in PDFNotes on his
     * iPad, copied to Dropbox/Horsefax/Sale Catalogs/DoNotEdit). Only the PDF's own text is read: PDFNotes keeps his
     * handwritten notes in separate files beside it, which are never opened. The file is read, never changed. Lots link
     * to Billings' horse-sale page (there is no public URL for a printed catalog); the harvest page is keyed by the
     * file's SHA-256, so a rerun or the same file on another machine is the same page. Each lot is then placed as the
     * other catalog imports do (RegistrationOnlyMatch::place: registration number, else the registered-name rule).
     *
     * @param  ?string  $month  "2013-02" when known; otherwise read from the cover
     * @return array{file:string, sale:?string, hips:int, registration_numbers:int, pedigrees:int, placed:array<string,int>}
     */
    public function importFile(string $path, ?string $month = null, bool $dryRun = false): array
    {
        $pdf = file_get_contents($path);
        $text = StatePdfHarvester::text($pdf);
        $hips = BillingsParser::catalog(PremierParser::words($pdf));
        $month ??= self::coverMonth($text);
        $day = $month && strlen($month) === 10 ? $month : null; // "2015-02-27": a part of a catalog whose cover has no date
        $name = $month ? strtolower(date('M', strtotime(substr($month, 0, 7).'-01'))).substr($month, 2, 2).'cat' : basename($path);
        [$date] = $day ? [$day] : self::saleDate($name, $text);
        $out = ['file' => basename($path), 'sale' => $date, 'hips' => count($hips),
            'registration_numbers' => count(array_filter($hips, fn ($h) => $h['reg'])), 'pedigrees' => count(array_filter($hips, fn ($h) => $h['pedigree'])), 'placed' => []];
        if ($dryRun || ! $hips || ! $date) {
            return $out;
        }
        $sha = hash('sha256', $pdf);
        $page = HarvestPage::firstOrNew(['url' => 'catalog-copy:billings:'.$sha], ['data_source_id' => $this->source->id, 'kind' => 'catalog_copy']);
        $file = 'harvest/billings/'.substr($date, 0, 4).'/'.$sha.'.pdf.gz';
        if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($file)) {
            \Illuminate\Support\Facades\Storage::disk('local')->put($file, gzencode($pdf, 9));
        }
        $page->fill(['path' => $file, 'sha256' => $sha, 'status' => 'fetched', 'fetched_at' => now()])->save();
        $this->catalog($page, $name, $text, $hips, self::BASE.'/horse-sales/', $day);
        $lots = SaleLot::where('data_source_id', $this->source->id)->where('harvest_page_id', $page->id);
        (clone $lots)->where('status', 'catalog')->where('sale_date', '<', now()->subDays(3)->toDateString())
            ->update(['status' => 'offered', 'note' => 'Offered at the sale; from Billings\' printed catalog, which gives no prices']);
        foreach ($lots->get() as $lot) {
            $r = RegistrationOnlyMatch::place($lot, 'Billings Livestock');
            $out['placed'][$r] = ($out['placed'][$r] ?? 0) + 1;
        }

        return $out;
    }

    /** "February 22-23-24" etc. near the front of a catalog -> "2013-02", with the first four-digit year there. */
    public static function coverMonth(string $text): ?string
    {
        $cover = substr($text, 0, 8000);
        if (! preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', $cover, $m)
            || ! preg_match('/\b(20[0-3]\d)\b/', $cover, $y)) {
            return null;
        }

        return $y[1].'-'.date('m', strtotime($m[1].' 1 2000'));
    }

    public static function isTopList(string $name): bool
    {
        return (bool) preg_match('/top[-_ ]?(20|25|30|40)/i', $name);
    }

    /** @return array<string,string> file name => URL, from the live horse-sale pages */
    private function livePdfs(): array
    {
        $files = [];
        foreach (self::PAGES as $p) {
            $body = $this->fetch(self::BASE.$p)?->body() ?? '';
            preg_match_all('#https?(?::|%3A)(?://|%2F%2F)www\.billingslivestock\.com(?:/|%2F)wp-content(?:/|%2F)uploads(?:/|%2F)[^"\'&\s<>]+?\.pdf#i', $body, $m);
            foreach ($m[0] as $u) {
                $u = urldecode($u);
                $files[basename($u)] = $u;
            }
        }

        return array_filter($files, fn ($u) => ! preg_match('/AQHA_|Certificate|Registration_Application|consign|form/i', basename($u)));
    }

    /** @return array<string,string> file name => Wayback raw-file URL, for catalogs and Top lists no longer linked */
    private function archivedPdfs(): array
    {
        $r = $this->fetch('https://web.archive.org/cdx/search/cdx?url=billingslivestock.com/wp-content/uploads/*&output=json&fl=original,timestamp&filter=mimetype:application/pdf&filter=statuscode:200&collapse=urlkey&limit=5000');
        $rows = $r?->successful() ? array_slice($r->json() ?? [], 1) : [];
        $files = [];
        foreach ($rows as [$original, $ts]) {
            $name = basename(parse_url($original, PHP_URL_PATH));
            if (preg_match('/cat|top[-_ ]?\d0|web/i', $name) && ! preg_match('/AQHA_|Certificate|Registration|consign|form/i', $name)) {
                // The Archive caps its copies at 1-5 MB, which cuts most catalogs short: it only tells us the file
                // names Billings published. Each is read from Billings' own server, the Archive copy only if it's gone.
                $files[$name] = self::BASE.'/wp-content/uploads/'.ltrim(preg_replace('#^.*?/wp-content/uploads/#i', '', $original), '/');
                $this->fallback[$files[$name]] = 'https://web.archive.org/web/'.$ts.'id_/'.preg_replace('#^http:#', 'https:', $original);
            }
        }

        return $files;
    }

    /** "oct26catweb.pdf", "febcat25web2.pdf", "APRIL-2026-CATALOG.pdf" + the cover text -> [Y-m-d sale day, precision]. */
    public static function saleDate(string $name, string $text): array
    {
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $month = $year = null;
        if (preg_match('/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*[-_ ]?(?:cat)?[-_ ]?(20)?(\d\d)/i', $name, $m)) {
            [$month, $year] = [$months[strtolower($m[1])], 2000 + (int) $m[3]];
        }
        $cover = substr($text, 0, 6000);
        if ($month && preg_match('/\b('.implode('|', array_map(fn ($k) => date('F', mktime(0, 0, 0, $months[$k], 1)), array_keys(array_filter($months, fn ($v) => $v === $month)))).')\s*(\d{1,2})\b/i', $cover, $d)) {
            return [sprintf('%04d-%02d-%02d', $year, $month, (int) $d[2]), 'day'];
        }

        return $month ? [sprintf('%04d-%02d-01', $year, $month), 'month'] : [null, 'month'];
    }

    private function catalog(HarvestPage $page, string $name, string $text, array $hips, ?string $sourceUrl = null, ?string $day = null): int
    {
        [$date, $precision] = $day ? [$day, 'day'] : self::saleDate($name, $text);
        if (! $date) {
            $page->fill(['status' => 'skipped', 'error' => 'Catalog month not found'])->save();

            return 0;
        }
        $saleName = 'Billings Livestock '.date('F Y', strtotime($date)).' Horse Sale';
        $n = 0;
        foreach ($hips as $h) {
            $lot = SaleLot::firstOrNew(['data_source_id' => $this->source->id, 'external_id' => 'bls:'.substr($date, 0, 7).':'.$h['hip']]);
            $keep = in_array($lot->status, ['sold'], true);
            $lot->fill(array_filter([
                'harvest_page_id' => $page->id, 'sale_name' => $saleName, 'sale_date' => $date, 'date_precision' => $precision,
                'sale_year' => (int) substr($date, 0, 4), 'lot' => $h['hip'], 'horse_name' => $h['name'],
                'name_key' => \App\Models\ShowRecord::key($h['name']), 'registered' => $h['reg'] ? true : null, 'registry' => $h['registry'],
                'registration_number' => $h['reg'], 'foal_year' => $h['foal_year'], 'sex' => $h['sex'], 'color' => $h['color'],
                'breed' => $h['registry'] === 'AQHA' ? 'Quarter Horse' : null,
                'sire_name' => $h['pedigree']['s'] ?? null, 'dam_name' => $h['pedigree']['d'] ?? null, 'dam_sire_name' => $h['pedigree']['ds'] ?? null,
                'pedigree' => $h['pedigree'] ?: null,
                // A Top-list lot keeps its link to the published list when a printed catalog fills in its details.
                'source_url' => $sourceUrl && $lot->exists ? $lot->source_url : ($sourceUrl ?? $page->url),
                'note' => $h['video'] ? mb_substr('Video: '.$h['video'], 0, 250) : null,
            ], fn ($v) => $v !== null));
            if (! $keep) {
                $lot->status = 'catalog';
            }
            $lot->save();
            $n++;
        }
        $page->fill(['status' => 'parsed', 'rows' => count($hips), 'year' => (int) substr($date, 0, 4)])->save();
        $this->say("$name: $saleName, ".count($hips).' hips');

        return $n;
    }

    private function topList(HarvestPage $page, array $words, string $text): int
    {
        $list = BillingsParser::topList($words, $text);
        if (! $list['date'] || ! $list['rows']) {
            $page->fill(['status' => 'skipped', 'error' => 'Top list not read'])->save();

            return 0;
        }
        $saleName = 'Billings Livestock '.date('F Y', strtotime($list['date'])).' Horse Sale';
        foreach ($list['rows'] as $r) {
            $lot = SaleLot::firstOrNew(['data_source_id' => $this->source->id, 'external_id' => 'bls:'.substr($list['date'], 0, 7).':'.$r['hip']]);
            if (! $lot->exists) {
                $lot->fill([
                    'sale_name' => $saleName, 'sale_date' => $list['date'], 'date_precision' => 'day', 'sale_year' => (int) substr($list['date'], 0, 4),
                    'lot' => $r['hip'], 'horse_name' => $r['name'], 'name_key' => \App\Models\ShowRecord::key($r['name']),
                    'registry' => $r['registry'], 'foal_year' => $r['foal_year'], 'sex' => $r['sex'], 'color' => $r['color'],
                    'sire_name' => $r['sire'], 'dam_sire_name' => $r['dam_sire'], 'source_url' => $page->url, 'harvest_page_id' => $page->id,
                ]);
            }
            $lot->fill(['status' => 'sold', 'price_cents' => $r['price_cents'], 'note' => 'Reported in the sale\'s published Top list: '.$page->url])->save();
        }
        $page->fill(['status' => 'parsed', 'rows' => count($list['rows']), 'year' => (int) substr($list['date'], 0, 4)])->save();
        $this->say(basename($page->url).': '.count($list['rows']).' prices, '.$saleName);

        return count($list['rows']);
    }
}
