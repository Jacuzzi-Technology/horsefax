# Legacy Reining Breeders Sale results

The Legacy Reining Breeders Sale is a reining yearling and broodmare sale held every September at ThorSport Ranch,
Aubrey TX. legacysale.com publishes a results page for every sale from 2014 on, with the price for each hip, which is
what Horsefax was missing for reining sales. Checked Oct 23 2026 and collected under Richard's standing approval.

- The site runs on WordPress. robots.txt disallows only `/wp-admin/` and `/wp-content/uploads/wpforms/`. There are no
  terms of use (only a copyright line) and no bot wall. `collect()` reads robots.txt again before each run and stops
  if it ever disallows the results pages.
- HorsefaxBot user agent, at least 3 s between requests. A 403, a 429 or a bot check stops the run (`HarvestBlocked`).

This is separate from the Robin Glenn crawl, which already has the 2025 and 2026 Legacy catalogs (catIDs 2650 and
3061) with pedigrees but no prices.

## Pages

| Sale | Page | Date |
| --- | --- | --- |
| 2014–2024 | `/<year>-sale-results/` | September, month precision (the pages print no date) |
| 2025 | `/2025-sale-results/` | Sept 20 2025 (Robin Glenn catalog 2650) |
| 2026 Designer Sale | `/2026-designer-sale-results/` | Fri Sept 18 2026 (the site's schedule) |
| 2026 Legacy Sale | `/2026-legacy-sale/` | Sat Sept 19 2026 (the site's schedule) |

Each page holds one or two TablePress tables (2014–2017 have two, hips 1– and 201–). The columns change from year to
year, so `LegacySaleParser` reads them by their headings:

- **2014–2021:** hip, a horse cell (`NAME<br>2013 Palomino Stallion`), sire, dam, buyer and price. 2019–2021 add a
  consignor column.
- **2022–2023:** the horse cell with the foaling line, but no sire or dam.
- **2024–2026 Legacy:** hip, consignor, name, amount and buyer.
- **2026 Designer:** name, sex, foal year, color, sire and dam in their own columns.

What the parser takes from each row:

- **Name:** `*` markers and anything after `*` in the foaling line (`*Bred to ...`, `*SELLS WITH RECIP ...`) are
  dropped. All-capitals pages are title-cased (`NrchaHarvester::display`).
- **Foal year, sex, color:** read from the foaling line. A lone `APHA` is taken as the registry. `AQHA/APHA` names
  both registries, so none is set, and AQHA is never assumed.
- **Outcome:**
  - The buyer cell says `Pass Out`, `-PASS OUT,` or `PO`: `no_sale`. No price is stored. The last bid goes in the
    note when one is printed (2014–2017).
  - `OUT`, `-OUT OF SALE`, `Name - Out` or `Name (OUT)`: scratched and skipped.
  - A price over $0: `sold`.
  - Otherwise: `offered`, meaning it was catalogued but no result was printed.
- **Unnamed horses:** `PENDING`, `Pending/<dam>`, `Pending A/<dam>`, `NAME (PENDING)`, `Selling On Reg App`,
  `FOAL IN UTERO`, `EMBRYO`, `Weanling To 55` and blank names. These are stored as `Pending` with
  `registered = false`, with the hip, the price, and the dam when the page names one. They are marked `unmatched` and
  never matched by name.
- **Skipped rows:** totals, averages and blank spacer rows. When a hip is printed twice, the first row is kept.

**Facts only:** the consignor and buyer cells are read only to tell a sale from a pass-out or a scratch, and nothing
from them is stored. The kept pages still print them.

## Loading and the Robin Glenn lots

Each row becomes a `sale_lots` row of source `legacysale`, with external id `legacy:<sale key>:<hip>` (sale keys
`2014` to `2025`, `2026-designer` and `2026`). The sale name is `Legacy Reining Breeders Sale <year>`, or
`... 2026 (Designer Sale)` for the Designer Sale. Buyer's premium is `unknown`.

A results row is paired with a Robin Glenn lot when the Robin Glenn lot is in the same sale year with the same hip,
its sale name starts with "Legacy Reining Breeders Sale", and the names agree (or one holds the other, as
`WaverlyParser::bidLot` does). This reverses the Waverly pairing (`docs/waverly-harvest.md`):

- If the Robin Glenn lot is already on a record, the results row goes onto that record (`match_tier = same_lot`) and
  carries the price. The nightly `hf:match-sales` puts it in the history.
- If the results row makes or finds the record itself (2026 Designer, which prints sire and dam), a Robin Glenn lot
  still waiting (no record, `unmatched` or unreviewed) goes onto it, and its pedigree and foal year are added through
  `AddPublishedIdentity`.
- Either way, the Robin Glenn lot is marked `review_status = same_sale` so the matcher never adds an "Offered at
  auction" entry beside the reported sale.
- If the Robin Glenn lot already has a history entry of its own, or sits on another record, both lots are left as
  they are and counted in `robin_glenn_lot_left_for_staff`. On production, the nightly matcher may already have put
  an "offered" entry on some of those records. **TODO (deploy session):** check `robin_glenn_lot_left_for_staff` after
  the production load and decide whether to remove those "offered" entries.
- "Pending/..." rows are never paired, even on the same hip. Robin Glenn stored those catalog lots under names such
  as "Pending/Exit 21" and made records from them. That is a separate cleanup.

All other rows are placed with `RegistrationOnlyMatch::place($lot, 'Legacy Reining Breeders Sale')`. The pages give
no registration numbers, so only the registered-name rule can act. It needs sire and dam, so the 2014–2021 and 2026
Designer rows can propose or create records, while the 2022–2026 Legacy rows mostly stay unmatched. That's expected.

## First load (local database, Oct 23 2026)

- **Pages and lots:** 14 pages, 2,096 lots loaded and 97 scratched rows skipped. 1,825 sold, 253 passed out and 18
  offered with no result. 1,456 lots have sire and dam, and 132 were unnamed (Pending and similar).
- **Robin Glenn pairing:** 157 rows paired with the 2025 and 2026 catalog lots. In 20 more, the hip and name agree
  but neither lot is on a record yet.
- **Placement:** 1,060 new unverified records, 78 proposed to staff, 123 queued, 7 linked to a record already made
  from this source and 671 left unmatched.

Per-row sums agree with the totals printed on the 2022, 2024 and both 2026 pages. The 2023 printed total counts its
$110,000 of pass-outs. The 2019 total is $49,100 above the row sum and the 2020 total is $27,500 below it; neither
difference has been traced.

Files (in `storage/app/private/legacysale/`): `pages/<slug>.html.gz` (the fetched pages) and `legacy-sales.json`
(counts per sale, no names).

```bash
php artisan hf:harvest-legacysale
```

```bash
php artisan hf:harvest-legacysale --offline
```

`--no-load` only fetches. A kept page is never fetched again; delete its `.html.gz` to refresh it. For production,
rsync `storage/app/private/legacysale/` first and run `--offline`.
