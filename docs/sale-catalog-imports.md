# Sale-catalog imports: Heritage Livestock and Robin Glenn QData

Two sources of sale catalogs, loaded into `sale_lots` as source records that link back to the catalog. Richard
decided Oct 22 2026: "Import everything from both."

How a lot is placed (`RegistrationOnlyMatch::place`):

1. **Registration number first.** The lot attaches to the one record holding that number. If the number is on more
   than one record, or something on the record contradicts the lot, it goes to staff as a conflict.
2. **Then a registered name.** Richard, Oct 22 2026: "create new unverified horse listings anytime we have the
   registered names on the sites we're crawling." This uses `AdvertisedRegisteredName`, the same rule RanchWorldAds and DreamHorse
   follows, and the record is made by `CreateRecordsFromSaleLots::createForAdvertised`. A registered name means a
   registry or sire + dam is given, the horse isn't grade, and the name has 2+ words. Then:
   - name + parentage agree with a record: proposed to staff, never attached;
   - a record of that name with nothing to compare: queued for staff;
   - no record of that name, or every one contradicts the lot: a new **Identity unverified** record;
   - a later lot of the same horse from the same source: linked to that record.
3. Anything else waits as an unmatched source record (`review_status = 'unmatched'`). That keeps the nightly
   `hf:match-sales` from trying its own name matches on these lots. A rerun tries these lots again.

Code: `app/Harvest/HeritageHarvester.php`, `HeritageParser.php`, `RobinGlennHarvester.php`, `RobinGlennParser.php`,
`RegistrationOnlyMatch.php`, `HarvestBlocked.php`; commands in
`app/Console/Commands/HarvestHeritage.php` and `HarvestRobinGlenn.php`; test `tests/Feature/SaleCatalogImportTest.php`.

## House rules

- One request at a time, HorsefaxBot user agent, at least 3 s apart (`--delay` below 3 is raised to 3; neither site sets a Crawl-delay for HorsefaxBot).
- Errors back off. A 403, a 429 or a real bot-check page stops the run with `HarvestBlocked`. Never work around it.
- Everything fetched is kept on disk, so re-parsing, exporting and loading never touch the sites again (`--offline`).
- Facts only: name, registration number, foal year/date, color, sex, pedigree, hip, sale, and the result and price.
  Never kept: catalog NOTES text, phone numbers, photos and videos.
- **Consignor and buyer names are never stored** (house rule, "Key" sheet of Data Sources.xlsx): not in the database
  and not in the CSV/JSON. Only yes/no flags are kept (`has_consignor`, `has_buyer`). The evidence copies (the
  Heritage PDFs, the gzipped Robin Glenn pages) still show them as the catalogs published them.

## Heritage Livestock

`heritagelivestock.com`. It's a Wix site. robots.txt allows everything, there are no terms of use, and there is no
bot wall.

- **Finding the PDFs:** the harvester reads `sitemap.xml` (sale and results pages, but not the tack store) plus the
  pages listed in `HeritageHarvester::PAGES`. It also asks the Internet Archive's CDX API for every file it has seen
  under `/_files/ugd/`. Each PDF is downloaded from Heritage's own server, falling back to the Archive copy only if
  the file is gone.
- **What counts as a catalog:** a PDF with fewer than 5 hips is skipped. That covers the consignment forms and the
  "Riders for Hire" list.
- **Parsing:** hips are read from word positions (`pdftotext -bbox`). The pedigree columns sit at fixed offsets from
  the block's left edge. Some 2022–2024 pages print each block twice, a few points apart; the shifted copy is dropped.
  A column is only used when it has exactly the expected number of names (2, 4 or 8).
- **Registry:** a 7-digit number with no suffix is AQHA. The catalogs mark other breeds: "(P)" is APHA and
  "(ApHC)" is ApHC.
- **Matching a catalog to its sale:** the harvester tries these in order:
  1. the sale day on the cover;
  2. the results-page sale whose top-seller hips and names agree with the catalog;
  3. the first sale after the catalog's newest "QData-CB m/yyyy" stamp.
  The `date_basis` column records which one was used.
- **Prices:** prices come from the results pages (`/2022-catalog-sales`, `/2023-sale-results`, `/2024-sale-results`,
  `/copy-of-2024-sale-results` (2025), and the 2026 sale pages). Those pages list top sellers only. A price attaches
  by hip unless the results page names a clearly different horse. Sales whose catalog is gone still load their
  published top sellers (hip, name and price).
- **Status:** `sold` for a published price. Otherwise `offered` once the sale is past, and `catalog` before it.

Files (in `storage/app/private/heritage/`): `pdf/` (catalog PDFs), `pages/` (gzipped HTML),
`pdf-index.json`, `heritage-lots.csv` / `.json` (one row per horse), `heritage-catalogs.json`.

