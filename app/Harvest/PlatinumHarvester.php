<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\HarvestPage;
use App\Models\SaleLot;
use Illuminate\Support\Facades\Storage;

/**
 * Platinum Equine Auction (platinumequineauction.com) expired online auctions. Approved by Richard, Oct 17 2026:
 * robots.txt disallows only WooCommerce cart/log paths and wp-admin; no website terms posted; served through
 * Cloudflare but with no challenge. Reads the listing pages only (20 auctions a page), newest first.
 *
 * Platinum shows "Winning Bid" or "Reserve price Not met!", never "sold", so under Kate's rule a winning bid is a
 * recorded high bid, not a reported sale. Listing titles are often barn names and there's no pedigree, so these lots
 * only ever reach a record through staff review. Seller name, phone and location are on the cards; not stored.
 */
class PlatinumHarvester
{
    use PoliteFetch;

    public const BASE = 'https://platinumequineauction.com/expired-auctions/';

    private DataSource $source;

    public function __construct(private float $delay = 3.0, private ?\Closure $log = null)
    {
        $this->source = DataSource::firstOrCreate(['key' => 'platinum'], [
            'name' => 'Platinum Equine Auction', 'url' => self::BASE, 'permission' => 'no_terms_posted',
            'terms_note' => 'Checked Oct 17 2026: robots.txt disallows only WooCommerce cart/log paths and wp-admin; no website terms page; Cloudflare-served with no challenge. Richard approved collecting sale results (Oct 17 2026).',
        ]);
    }

    /**
     * Listing pages change as auctions end, so they're read fresh. Stops after a page with nothing new unless $all.
     * Returns [pages read, lots stored or updated].
     */
    public function run(bool $all = false, ?int $maxPages = null): array
    {
        $pages = 0;
        $stored = 0;
        $last = null;
        for ($p = 1; $last === null || $p <= $last; $p++) {
            if ($maxPages !== null && $pages >= $maxPages) {
                break;
            }
            $url = $p === 1 ? self::BASE : self::BASE.'page/'.$p.'/';
            $r = $this->fetch($url);
            if (! $r?->successful()) {
                break;
            }
            $html = $r->body();
            $last ??= PlatinumParser::lastPage($html);
            $pages++;
            // Evidence: each read of a listing page is kept under its own date.
            $key = $url.'#read-'.now()->format('Y-m-d');
            $file = 'harvest/platinum/'.now()->format('Y').'/'.sha1($key).'.html.gz';
            Storage::disk('local')->put($file, gzencode($html, 9));
            $page = HarvestPage::updateOrCreate(['url' => $key], [
                'data_source_id' => $this->source->id, 'kind' => 'index', 'year' => (int) now()->format('Y'), 'path' => $file,
                'sha256' => hash('sha256', $html), 'status' => 'parsed', 'http_status' => 200, 'fetched_at' => now(),
            ]);
            $new = 0;
            foreach (PlatinumParser::cards($html) as $c) {
                if ($c['status'] === 'catalog' || ! $c['name']) {
                    continue; // still running, or more than one horse in the listing
                }
                $lot = SaleLot::where('data_source_id', $this->source->id)->where('external_id', $c['slug'])->first();
                if ($lot && $lot->status === $c['status'] && $lot->price_cents === $c['price_cents']) {
                    continue;
                }
                SaleLot::record($this->source->id, $c['slug'], [
                    'harvest_page_id' => $page->id, 'sale_name' => 'Platinum Equine Auction (online)', 'sale_date' => $c['ended'],
                    'horse_name' => NrchaHarvester::display($c['name']), 'registered' => $c['registered'],
                    'registry' => LiveAgParser::registry(preg_replace('/\s.*$/', '', (string) $c['breed'])),
                    'foal_year' => $c['foal_year'], 'sex' => $c['sex'], 'color' => $c['color'], 'breed' => $c['breed'],
                    'status' => $c['status'], 'price_cents' => $c['price_cents'],
                    'note' => $c['status'] === 'high_bid' ? 'Platinum reports a winning bid; it does not say the sale completed' : ($c['status'] === 'no_sale' ? 'Reserve not met' : null),
                    'source_url' => $c['url'],
                ]);
                $new++;
            }
            $page->update(['rows' => $new]);
            $stored += $new;
            if (! $all && $new === 0 && $p > 1) {
                break; // caught up with what's already stored
            }
        }

        return [$pages, $stored];
    }
}
