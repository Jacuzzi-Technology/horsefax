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

The Seymour, Northern Indiana Yearling Speed (Standardbred) and Friesian catalogs use other layouts and are not read yet.

Status: `sold` with a market-report price, otherwise `offered` once the sale is past, `catalog` before it. Sale dates
are the first day of sale week from the cover, stored with month precision.

Never kept: remarks, "Bred by", consignors, buyers and their towns (the kept PDFs still print them).

```bash
php artisan hf:harvest-topeka
```

```bash
php artisan hf:harvest-topeka --offline
```