```bash
php artisan hf:harvest-heritage
```

```bash
php artisan hf:harvest-heritage --offline
```

```bash
php artisan hf:harvest-heritage --no-load
```

Other options: `--no-archive` skips the CDX lookup, and `--seed=/folder` copies already-downloaded
`1b95cd_*.pdf` files into the cache first.

## Robin Glenn Pedigrees / QData online catalogs

The catalogs are at `catalogs.robinglenn.com/account/SalePreview?catID=N`. They open without the `uid` parameter.
There's no robots.txt (it returns 404), the catalog pages carry no terms, and there's no login. Robin Glenn is a
commercial pedigree business and these catalogs are its product, which was flagged to Richard before he decided.

- **Crawl:** reads every catID from 1 upward. It stops after 60 missing numbers in a row past the highest real one
  (`PROBE_GAP`). Each run re-probes past the end for new catalogs. Pages are kept in `html/<catID>.html.gz`, and the
  crawl state is in `crawl-state.json` (catalog / empty / missing / error). The per-horse pedigree pages
  (`RGPCatBTNew.aspx`) are not fetched.
- **Columns:** hip, horse, Robin Glenn's horse id (`hid`), foal date, color, sex, sire, dam, dam's sire, consignor (yes/no only),
  session, results, price and buyer (yes/no only). The notes, photo and video cells are not kept.
- **Results:** "Purchased" (with a price) becomes `sold`. "Repurchased" (bought back) becomes `high_bid` with a
  price, or `no_sale` without one. "Out" or "Withdrawn" becomes `withdrawn`. A lot with no result is `offered` once
  the sale is past, and `catalog` before it.
- **Kinds:** `sale`, `private_order` ("PO-Name-date" pedigree orders) and `test` (Robin Glenn's "TEST …" and
  "Example Sale" catalogs). Only `sale` catalogs are loaded. All of them are in the inventory.
- **Results text:** only the result is kept ("Purchased 5500"). Anything after it, such as "; buyer <name>", is
  dropped.
- **First full crawl (Oct 22 2026):** catIDs 1–3,138 in 2,515 requests, never blocked. That found 1,298 sale
  catalogs with 105,839 lots, plus 318 sale catalogs cleared after the sale. Every price on the site is in a test or
  example catalog, so real sales carry none.
- **No registration numbers:** the web view doesn't print them, so lots go straight to the registered-name rule
  (sire and dam are given, so most qualify).

Files (in `storage/app/private/robinglenn/`): `inventory.csv` (catID, sale company, sale name, location, dates,
contact, lots, withdrawn, with results/price/buyer/consignor, kind), `robinglenn-lots.csv` / `.jsonl` (no buyer or
consignor names), `totals.json`.

The default run crawls new catIDs, writes the files and loads:

```bash
php artisan hf:harvest-robinglenn
```

To write the files only, without touching the database:

```bash
php artisan hf:harvest-robinglenn --no-load
```

To re-parse and load from the kept pages:

```bash
php artisan hf:harvest-robinglenn --offline
```

To re-read recent catalogs for new results:

```bash
php artisan hf:harvest-robinglenn --refresh-from=2900
```

Other options: `--from`, `--to`, `--max-requests=` (a trial), `--delay=` and `--dir=`.

## Running on the server

The first full crawl ran from Richard's Mac, so the cache lives in the Dropbox copy of `storage/app/private/`. To load
production without re-crawling:

1. Deploy the code (Richard's approval needed). No migration is needed; it uses the existing `sale_lots`.
2. Copy the cache across:
   `rsync -a storage/app/private/{heritage,robinglenn} root@146.190.128.6:/var/www/horsefax/storage/app/private/`
   then `chown -R horsefax:horsefax` on the server.
3. As `horsefax`, run `php artisan hf:harvest-heritage --offline` and `php artisan hf:harvest-robinglenn --offline`.
4. The nightly `hf:match-sales` then puts reported prices on the matched records.

## Weekly rerun (set up Oct 24 2026)

Wednesdays at 05:00 UTC, in the `horsefax` user's crontab on trusted-core-01. It runs
`/usr/local/bin/horsefax-catalogs-weekly` (a copy is in `bin/catalogs-weekly`) under `flock /tmp/hf-catalogs.lock`
and the shared `hf-match` lock. Output goes to `storage/logs/harvest-catalogs.log`.

1. `hf:harvest-heritage --no-archive --delay=3`: new catalog and results PDFs from the sitemap.
2. `hf:harvest-robinglenn --delay=3 --refresh-from=<highest catalog catID − 300>`: new catIDs past the end, plus the
   last 300 re-read so results posted after a sale are picked up.

Both runs load `sale_lots`. The crontab from before the change is in /root/horsefax-crontab.bak-2026-10-24.
