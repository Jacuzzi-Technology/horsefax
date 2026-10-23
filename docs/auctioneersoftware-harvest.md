# AuctioneerSoftware horse auctions

Horse sales whose online bidding runs on AuctioneerSoftware (auctioneersoftware.com). Found Oct 22 2026 under
Richard's standing approval to find and collect allowed horse-data sites. One harvester covers every site; each is in
`AuctioneerSoftwareHarvester::SITES`.

| Key | Sale company | Notes |
|---|---|---|
| `bigsky` | Big Sky Horse Sales (MT) | Titles carry name, foal year, registry and sex ("NAME \| 2018 AQHA Mare \| …") |
| `montanaselect` | Montana Select Horse Sales | |
| `eugene` | Eugene Horse Auction (OR) | Ring sale; mostly grade horses and ponies |
| `waverly` | Waverly Sales Co. Midwest Horse Sale (IA) | Draft and light horses; "Winning Bidder" prices |
| `mthope` | Mt. Hope Auction, Mid-Ohio sales (OH) | Names only for most lots |
| `cavan` | Cavan Equestrian (Ireland) | Prices in EUR |
| `goresbridge` | Goresbridge Horse Sales (Ireland) | Prices in EUR |

Checked Oct 22 2026 for every site: the platform robots.txt allows everything but `/admin/`, `/api/` and `/asset/`; no
bot wall; neither the platform's nor the sale company's terms page has a scraping, crawling, database or reuse clause.
Each site's Privacy Policy page is checked as well: some sale companies post their Terms of Service there.

**Retired:** Premier Online Horse Sales (MM Auction Services, `premieronline`). Its Terms of Service, posted under
`/privacy`, restrict the site to personal noncommercial use and forbid copying or republishing its materials (found
Oct 23 2026, after it had been loaded). It is listed in `AuctioneerSoftwareHarvester::RETIRED`, is never collected
again, and `--purge=premieronline` removes its lots and the records only they created (records with other evidence
are kept).

## How it reads a site

1. `/sitemap/past-auctions` lists finished auctions; `/sitemap/auction/{id}` lists every lot in one.
2. The lot list is read from the server-rendered page, 125 lots at a time (`/auctions/{id}-{slug}?pageSize=125&page=N`).
   The JSON `/api/` that the page's script uses is disallowed by robots.txt and is never called.
3. One lot page per auction gives the sale day ("Start Time").
4. Auctions that aren't horse sales are skipped: equipment, tack, carriage, stallion-service and similar titles; on
   mixed sale barns (Waverly, Mt. Hope) a vaguely titled auction counts only when most of its lots describe horses.

Finished auctions are kept gzipped in `storage/app/private/auctioneersoftware/<site>/pages/` and never refetched, so a
rerun only reads new auctions. `--offline` re-reads the kept pages.

## Results (never upgraded)

- `sold`: bids were placed and the site shows its SOLD tag or the "Winning Bidder" label (the precedent Richard set for
  Platinum's winning bids, Oct 18 2026).
- `high_bid`: bids were placed but the sale didn't mark it sold, or "Reserve not met".
- `offered`: no bids recorded online (many of these sales sell in the ring, and the online page shows no ring result),
  or a SOLD tag with no bids (no price published). A price shown with no bids is a starting or buy-now price and is never
  kept. Nothing here is ever labelled "no sale".

## Facts only

Kept: name, foal year (or age at the sale, with the Jan 1 birthday rule), sex, color, breed/registry, sire, dam, lot,
sale, sale day, result and price, with a link to the lot. Never kept (`AuctioneerSoftwareParser::DROP`): consignor,
trainer, fitter, phone, email, location, bidder, the "Story" or description text, photos and video. Practice and
sponsor lots, tack, and mules and donkeys are skipped.

Lots are placed with `RegistrationOnlyMatch::place` (registration number, then Richard's registered-name rule through
`AdvertisedRegisteredName`).

```bash
php artisan hf:harvest-auctioneersoftware
```

```bash
php artisan hf:harvest-auctioneersoftware bigsky waverly
```

```bash
php artisan hf:harvest-auctioneersoftware --offline
```

```bash
php artisan hf:harvest-auctioneersoftware --purge=premieronline
```
