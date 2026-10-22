# DreamHorse.com harvest

Collects every horse ad on DreamHorse.com as **facts only** and loads them into `sale_lots` as advertised asking
prices. Code: `app/Harvest/DreamHorseHarvester.php`, `DreamHorseParser.php`; commands in `routes/console.php`;
test `tests/Feature/DreamHorseTest.php`.

## Why it's allowed (checked Oct 22 2026)

- Dream Horse Classifieds LLC's terms take no license to seller content and have no anti-scraping clause.
- robots.txt allows `/ad/*.html` and the facet list pages. It **disallows `/show_list_pages.php`**, which is where
  the "next page" buttons post, so the harvester never pages through lists (see below).
- Photos and descriptions are still the sellers' copyright. Only facts are kept, and every record links back to the ad.
- No bot-protection bypass. A 403 or a challenge page stops the run with `DreamHorseBlocked`. Don't work around it;
  ask DreamHorse to allow HorsefaxBot instead.

## What's kept and what isn't

Kept in the raw file: ad id and URL; registered name and barn name (from the title "NAME (Barn)"); breed (or
breeds); registered yes/no; association; registration number; sex; color and markings; foal month and year;
height; weight; gaited; warmblood; for sale, lease and trade; asking price as shown; sold flag; city, state, zip and
country; the seller's display name; the ad's created, renewed and expiry dates; the pedigree chart by position
(s, d, ss ... up to 4 generations); first seen, last seen and gone dates.

Never read or kept: the description text, temperament score, skills/disciplines list, photos, videos, seller phone,
email and profile. No copy of the page itself is kept as evidence, because the page carries the description.

The seller's display name is kept in the raw file only. It never goes into `sale_lots`, which stores no consignor
names.

Expiry date: only photo ads print one ("Photos Expire 19-Apr-2027"). Free text ads print none, so it's blank for those.

## How it finds the ads

`sitemap.txt` lists only the facet pages (states, colors, breeds and so on), with no ads. Each list shows its first 40
ads and its total (`total_rows` in an HTML comment). A list over 40 is split into finer allowed facets:

state `/s/` → gender `/gs/` → color `/cgs/` → breed `/bcgs/<breed>/x/<color>/<gender>/horses-for-sale-in-<state>.html`

Colors and breeds whose national list fits on one page are skipped in the split, because all of their ads are
already known. The three "similar horses" links on every ad page add stragglers. Lists still over 40 at the breed
level are logged as `still over a page`, and the discovery file records the totals, so the run reports what it
couldn't reach.

## Running it

All commands run in the app directory. Use the horsefax user on the server.

```bash
php artisan hf:harvest-dreamhorse --delay=1
```

Defaults: list, fetch new ads, re-check gone ones, write the CSV/JSON, then load into `sale_lots`. Options:

- `--max-ads=50` stops after 50 ad fetches (a trial). A trial never marks ads gone.
- `--refresh-days=30` refetches live ads last read more than 30 days ago (to catch price changes and sold flags).
- `--no-discover` skips the lists. Use it with care: with no lists there's nothing new to fetch.
- `--no-load` writes files only and doesn't touch the database.
- `--dir=/path` sets where the files live. The default is `storage/app/private/dreamhorse`.

Load a raw file collected elsewhere (for example on the Mac) without fetching anything:

```bash
php artisan hf:load-dreamhorse --dir=/path/to/dreamhorse
```

Expect a full run to take most of a day: about 5,000 list pages plus one page per new ad, at about 1.5 s each.
Reruns take far less ad time, because each ad is fetched once and then only refreshed. The lists are always read in
full, since that's how ads that have gone are noticed.

## Files (in `--dir`)

- `ads.jsonl`: the state, one JSON line per ad. Lines are appended as the run goes and compacted at the end, so a
  killed run resumes where it stopped.
- `dreamhorse-ads.json` and `dreamhorse-ads.csv`: the current export of every ad on file (live and gone).
- `discovery-YYYY-MM-DD.json`: every list page read, with its total, plus a `_summary` (state total, color total, ads
  missed).

## Rerunnable

- Ads are keyed by DreamHorse ad id, in the raw file and in `sale_lots.external_id`.
- An ad on file that's missing from a full listing gets refetched. If DreamHorse says "Ad Not Found", it's marked
  `gone` with today's date, and its lot note says "ad no longer on DreamHorse as of …". The lot itself is kept as history.
- An ad that comes back is refetched and becomes `live` again.

## In Horsefax

- Each for-sale ad becomes one `sale_lots` row with status `asking` ("Advertised asking price") and source `dreamhorse`.
  Ads marked not for sale (stud, lease only, "Not For Sale") stay in the raw file only.
- Advertised asking prices aren't published yet (`HORSEFAX_PUBLISHED_SALE_KINDS`). Matched lots are therefore held,
  like HOMD's listings, and `hf:match-sales` attaches them once `asking` is published.
- Matching is on **registration number only**, following the show-results convention. When exactly one record holds
  the number (registry agreeing or blank), the lot gets `match_tier=registration` and `review_status=held`. When
  several records hold it, the lot is a `conflict` for staff. Everything else stays unmatched. Names are never used,
  and `hf:match-sales` and `hf:create-sale-records` skip `asking` lots, so no new horse records are created
  from ads.
