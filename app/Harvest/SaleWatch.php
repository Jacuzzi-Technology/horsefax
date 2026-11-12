<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleEvent;
use App\Models\SaleLot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The sale calendar (Richard, Oct 24 2026: "check the sale calendar and schedule precise checks rather than weekly").
 * Instead of re-reading every sale source each week, hf:sale-watch keeps a calendar of upcoming and recent sales
 * (sale_events) and runs a source's harvester only when one of its sales is due a check:
 *
 *  - before the sale, while its catalog hasn't been seen: from `lead` days ahead (60, 45, 30, 21, 14, 7, 3 days before);
 *    once it has, one last look 2 days before for scratches and revisions;
 *  - after the sale: 1, 3, 7, 14 and 30 days after the last day where the source posts results, else once at 4 days
 *    (the lots turn from "catalog" to "offered").
 *
 * The dates come from what each source publishes ahead of a sale: Robin Glenn's catalogs carry their sale dates
 * (inventory.csv) weeks before the sale, Pitzer announces its next sales on its sales page, and Heritage, Topeka, Triangle
 * and Billings post catalogs whose lots load as "catalog" with the sale date. New sales still have to be found, so each
 * source also gets a slower discovery run (`discover` days). Online-auction sources (LiveAg, Platinum,
 * AuctioneerSoftware, Premier) post results the moment a sale closes and stay on their own sweeps.
 */
class SaleWatch
{
    /**
     * Each source: harvest commands (run in order), whether results are posted after the sale, days between discovery
     * runs, catalog lead time (0: the source has no catalog of its own).
     */
    public const SOURCES = [
        'robinglenn' => ['commands' => [['hf:harvest-robinglenn', '--delay=3']], 'results' => true, 'discover' => 7, 'lead' => 30],
        'pitzer' => ['commands' => [['hf:harvest-pitzer', '--delay=3']], 'results' => false, 'discover' => 14, 'lead' => 90],
        'heritage' => ['commands' => [['hf:harvest-heritage', '--no-archive', '--delay=3']], 'results' => true, 'discover' => 14, 'lead' => 45],
        'topeka' => ['commands' => [['hf:harvest-topeka', '--delay=3']], 'results' => true, 'discover' => 14, 'lead' => 45],
        'triangle' => ['commands' => [['hf:harvest-triangle', '--delay=3']], 'results' => false, 'discover' => 30, 'lead' => 45],
        'billings' => ['commands' => [['hf:harvest-billings', '--no-archive', '--delay=3']], 'results' => true, 'discover' => 14, 'lead' => 45],
        // Legacy Reining: results pages only (its catalog is a Robin Glenn catalog, checked under robinglenn).
        'legacysale' => ['commands' => [['hf:harvest-legacysale', '--delay=3']], 'results' => true, 'discover' => 30, 'lead' => 0],
        // Waverly Midwest Horse Sale: results on waverlysales.bid first, then the catalog, which pairs its lots with them.
        'waverly' => ['commands' => [['hf:harvest-auctioneersoftware', 'waverly', '--delay=3'], ['hf:harvest-waverly', '--delay=3']], 'results' => true, 'discover' => 14, 'lead' => 45],
    ];

    public const BEFORE = [90, 75, 60, 45, 30, 21, 14, 7, 3];
    public const AFTER_WITH_RESULTS = [1, 3, 7, 14, 30];
    public const AFTER_NO_RESULTS = [4];
    /** Sales that ended longer ago than this are not added to the calendar. */
    public const LOOKBACK_DAYS = 35;

    private string $state;
    private string $data;

    /**
     * @param \Closure|null $runner fn (array $argv): int, for tests; default runs `php artisan …`
     * @param string|null $data the harvesters' folder (storage/app/private): robinglenn/inventory.csv and each calendar.json
     */
    public function __construct(private ?\Closure $log = null, private ?\Closure $runner = null, ?string $state = null, ?string $data = null)
    {
        $this->data = $data ?? storage_path('app/private');
        $this->state = $state ?? $this->data.'/sale-watch.json';
    }

