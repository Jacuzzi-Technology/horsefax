# RanchWorldAds.com harvest

Collects every horse ad on RanchWorldAds.com, active and sold, back to about 2014, as **facts only**. Loads them
into `sale_lots` as advertised listings. Code: `app/Harvest/RanchWorldAdsHarvester.php` and `RanchWorldAdsParser.php`.
The command is `app/Console/Commands/HarvestRanchWorldAds.php`. The test is `tests/Feature/RanchWorldAdsTest.php`.

## Why it's allowed, and the limits (checked Oct 22 2026)

- No robots.txt, no sitemap, and no anti-scraping clause. Sold ads stay online; ad #44256 from 2014 still loads.
- The Site Rules ("USE OF PHOTOS") give RanchWorldAds an **exclusive, perpetual license to ad text and images**. So
  photos, video, the description text and phone numbers are never stored in Horsefax. Every record links back to
  the ad.
- No bot-protection bypass. A 401, 403 or 429, or a challenge or captcha page, stops the run with
  `RanchWorldAdsBlocked`. Don't work around it; ask RanchWorldAds to allow HorsefaxBot instead.
- Requests are one at a time, at least 3 s apart (the Horsefax house rule; the command won't go lower), as `HorsefaxBot/1.0 (+https://horsefax.com/contact)`. Other errors
  back off for 30 s, doubling each time, then give up on that page after 5 tries.

## What's kept

The raw file `ads.jsonl` keeps one line per ad with:

- listing id and URL, plus the index kind ("Horses For Sale" / "Horses For Bid at Auction")
- the title, and the horse's name when the title leads with one
- the SOLD flag, the auction flag, the price as shown (and in cents when it's a number), and the sale name
- city, state, foal year (with the text as shown), breed, sex, height, weight, color, and registry (normalized, plus as shown)
- registered yes/no ("GRADE" = no), disciplines, the poster's username, and a `horsebid` flag (poster contains "horsebid")
- the posted date, when the page shows one ("Posted 10-20-26"; older ads often don't)
- the **pedigree**: the ad's 3-generation chart by position (`s, ss, sss, ssd, sd, sds, sdd, d, ds, dss, dsd, dd, dds, ddd`).
  Sale-company ads with no chart put the sire line in the title ("NAME – 2023 AQHA Sorrel Gelding x SIRE x SIRE'S SIRE
  out of DAM x DAM'S SIRE"). Those four names are read from the title, and `pedigree_from` says which source was used.
- `registration_number`: the first standalone 7-digit number in the title or description, with all of them in
  `reg_numbers`. **Only the number is kept, never the text.** Phone numbers and prices don't match: the number can't
  be part of a longer digit run or have dashes or dots around it.
- first seen, last seen, the date we first saw it SOLD, fetched, and gone dates

Never kept in the raw file: description text, photos, video links, phone numbers, or the "visitors" count.

**HTML cache.** `html/<id/1000>/<id>.html.gz` and `html/index-N.html.gz` hold the raw pages. They let the parser
be rerun (`--reparse`) without fetching anything. **The cache contains RanchWorldAds' licensed ad text. It stays on
the machine that ran the harvest. It is never loaded, deployed, published, or copied to the server.** For the
Oct 2026 run it lives in `~/horsefax-harvest/ranchworldads` on Richard's Mac, outside Dropbox.

## How it finds the ads

`index.php?cat_id=1&pg=N&sort=date&sort_dir=d` pages through every horse ad, newest first. That's about 36 per page
and 912 pages on Oct 22 2026, sold ads included. The last page number is read from the pagination. A few featured
ads repeat at the top of every page; they're kept, marked `featured`.

Listing ids are shared by every category (cattle, spurs, hay …), and deleted ids answer with a tiny redirect to the
home page. `--probe=N` fetches N random ids in the index's id range that the index doesn't list. It counts how
many are horse ads, keeps those (`off_index: true`), and writes `probe-YYYY-MM-DD.json` with an estimate of how
many horse ads the index leaves out. A full sweep of every id (~172,000 requests, about 4 days) is only worth it if the
probe finds many.

## Running it

Run all commands in the app directory.

```bash
php artisan hf:harvest-ranchworldads --delay=3 --probe=500 --no-load --dir="$HOME/horsefax-harvest/ranchworldads"
```

By default a run reads the full index, fetches new ads, and re-checks ads that dropped out. It writes the CSV/JSON,
then loads into `sale_lots`. Options:

- `--max-pages=2 --max-ads=20`: a trial. A trial never marks ads gone.
- `--refresh-days=60`: refetches unsold live ads last read more than 60 days ago. An ad is also refetched as soon
  as the index shows its SOLD flag changed.
- `--probe=500`: samples ids the index doesn't list (see above).
- `--no-index`: skips the index, so there's nothing new to fetch. Use it only with `--probe`.
- `--no-load`: writes files only and doesn't touch the database.
- `--reparse`: re-reads the HTML cache with the current parser. No requests are made. Use it after a parser fix.
- `--load-only`: loads the raw file into `sale_lots`. No requests are made. Use it to load on another machine with just `ads.jsonl`.
- `--dir=/path`: where the files live. The default is `storage/app/private/ranchworldads`.

A first full run is about 33,000 ads at 3 s each, so about 28 hours plus about 50 min for the index. Index pages read in the last
12 hours are reused, so a stopped run restarts without rereading them. Reruns read the index (about 50 min) and then fetch only new or changed ads.

## Files (in `--dir`)

- `ads.jsonl`: the state, one JSON line per ad. Lines are appended as the run goes and compacted at the end, so a
  killed run resumes where it stopped.
- `ranchworldads-ads.json` and `.csv`: the current export of every horse ad on file (live and gone).
- `index-YYYY-MM-DD.json`: the index as read, with every row (id, kind, title, sold, price shown, page), the counts,
  and the id range.
- `probe-YYYY-MM-DD.json`: the off-index sample.
- `html/`: the local-only page cache (see above).

## In Horsefax

- Each horse ad becomes one `sale_lots` row with status `asking`, source `ranchworldads`, and `source_url` linking
  back to the ad. The sale date is the posted date when shown. Asking prices aren't published yet
  (`HORSEFAX_PUBLISHED_SALE_KINDS`).
  - **Auction ads** ("For Bid at Auction") store no price. Any figure in such an ad is written into the note only,
    since it may be a reserve or an estimate.
  - **SOLD** is recorded in the note ("ad marked SOLD"). A seller's SOLD flag is not a reported sale, so the status
    stays `asking`.
  - **The horse's name** is taken from the title. When the title has no name, the lot is `Unnamed`; the ad title,
    which is RanchWorldAds' text, never becomes a horse name.
  - The poster's username stays in the raw file only. `sale_lots` stores no seller names. HorseBid-posted ads say
    "HorseBid lot advertised on RanchWorldAds" in the note.
- **Matching and new records.** Richard decided on Oct 22 2026: "create new unverified horse listings anytime we
  have the registered names on the sites we're crawling."
  1. **Registration number.** When exactly one record holds the number (registry agreeing or blank), the lot is
     attached as `match_tier=registration, review_status=held`. When several records hold it, the lot is a `conflict`.
  2. **A registered name.** The ad must name a registry (or say registered) or give a sire and dam. It must not be
     listed as grade, and the name must have two or more words. One-word names like "Trigger" are barn names.
     Mules and donkeys are left out. Then:
     - **No record has that name**, or every one contradicts the ad (registration number, sire, dam, foal year): a
       **new record** is made (`source=published`, **Identity unverified**, no owner). It carries the ad's name, sex,
       color, breed, foal year, registration number and pedigree. The ad is tied to it (`match_tier=created`, held).
       This uses `CreateRecordsFromSaleLots::createForAdvertised`, the same record-making path auction catalogs use.
     - **One record agrees on name and parentage:** the match is **proposed** to staff. The lot is `queued` and a
       `lot_belongs` review suggestion is left. It is never attached automatically. The one exception: when that
       record was made from an earlier RanchWorldAds ad of the same horse, the new ad is linked and held.
     - **A record of that name exists, with nothing on it to compare:** the lot is left `queued` for staff and
       nothing is created beside it, so duplicates aren't made.
  3. Ads with no name in the title (`Unnamed`) or only a barn name stay as listings only.
- A rerun leaves lots that are already matched or in review alone, so it creates nothing twice.
- `hf:match-sales` and `hf:create-sale-records` skip `asking` lots. Records and matches from these ads come only
  from this loader.

## First full run (Oct 22–23 2026)

- Index: 912 pages, 31,906 horse ads, listing ids 44,256 to 216,467. Crawled at 3 s per request for about 26 hours. The
  run stopped once when PHP hit its 128 MB limit at ~16,000 ads; it resumed without refetching.
- On file: 31,909 ads (29,018 sold, 2,891 active, 15,469 for bid at auction). 12,603 have a horse name, 15,038 have sire
  and dam (11,827 from the chart, 3,537 from the title), 300 have a 7-digit registration number, and 1,217 were posted by HorseBid.
  6,588 ads (6,203 different names) qualify for a new record.
- Posted stamp: on 15,691 ads, 2014–2026. Newer ads usually have none.
- Probe: of 500 random ids not in the index, 294 were deleted, 205 were other categories and 1 was a horse ad. That's an
  estimated ~280 horse ads outside the index, so a full id sweep isn't worth it.
- One ad page failed after retries; there were no blocks.
