<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\SaleLot;

/**
 * Horse sales run on Live Ag (bid.live-ag.com): the Congress Super Sale, Cowgirl Cadillacs, Women of the Wild West,
 * Western Wyoming Select Gelding Sale and others. Approved by Richard, Oct 17 2026. robots.txt disallows only /admin/
 * and /account/; the site terms are a transaction agreement with no scraping or database clause; no bot wall.
 *
 * sitemap-events.xml -> each finished horse sale's event page (lot list with status) -> each lot page (identity:
 * reg #, birth date, sire, dam, dam's sire). Stores the horse and what the sale reported; never buyer, bidder,
 * consignor, seller or agent names (the page data carries some of those: they stay in the private evidence copy only).
 */
class LiveAgHarvester
{
    use PoliteFetch;

    public const SITEMAP = 'https://bid.live-ag.com/sitemap-events.xml';

    private DataSource $source;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'liveag'], [
            'name' => 'Live Ag (horse sales on bid.live-ag.com)', 'url' => 'https://bid.live-ag.com', 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 17 2026: robots.txt disallows only /admin/ and /account/; the Live Ag terms (LiveAgTandC.docx) are a short transaction agreement with no scraping, database or data-ownership clause; no bot protection. Richard approved collecting sale results (Oct 17 2026).',
        ]);
    }

    /** @return list<array{id:int, slug:string, url:string}> horse sales in the sitemap, newest first */
    public function events(): array
    {
        $r = $this->fetch(self::SITEMAP);
        $events = $r?->successful() ? LiveAgParser::sitemapEvents($r->body()) : [];
        $events = array_values(array_filter($events, fn ($e) => LiveAgParser::isHorseSale(str_replace('-', ' ', $e['slug']))));
        usort($events, fn ($a, $b) => $b['id'] <=> $a['id']);

        return $events;
    }

    /** Returns [sales read, lots stored]. */
    public function run(?array $eventIds = null, ?int $maxEvents = null): array
    {
        $events = $this->events();
        if ($eventIds) {
            $events = array_values(array_filter($events, fn ($e) => in_array($e['id'], $eventIds, true)));
        }
        $sales = 0;
        $lots = 0;
        foreach ($events as $e) {
            if ($maxEvents !== null && $sales >= $maxEvents) {
                break;
            }
            $done = HarvestPage::where('url', $e['url'])->value('status');
            if (in_array($done, ['parsed', 'skipped'], true)) {
                continue;
            }
            $n = $this->event($e);
            if ($n !== null) {
                $sales++;
                $lots += $n;
            }
        }

        return [$sales, $lots];
    }

    /** One sale. Null when it isn't finished yet (read again on a later run). */
    public function event(array $e): ?int
    {
        // An unfinished sale's page changes; read it fresh and keep it only once the sale is over.
        $r = $this->fetch($e['url']);
        $props = $r?->successful() ? LiveAgParser::pageData($r->body()) : null;
        if (! $props) {
            return null;
        }
        $event = LiveAgParser::event($props);
        if (! $event['finished']) {
            $this->say('not finished yet: '.$event['title']);

            return null;
        }
        $page = HarvestPage::firstOrNew(['url' => $e['url']], ['data_source_id' => $this->source->id, 'kind' => 'index']);
        $year = $event['start'] ? (int) substr($event['start'], 0, 4) : null;
        $file = 'harvest/liveag/'.($year ?? 'index').'/'.sha1($e['url']).'.html.gz';
        \Illuminate\Support\Facades\Storage::disk('local')->put($file, gzencode($r->body(), 9));
        $page->fill(['year' => $year, 'path' => $file, 'sha256' => hash('sha256', $r->body()), 'status' => 'fetched', 'http_status' => 200, 'fetched_at' => now()])->save();

        $stored = 0;
        $missing = 0;
        foreach ($event['lots'] as $lot) {
            $html = $this->page($lot['url'], 'lot', $year);
            $lp = $html ? LiveAgParser::pageData($html) : null;
            if (! $lp) {
                $missing++;
                continue;
            }
            $id = LiveAgParser::identity($lp);
            $name = NrchaHarvester::display($id['name'] ?? $lot['title']);
            if (! $name) {
                continue;
            }
            [$foalYear, $birthDate] = LiveAgParser::birth($id['year'] ?? null);
            $result = LiveAgParser::result($lp);
            $registry = LiveAgParser::registry($id['breed'] ?? null);
            SaleLot::record($this->source->id, (string) $lot['id'], [
                'harvest_page_id' => HarvestPage::where('url', $lot['url'])->value('id'),
                'sale_name' => $event['title'], 'sale_date' => LiveAgParser::saleDate($lot['end_time'], $event['start']),
                'lot' => $id['hip'] ?? $lot['lot'], 'horse_name' => $name,
                'registered' => isset($id['reg']) ? true : null, 'registry' => $registry,
                'registration_number' => isset($id['reg']) ? preg_replace('/\s+/', '', $id['reg']) : null,
                'foal_year' => $foalYear, 'birth_date' => $birthDate,
                'sex' => $id['sex'] ?? null, 'color' => $id['color'] ?? null, 'breed' => $id['breed'] ?? null,
                'sire_name' => NrchaHarvester::display($id['sire'] ?? null), 'dam_name' => NrchaHarvester::display($id['dam'] ?? null),
                'dam_sire_name' => NrchaHarvester::display($id['dam_sire'] ?? null),
                'status' => $result['status'], 'price_cents' => $result['price_cents'], 'currency' => $result['currency'],
                'note' => $result['note'], 'source_url' => $lot['url'],
            ]);
            $stored++;
        }
        // A lot page that couldn't be read leaves the sale open, so the next run tries again (kept pages aren't refetched).
        $page->fill(['status' => $missing ? 'fetched' : 'parsed', 'rows' => $stored, 'error' => $missing ? "$missing lot pages unread" : null])->save();
        $this->say($event['title'].": $stored lots".($missing ? ", $missing unread" : ''));

        return $stored;
    }
}
