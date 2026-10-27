# MLOSZ (Hungarian stud book) harvest

`php artisan hf:harvest-mlosz` reads the free detailed horse search of **MLOSZ**, the Federation of Hungarian Horse
Breeders (mlosz.hu). MLOSZ runs Hungary's horse breeding database with the national food-chain authority (Nébih).
Kate found it on Oct 27 2026.

## Why it's allowed (checked Oct 27 2026)

- robots.txt: `User-agent: * / Disallow:` (everything allowed, including `/api/`).
- Terms: the English page is a placeholder ("terms in english..."). The Hungarian ÁSZF (`/aszf`, in force from
  Feb 27 2025) covers paid subscribers (ads, microchip search) and has no scraping, database or reuse clause.
- Privacy policy (`/adatkezelesi-tajekoztato`): no such clause.
- No bot wall. Owners are not published ("Data not public").

## What it collects

The search page posts `{"fajtaKod": "<breed>"}` to `/api/search/detailed-search?offset=&limit=100`, where `offset` is the page number (0, 1, 2 …), not a row offset. Each answer is
kept gzipped under `storage/app/private/harvest/mlosz/<breed>/`. A row gives: name, Hungarian ID (`HUN K IM…`),
foal date, sex (gelded flag), color, breed, sire, dam, dam's sire and breeder.

Breeds collected: **every horse breed MLOSZ lists** (111 codes from `/api/assets`, minus donkey, Hungarian donkey,
mule and zebra; Richard, Oct 27 2026: build Horsefax to be useful around the world). Names come from MLOSZ's English
list (Hungarian when it has none); `MloszHarvester::BREEDS` gives the Horsefax name for a few (Quarter Horse, Paint,
Appaloosa, Thoroughbred, Arabian, Morgan ...). Oct 27 2026: 8,966 Quarter Horses, 1,224 Paints, 745 Appaloosas.

Pedigree depth: early American horses are in the stud book themselves (King 1932 by Zantanon, Poco Bueno, Wimpy,
Leo, Doc Bar, Joe Hancock, Joe Reed), each with a Hungarian ID (`HUN M XI…`), so linking rows gives the 5–7
generations the data sheet shows. Three Bars is filed as a Thoroughbred, so the all-breed run brings him in. King
appears twice (one entry with parents, one without); both land on one record. Some foal years are typos (192, 2203).

## How rows become records

Through `ImportPublishedHorse` (source "published", identity unverified, no owner):

- The Hungarian ID is stored as an **MLOSZ registration number** ("Hungarian stud book (MLOSZ)"), so a rerun finds
  the same record. A **UELN**, when listed, is stored as its own identifier (new kind `ueln`).
- Sire, dam and dam's sire become pedigree lines; ancestors get records.
- **Breeder** is filled when blank (Richard, Oct 27 2026: breeders are public, owners are not). The Hungarian
  "és" ("and") becomes "&".
- **Deceased**: a horse recorded as a permanent loss (`fogyatekVegleges`) gets a Death entry with no date. The data
  sheet also shows very old horses as deceased without that record (apparently by age: 1988 and 1990 horses
  deceased, 1997 not); those are not recorded.
- `parentage_must_agree`: names repeat across countries, so a same-named record whose sire or dam differs is a
  different horse, and a new record is made beside it. When several same-named records remain, the row is skipped
  (`skipped_ambiguous`).
- A record someone owns is never touched (`skipped_owned`).

No US registration numbers are published, so records are matched on name and parentage.

## Running it

    php artisan hf:harvest-mlosz                 # fetch pages not kept yet, then load new or changed pages
    php artisan hf:harvest-mlosz --offline       # load kept pages, no requests
    php artisan hf:harvest-mlosz --breed=quart   # one breed
    php artisan hf:harvest-mlosz --refresh       # refetch every page; reload only pages whose content changed
    php artisan hf:harvest-mlosz --reload        # load every kept page again

House rules: HorsefaxBot user agent, at least 3 s between requests (the command won't go lower), a 403/429 stops the
run. Fetching is quick (100 horses a request); loading is the slow part, about 200 horses a minute, so the first
all-breed load takes a day or more. MLOSZ lists horses in id order, so new horses land on the last pages and a
monthly `--refresh` reloads only those.

Schedule (production crontab, horsefax user): monthly `--refresh`.

## Breeder field

Added Oct 27 2026 to every record (`horses.breeder`, plus a `breeder` horse fact with source and history). Shown in
the Identity table and the public report. Owners can edit it. `php artisan hf:backfill-breeders` fills it from the
"Breeder:" line of the AQHA papers already on file (`registry_records`); owners on those papers stay recorded, not
shown.
