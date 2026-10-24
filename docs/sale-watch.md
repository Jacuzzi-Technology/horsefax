# Sale calendar (hf:sale-watch)

Richard, Oct 24 2026: "We should be able to check the sale calendar and schedule precise checks rather than weekly."

Sale sources publish on a rhythm: a catalog appears some weeks before a sale, and results (where a source posts any)
appear in the days after it. `hf:sale-watch` keeps a calendar of upcoming and recent sales (`sale_events`) and runs a
source's harvester only when one of its sales is due a check. It runs daily from cron and usually does nothing.

## When a sale is checked

Before the sale, while its catalog hasn't been seen, the source is checked at 90, 75, 60, 45, 30, 21, 14, 7 and 3 days
before the first day, starting at the source's lead time. Once the catalog has been seen, there's one last look 2 days
before, for scratches and revisions.

After the sale, a source that posts results is checked 1, 3, 7, 14 and 30 days after the last day. A source that posts
no results gets one check at 4 days, so its catalog lots turn from `catalog` to `offered`. After that the sale needs
nothing more. Sales that ended more than 35 days ago aren't added.

New sales still have to be found, so each source also gets a discovery run every `discover` days.

| Source | Where the dates come from | Results after the sale | Discovery | Catalog lead |
|---|---|---|---|---|
| Robin Glenn | `inventory.csv`: each catalog carries its sale dates, usually weeks ahead | yes | 7 days | 30 days |
| Pitzer Ranch | the sales page ("2027 Fall Sale – September 10th & 11th"), kept in `calendar.json` | no | 14 days | 90 days |
| Heritage | catalog lots (status `catalog`, with the sale date) | top sellers | 14 days | 45 days |
| Topeka | catalog lots | market reports | 14 days | 45 days |
| Triangle | catalog lots | no (results are on CCI.live, not collected) | 30 days | 45 days |
| Billings | catalog lots | top-20/40 lists | 14 days | 45 days |

Robin Glenn's checks re-read only the catalogs that are due (`hf:harvest-robinglenn --cats=3068,3100`) instead of the
last 300 catIDs each week; its discovery run still probes past the highest catID for new catalogs. Robin Glenn entries
that aren't sales are skipped: private pedigree orders, "Deleted", "Non Sale", and catalogs whose dates span more than 14
days (a ranch's standing list, "2021-01-01 – 2031-01-01").

Pitzer's harvester now reads the sales page for new catalog PDFs, as well as its fixed list. A Pitzer sale that Robin
Glenn already loaded (Spring 2024 through Spring 2026) is left to Robin Glenn, so the same horses aren't loaded twice.

Not on the calendar: the online-auction sources (LiveAg, Platinum, AuctioneerSoftware sites, Premier) post results the
moment a sale closes and keep their own sweeps; the ad sites (DreamHorse, RanchWorldAds) aren't sales. Legacy Reining's
results pages are a hard-coded list per year, and Waverly's catalogs come from the Internet Archive; both still need a
person to add the next sale.

## Running it

```bash
php artisan hf:sale-watch --list
```

```bash
php artisan hf:sale-watch --dry-run
```

```bash
php artisan hf:sale-watch
```

`--source=pitzer` limits a run to one source. The last discovery run per source is kept in
`storage/app/private/sale-watch.json`; delete a source's entry to force a discovery run.

Production (live Oct 24 2026): one daily cron line at 05:00 UTC in the `horsefax` crontab, under the matching lock,
logging to `storage/logs/sale-watch.log`. It replaced the Wednesday Heritage + Robin Glenn script
(`/usr/local/bin/horsefax-catalogs-weekly`, no longer called) and took Billings out of the Monday sales bundle. Crontab
backup: `/root/horsefax-crontab.bak-2026-10-24-salewatch`.