    /** Bring the calendar up to date from what every source has published, and set each sale's next check. */
    public function sync(): array
    {
        $out = [];
        foreach (array_keys(self::SOURCES) as $source) {
            $events = match ($source) {
                'robinglenn' => $this->robinGlennSales(),
                'pitzer' => $this->pitzerSales(),
                'legacysale' => $this->legacySales(),
                'waverly' => $this->waverlySales(),
                default => $this->catalogSales($source),
            };
            foreach ($events as $e) {
                $event = SaleEvent::firstOrNew(['source' => $source, 'key' => $e['key']]);
                $event->fill(['name' => mb_substr($e['name'], 0, 255), 'starts_on' => $e['from'], 'ends_on' => $e['to'], 'date_from' => mb_substr($e['date_from'], 0, 255)]);
                if (($e['catalog'] ?? false) && ! $event->catalog_seen_at) {
                    $event->catalog_seen_at = now();
                }
                if (($e['results'] ?? false) && ! $event->results_seen_at) {
                    $event->results_seen_at = now();
                }
                $event->save();
            }
            $out[$source] = count($events);
        }
        foreach (SaleEvent::all() as $event) {
            $next = self::nextCheck($event, self::SOURCES[$event->source] ?? null, now());
            if ($event->next_check_at?->toDateString() !== $next?->toDateString()) {
                $event->forceFill(['next_check_at' => $next])->save();
            }
        }

        return $out;
    }

    /** The first check date still ahead for a sale (today counts), or null when it needs no more checks. */
    public static function nextCheck(SaleEvent $e, ?array $cfg, Carbon $now): ?Carbon
    {
        if (! $cfg) {
            return null;
        }
        $start = $e->starts_on->copy()->startOfDay();
        $end = $e->lastDay()->copy()->startOfDay();
        $dates = [];
        if ($e->catalog_seen_at) {
            $dates[] = $start->copy()->subDays(2);
        } else {
            foreach (self::BEFORE as $d) {
                if ($d <= $cfg['lead']) {
                    $dates[] = $start->copy()->subDays($d);
                }
            }
        }
        foreach ($cfg['results'] ? self::AFTER_WITH_RESULTS : self::AFTER_NO_RESULTS as $d) {
            $dates[] = $end->copy()->addDays($d);
        }
        sort($dates);
        $today = $now->copy()->startOfDay();
        $checked = $e->last_checked_at?->copy()->startOfDay();
        foreach ($dates as $d) {
            if ($d->gte($today) && (! $checked || $d->gt($checked))) {
                return $d;
            }
        }

        return null;
    }

    /**
     * What is due now: per source, the sales due a check and whether a discovery run is due.
     *
     * @return array<string, array{events: list<SaleEvent>, discover: bool}>
     */
    public function due(): array
    {
        $last = $this->lastDiscovery();
        $due = [];
        foreach (self::SOURCES as $source => $cfg) {
            $events = SaleEvent::where('source', $source)->whereNotNull('next_check_at')->where('next_check_at', '<=', now())->orderBy('starts_on')->get()->all();
            $discover = ! isset($last[$source]) || Carbon::parse($last[$source])->addDays($cfg['discover'])->subHours(6)->lte(now());
            if ($events || $discover) {
                $due[$source] = ['events' => $events, 'discover' => $discover];
            }
        }

        return $due;
    }

    /** Run what is due, one source at a time, then refresh the calendar. Returns per-source exit codes. */
    public function run(bool $dryRun = false, ?string $only = null): array
    {
        $this->sync();
        $out = [];
        foreach ($this->due() as $source => $d) {
            if ($only && $source !== $only) {
                continue;
            }
            $commands = self::SOURCES[$source]['commands'];
            if ($source === 'robinglenn' && $d['events']) {
                $commands[0][] = '--cats='.implode(',', array_map(fn ($e) => $e->key, $d['events']));
            }
            $this->say("$source: ".($d['discover'] ? 'discovery' : '').($d['discover'] && $d['events'] ? ' + ' : '')
                .($d['events'] ? count($d['events']).' sale(s) due: '.implode('; ', array_map(fn ($e) => $e->name.' ('.$e->starts_on->toDateString().')', $d['events'])) : '')
                .' -> '.implode(' ; ', array_map(fn ($argv) => implode(' ', $argv), $commands)));
            if ($dryRun) {
                $out[$source] = null;
                continue;
            }
            $code = 0;
            foreach ($commands as $argv) {
                $code = max($code, $this->runner ? ($this->runner)($argv) : $this->artisan($argv));
            }
            $out[$source] = $code;
            foreach ($d['events'] as $e) {
                $e->forceFill(['last_checked_at' => now()])->save();
            }
            if ($d['discover']) {
                $this->markDiscovered($source);
            }
            $this->sync();
        }

        return $out;
    }

