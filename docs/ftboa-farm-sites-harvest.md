# FTBOA registry and Thoroughbred farm sites

`php artisan hf:harvest-ftboa` (Oct 27 2026, Richard: build and load). Two sources, one command.

## FTBOA (ftboa.com)

The Florida Thoroughbred Breeders' and Owners' Association. Checked Oct 27 2026: robots.txt allows everything; only a
privacy policy is posted (no terms, no scraping clause); no bot wall.

- **Registry**: the "Search the FL-Bred & FSS Registry" page loads one public file,
  `/wp-content/fb-fss-lookup-csv/FB_FSS_Lookup_LIVE.csv` (24,972 horses, crops 2015–2026, no header, stray NUL bytes).
  Columns: name, dam, sire, foal crop, sex, then five Y/N flags. Matched against the on-screen table, the flags are:
  registered Florida-bred; sire is an FTBOA stallion; fully registered for Florida Sire incentives; yearling paid;
  2-year-old paid. About 22,350 rows are named with sire and dam; those become or fill records (Thoroughbred,
  unverified). Unnamed foals can't. "Registered Florida-bred" and "Registered for Florida Sire Stakes incentives"
  become Achievements & earnings program entries. Sex: Filly/Colt are kept only for crops of the last 3 years
  (older fillies are mares; older colts may since have been gelded, so they are left blank).
- **Stallions** (32, listed by `/wp-json/wp/v2/stallion`): sire - dam, by damsire; farm; Florida foal crops; the
  "Farm Website" link (kept on the stallion service, and the list of farm sites to check).

## Farm sites on Horse Hosting

Thoroughbred farm websites built on Horse Hosting (horsehosting.com), such as Ocala Stud, share one stallion-page
template: name, breed line, fee ("Fee: $3,000 LFSN"), a 4-generation pedigree table (country, color and foal year of
each ancestor), a race-record table with totals, and the farm's news.

Farms come from FTBOA's "Farm Website" links plus `FarmSiteHarvester::SITES`. **Every farm is checked before
collecting**, and the result is kept on its harvest page:

1. robots.txt is read first; if it disallows `/horses/`, the farm is skipped (`robots.txt disallows /horses/`).
2. The site must be Horse Hosting (the parser fits nothing else), otherwise skipped.
3. A site that links a terms/conditions/legal page is held with status `review` until someone reads it.

Loaded through `ImportPublishedHorse` (Thoroughbred, unverified, "(USA)" dropped, other country suffixes kept):
pedigree to four generations, race record (Achievements & earnings), stallion service (farm, fee and terms, website),
and each news story as an "In the news" link to the farm's article (title and date only). Not kept: photos,
descriptions, article text.

    php artisan hf:harvest-ftboa              # registry + stallions, then farm sites
    php artisan hf:harvest-ftboa --no-farms   # FTBOA only
    php artisan hf:harvest-ftboa --offline    # load kept files, no requests

HorsefaxBot, at least 3 s apart; a 403/429 from a farm skips that farm.
