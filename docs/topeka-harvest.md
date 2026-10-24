# Topeka Livestock Auction horse sales

topekalivestock.com (Topeka, Indiana). Found Oct 22 2026 under Richard's standing approval. robots.txt disallows only
`/404`, the PDF host (img1.wsimg.com) disallows nothing, there are no terms of use (copyright line only) and no bot wall.

The sale pages link two kinds of PDF:

- **Catalogs** (Spring and Fall Draft Horse Sales 2022–2026, plus a Clydesdale and a Special Draft sale). Each lot gives
  the name, registration number (Belgian "M123456"/"S78758", Percheron and Clydesdale numbers), breed, sex, foaling
  date, color and a 3-generation pedigree. `TopekaParser::catalog` reads them from `pdftotext -layout`; the pedigree
  is used only when its three columns hold exactly 2, 4 and 8 names. A barn name (in brackets, after AKA, or nested in
  quotes) is dropped from the name.
- **Market reports** (lot, description, name, price). The column order changes between years, so a report price is
  attached only when the report's lot **and** name agree with the catalogued horse. That also keeps a buyer's name from
  ever being read as a horse's. Reports for sales without a catalog are not loaded.

- **Seymour Draft Horse Sale catalogs** (2023, 2026; `TopekaParser::hashCatalog`): "#1 – Name", breed and sex,
  "Reg #", "Foaled", "Markings" (color) and a 2-generation (2026) or 3-generation (2023) chart. Chart names printed on
  the "Bred by" and "Remarks" lines are kept by blanking the label text, not cutting it, so columns don't shift.
  Seymour market reports (2022–2025) price the 2023 catalog; 2026 has no report yet.
- **Northern Indiana Yearling Speed Sale** (Standardbred yearlings, 2026; `TopekaParser::speedCatalog`): one page per
  hip with name, color, sex, foaling date, USTA registration number and a 3-generation chart. Race marks
  ("p, 3, 1:49.4") and dot leaders are stripped; the chart is centred, so generations are found from the widest gaps
  between offsets. Race and produce records are never kept. The sale is Nov 9–10, 2026, so its lots load as "catalog".

- **Friesian Spring Edition & Northern Indiana Riding Horse Sale** (2026, mixed breeds; `hashCatalog` with
  `ridingChart`): the "#N – Name" layout with only the sex on the header line. Each parent is printed with its breed
  label ("Gypsy Vanner", "1/2 Friesian", "AQHA") and a "Northern Indiana Riding Horse Sale" stamp is printed over the
  chart; `unwatermark` blanks the stamp without moving the columns. Sire and dam are read by slot, so an unnamed parent
  (a breed label alone) doesn't shift the other; grandparents are kept only when exactly four clean names are left.
  The lot's breed is set only when both parents carry the same label, and a registry only when the catalog prints one
  ("Reg # … (FBH)"). A horse with no number whose parent is labelled cross, grade or "1/2" is marked not registered,
  so Richard's registered-name rule makes no record from it. No market report is posted for this sale.

Status: `sold` with a market-report price, otherwise `offered` once the sale is past, `catalog` before it. Sale dates
are the first day of sale week from the cover, stored with month precision.

Never kept: remarks, "Bred by", consignors, buyers and their towns (the kept PDFs still print them).

```bash
php artisan hf:harvest-topeka
```

```bash
php artisan hf:harvest-topeka --offline
```