    private function artisan(array $argv): int
    {
        $r = Process::path(base_path())->forever()->run(['php', '-d', 'memory_limit=2G', 'artisan', ...$argv], function ($type, $chunk) {
            foreach (preg_split('/\R/', rtrim($chunk)) as $line) {
                $this->say('  '.$line);
            }
        });

        return $r->exitCode() ?? 1;
    }

    /** Robin Glenn: every sale catalog (not tests or private pedigree orders) with a date, from inventory.csv. */
    private function robinGlennSales(): array
    {
        $path = $this->data.'/robinglenn/inventory.csv';
        if (! is_file($path)) {
            return [];
        }
        $src = DataSource::where('key', 'robinglenn')->value('id');
        $since = now()->subDays(self::LOOKBACK_DAYS)->toDateString();
        $f = fopen($path, 'r');
        $head = fgetcsv($f);
        $out = [];
        while (($row = fgetcsv($f)) !== false) {
            $r = array_combine($head, array_pad($row, count($head), null));
            if (! $r['date_from'] || RobinGlennHarvester::kind($r['sale_name']) !== 'sale' || preg_match('/private orders?|^deleted$|non[- ]?sale/i', (string) $r['sale_name'])) {
                continue;
            }
            $to = $r['date_to'] && $r['date_to'] >= $r['date_from'] ? $r['date_to'] : $r['date_from']; // some catalogs end before they start
            // A span of weeks or years is a standing list (a ranch's horses for sale, "2021-01-01 – 2031-01-01"), not a sale date.
            if ($to < $since || Carbon::parse($r['date_from'])->diffInDays(Carbon::parse($to)) > 14) {
                continue;
            }
            $results = (int) $r['with_results'] > 0 || (int) $r['with_price'] > 0
                || ($src && SaleLot::where('data_source_id', $src)->where('external_id', 'like', $r['cat_id'].':%')->whereIn('status', ['sold', 'high_bid', 'no_sale'])->exists());
            $out[] = ['key' => $r['cat_id'], 'name' => $r['sale_name'] ?: 'Robin Glenn catalog '.$r['cat_id'], 'from' => $r['date_from'], 'to' => $to,
                'date_from' => RobinGlennHarvester::BASE.$r['cat_id'], 'catalog' => $r['state'] === 'catalog' && (int) $r['lots'] > 0, 'results' => $results];
        }
        fclose($f);

        return $out;
    }

    /** Pitzer: the dates its sales page announces (kept in calendar.json by PitzerHarvester::discover). */
    private function pitzerSales(): array
    {
        $h = new PitzerHarvester(3.0, null, $this->data.'/pitzer');
        $src = DataSource::where('key', 'pitzer')->value('id');
        $out = [];
        foreach ($h->calendar()['dates'] as $key => $d) {
            if ($d['to'] < now()->subDays(self::LOOKBACK_DAYS)->toDateString() || ! preg_match('/^(spring|fall)-(\d{4})$/', $key, $m)) {
                continue;
            }
            $catalog = ($src && SaleLot::where('data_source_id', $src)->where('external_id', 'like', "pitzer:$key:%")->exists())
                || PitzerHarvester::onRobinGlenn(ucfirst($m[1]), (int) $m[2]);
            $out[] = ['key' => $key, 'name' => 'Pitzer Ranch '.ucfirst($m[1]).' Sale '.$m[2], 'from' => $d['from'], 'to' => $d['to'],
                'date_from' => PitzerHarvester::BASE.PitzerHarvester::SALES_PAGE, 'catalog' => $catalog];
        }

        return $out;
    }

