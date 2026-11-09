# TheHorseBay online auctions

`thehorsebay.com`, an online horse auction (Zack and Kay Stevens, SW Missouri). It runs on the same WooCommerce
auction plugin as Platinum. Richard approved loading it on Nov 9 2026 ("Yes, load them"), after the Nov 8 research
pull.

## Where the data comes from

Horsefax never fetches the site. The research folder `Auction Info/TheHorseBay raw data/` (Dropbox, private) does the
slow pull and writes a facts-only CSV:

1. `python3 fetch.py sitemaps`, then `python3 fetch.py refresh 300`. One page at a time, 8–11 s apart.
2. `python3 parse.py` writes `lots.csv` and `bids.csv`.
3. `python3 export_horsefax.py` writes `horsefax-lots.csv`. It has no seller, "listed by", location, high bidder or
   bidder names.
4. Copy `horsefax-lots.csv` to `storage/app/private/thehorsebay/` (on the server too), then:

```bash
php artisan hf:import-thehorsebay --dry-run
```

```bash
php artisan hf:import-thehorsebay
```

A rerun updates the lots already stored, keyed by `thb:<slug>`, and leaves placed lots alone.

## Terms

Checked Nov 9 2026:
- robots.txt disallows only WooCommerce cart, account and search paths and wp-admin.
- The Terms and Conditions have no scraping, copying or commercial-use clause. They do bar collecting or using
  "information about users" without consent, so no person is ever stored.

## Rules (`app/Harvest/TheHorseBayImporter.php`)

- **Results** follow the Platinum precedent:
  - A winning bid is `sold`, labelled "winning bid as reported by TheHorseBay".
  - "Reserve not met" is `high_bid` with the top bid.
  - A lot with bids that the site didn't mark sold is also `high_bid`.
  - A lot with no bids is `offered`, with no price.
  - Lots still running are skipped.
- **Dates:** no end time is published. A lot with bids is dated by its last bid (day). A lot without bids is dated by
  the month its auction started.
- **Names:** the title's registered name, with barn names in quotes or brackets, "aka …" and a dash tail
  ("– AQHA Mare") dropped.
- **Registration:**
  - The number is the first run with a digit; commas are dropped, and so is a TWHBEA suffix like "/BL/TO".
  - Not taken as a number: a 15-digit microchip, a freezemark, or a bare year.
  - The registry is taken only when it is printed, or when the breed has one main registry (TWHBEA, MFTHBA, and the
    LiveAg mapping for Quarter Horse, Paint, Appaloosa and similar).
  - Crosses and Gypsy horses get no registry.
- **Skipped:** mules and donkeys, test lots, and listings of more than one horse.
- **Placement:** `RegistrationOnlyMatch::place`. It tries the registration number first, then Richard's
  registered-name rule (`AdvertisedRegisteredName`).
- **Kept:** name, registry and number, foal date, sex, color, breed, result, top bid and the lot link. Height,
  markings, location and the description aren't stored.

## Known case

Sterling's Danny Boy is double-registered (TWHBEA and SSHBEA numbers) and was listed twice. The two listings made two
records with the same foal date, which is left to the duplicate review.

Test: `tests/Feature/TheHorseBayTest.php`.
