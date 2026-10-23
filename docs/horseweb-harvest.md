# HorseWeb.com classified ads

HorseWeb.com (Texas; horse classifieds since 1995), found Oct 22 2026 while surveying EquineNow alternatives under
Richard's standing approval. robots.txt shuts out only named SEO bots (Ahrefs, Semrush and similar), the Terms and
Conditions have no scraping, crawling, database or reuse clause, and there is no bot wall.

- **Listing:** `/horses/N/`, 30 ads a page, newest first (about 1,470 pages, ~44,000 ads on Oct 22 2026). Pages past
  the last repeat the last page, so the run stops when a page brings no new ad. Each row gives the ad's name, breed,
  gender, age, color, state, asking price and status tag (Sold, Stud).
- **Ad pages** add Registry, Reg Number and Foal Date. They are fetched only for ads whose Name has two or more words
  (a one-word name can't be a registered name under Richard's rule), once each, and kept.
- **Loading:** `sale_lots` with status `asking` (an advertised price, never a sale, even on a "Sold" ad, which is
  noted). Stud ads (a breeding fee) and ads with no name are skipped, as are mules and donkeys. Placement: registration
  number first (`RegistrationOnlyMatch::apply`), then the registered-name rule (`AdvertisedRegisteredName`).
- **Facts only:** descriptions, seller details, phone numbers and photos are never stored. The kept HTML
  (`storage/app/private/horseweb/html`, local only) holds the seller's text and is never loaded.

State is `storage/app/private/horseweb/ads.jsonl` (one line per ad, latest wins), so a rerun only reads the listing
again and fetches ad pages it hasn't seen.

```bash
php artisan hf:harvest-horseweb
```

```bash
php artisan hf:harvest-horseweb --offline
```