    /** Legacy Reining: the dates on its homepage (calendar.json, kept by LegacySaleHarvester::discover). */
    private function legacySales(): array
    {
        $src = DataSource::where('key', 'legacysale')->value('id');
        $out = [];
        foreach ((new LegacySaleHarvester(3.0, null, $this->data.'/legacysale'))->found()['dates'] as $year => $d) {
            if ($d['to'] < now()->subDays(self::LOOKBACK_DAYS)->toDateString()) {
                continue;
            }
            $out[] = ['key' => (string) $year, 'name' => LegacySaleHarvester::SALE.' '.$year, 'from' => $d['from'], 'to' => $d['to'], 'date_from' => LegacySaleHarvester::BASE.'/',
                'results' => $src && SaleLot::where('data_source_id', $src)->where('sale_year', $year)->whereIn('status', ['sold', 'no_sale'])->exists()];
        }

        return $out;
    }

    /** Waverly: the dates on its live site (calendar.json, kept by WaverlyHarvester::discover). */
    private function waverlySales(): array
    {
        $cat = DataSource::where('key', 'waverly')->value('id');
        $bid = DataSource::where('key', 'as-waverly')->value('id');
        $out = [];
        foreach ((new WaverlyHarvester(3.0, null, $this->data.'/waverly'))->calendar() as $key => $d) {
            if ($d['to'] < now()->subDays(self::LOOKBACK_DAYS)->toDateString() || ! preg_match('/^(spring|fall)-(\d{4})$/', $key, $m)) {
                continue;
            }
            $out[] = ['key' => $key, 'name' => 'Waverly Midwest Horse Sale, '.ucfirst($m[1]).' '.$m[2], 'from' => $d['from'], 'to' => $d['to'],
                'date_from' => WaverlyHarvester::BASE.WaverlyHarvester::INFO,
                'catalog' => $cat && SaleLot::where('data_source_id', $cat)->where('sale_year', (int) $m[2])
                    ->whereRaw($m[1] === 'fall' ? 'extract(month from sale_date) >= 7' : 'extract(month from sale_date) < 7')->exists(),
                'results' => $bid && SaleLot::where('data_source_id', $bid)->whereBetween('sale_date', [$d['from'], Carbon::parse($d['to'])->addDay()->toDateString()])
                    ->whereIn('status', ['sold', 'high_bid'])->exists()];
        }

        return $out;
    }

    /** Sources whose catalogs load before the sale: their sales from sale_lots (catalog lots carry the sale date). */
    private function catalogSales(string $source): array
    {
        $src = DataSource::where('key', $source)->value('id');
        if (! $src) {
            return [];
        }
        $rows = SaleLot::where('data_source_id', $src)->whereNotNull('sale_date')->where('sale_date', '>=', now()->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->selectRaw("sale_name, min(sale_date) as d, sum(case when status in ('sold','high_bid','no_sale') then 1 else 0 end) as results")
            ->groupBy('sale_name')->get();
        $out = [];
        foreach ($rows as $r) {
            $from = Carbon::parse($r->d)->toDateString();
            $out[] = ['key' => $from.'|'.Str::slug(Str::limit($r->sale_name, 90, '')), 'name' => $r->sale_name, 'from' => $from, 'to' => $from,
                'date_from' => 'sale_lots', 'catalog' => true, 'results' => (int) $r->results > 0];
        }

        return $out;
    }

    private function lastDiscovery(): array
    {
        return is_file($this->state) ? (json_decode(file_get_contents($this->state), true) ?: []) : [];
    }

    private function markDiscovered(string $source): void
    {
        $s = $this->lastDiscovery();
        $s[$source] = now()->toIso8601String();
        @mkdir(dirname($this->state), 0775, true);
        file_put_contents($this->state, json_encode($s, JSON_PRETTY_PRINT));
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
