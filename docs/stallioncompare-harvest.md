# StallionCompare harvest

`php artisan hf:harvest-stallioncompare` reads stallioncompare.com, a free stallion directory based in Kingston,
Ontario: stock-horse stallions (mostly AQHA and APHA) with pedigrees, genetics, stud fees, where they stand,
earnings, highlights, progeny and article mentions. Richard asked for it to be built and loaded on Oct 27 2026.

## Why it's allowed (checked Oct 27 2026)

- robots.txt blocks only admin and private paths (`/private/`, `/admin/`, `/img/` …); listing, `/stallion/` and
  `/horse/` pages are open.
- Terms (Ontario law): no scraping, database or reuse clause.
- Copyright page: StallionCompare claims no copyright on stallion listing content (provided by the owners).
- No bot wall. `sitemap.xml` is broken (it returns the home page), so the crawl starts from the listing.

## Crawl

1. Listing pages `/listing/<n>/name_az/0/…/0`, 25 stallions a page. Only links after the filter panel ("Refine
   Search") count: the banner and "Featured" carousel above it rotate on every request.
2. Every `/stallion/<slug>` page.
3. Every `/horse/` or `/stallion/` page those link to (pedigree and progeny): one level deep, so the crawl doesn't
   wander through every ancestor. Mares in the pedigrees are fetched here too.

HorsefaxBot, at least 3 s apart; a 403/429 stops the run; a 404 is not retried. Pages are kept gzipped under
`storage/app/private/harvest/stallioncompare/`. `--refresh` refetches pages older than 25 days and reloads only pages
that changed.

## What is loaded

Through `ImportPublishedHorse` (source "published", unverified; same-named records with different parents stay apart;
a record someone owns is left alone):

| On the page | In Horsefax |
|---|---|
| Name, foal year, color, breed, registry, sex, height | identity (filled when blank) |
| 4-generation pedigree | pedigree lines; ancestors (sires and mares) get records |
| "In loving memory of …, 1997 – 2019" | Death entry, year precision |
| Genetics ("5 Panel Negative: HYPP (N/N) …") | Health · Genetic test, public |
| "$40,110 in LTE"; earnings beside a parent in the chart | Achievements & earnings · Reported lifetime earnings (on that horse's record) |
| Highlights / Overview list | Achievements & earnings · Title, award or honor (year when the text has one) |
| Nominations (Super Sires, NSBA BCF …) | Achievements & earnings · Incentive program nomination |
| Fees, chute fee, breeding terms, shipping regions | `stallion_services` |
| Standing At: farm, town, state, country, website, phone, email | `stallion_services` (street address not kept) |
| Breeding Manager: name, phone, email | `stallion_services` (Richard: breeding contacts help owners get contracts) |
| Article mentions | `horse_mentions`: title and link only |
| Outstanding progeny with sire **and** dam, name of 2+ words | new unverified records |

Never stored: the Owner(s) card (names and contacts), street addresses, photos, article text.

The record page and public report show these in a **Published profile** section (lifetime earnings, At stud, In the
news); titles, nominations and genetic tests appear under History.

## Running it

    php artisan hf:harvest-stallioncompare             # fetch new pages, then load new or changed ones
    php artisan hf:harvest-stallioncompare --refresh   # refetch pages older than 25 days (monthly cron)
    php artisan hf:harvest-stallioncompare --offline   # load kept pages, no requests
    php artisan hf:harvest-stallioncompare --reload    # load every kept page again

Not collected yet: the international show results (`/show-results`, 2023–2026), which may republish results from
associations Horsefax treats as off-limits and need checking first.
