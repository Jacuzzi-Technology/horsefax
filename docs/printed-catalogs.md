# Richard's printed sale catalogs

Richard keeps sale catalogs in PDFNotes on his iPad. The copies are in Dropbox › Horsefax › Sale Catalogs › DoNotEdit, listed in `Catalog Inventory.xlsx`. On Oct 28, 2026 he asked us to go through all of them ("I have them from other sales as well").

Lolli (`hf:import-lolli-catalogs`) and Billings have their own loaders. Everything else goes through `hf:import-printed-catalogs`:

```bash
php artisan hf:import-printed-catalogs --copy            # copy new catalogs from Dropbox (Richard's Mac), then load
php artisan hf:import-printed-catalogs --sale=boone      # one sale company
php artisan hf:import-printed-catalogs --dry-run         # count only
```

- **Code:** `App\Harvest\PrintedCatalogs` lists each sale company: its data source, the legal check, the layout, and its catalogs (the file inside DoNotEdit, plus year and month).
- **Storage:** the copies live in `storage/app/private/printed-catalogs/<sale>/<year>-<month>.pdf`. The server gets them by rsync. They are read there and never changed.
- **Status:** the catalogs give no prices, so every lot is `offered`.
- **Placement:** each lot is placed by `RegistrationOnlyMatch::place`: registration number first, then the registered-name rule.
- **Facts only:** consignors, owners, phone numbers and descriptions are never stored.

## Layouts (`App\Harvest\PrintedCatalogParser`)

Most sales print three things for each lot:
- a lot or hip anchor with the name;
- a line with registry, number, sex, color and foaling date;
- a positional tree.

Each layout finds the anchors on a page. The block under an anchor, in its own page column down to the next anchor, is read the same way for every layout. The tree is the first columns of 2, 4 and 8 names, left-aligned or centered. A deeper column is ignored. A parent must sit between its own two parents, or the tree is dropped. That check matters: one Southwest page sets a lot's tree under the neighboring lot's header.

| Layout | Sale | What a lot looks like |
|---|---|---|
| `southwest` | Southwest Horse Sale (Bush, LA) | `LOT NO. 6 STORM TREASURE` / `TWHBEA 20804163 MARE PALOMINO 2008` |
| `boone` | Boone County Draft Horse & Mule Sale | `LOT 5 Rock Lane Twelve 4 yr old Registered Black Percheron Stallion.` (age → foal year by calendar year) |
| `cla` | CLA Spring Horse Sale 2016 (Clovis) | `LIL SUGAR CANDY   Hip # 26` / `AQHA 4879822-GRAY MARE MAY 5, 2006` |
| `consignor` | Clovis Horse Sales Fall 2021 | name / `Consignor: …` / `2010 Gray AQHA GELDING // Reg #5279557`; lot number printed large beside the tree |
| `regline` | Twin Cities Appaloosa, Paint & QH Sale 2019 | name / `AQHA 5549037` / `Foaled May 07, 2013- Gelding`; 2 generations with numbers |
| `seymour` | Seymour Select Horse Sale 2026 | `Lot 25 - Coco Blackburn Champ, "Champ"   Quarter Horse` / `Reg. #6216001  DOB: 06/10/2022  Gelding` |
| `farmers` | Farmers & Ranchers Fall Classic 2021 (Salina, KS) | read from text: `LOT # 5 - MARE` / `NAME - AQHA` / `Foaled: 2010. Color: Bay.`; sire and dam only (the trees are justified type) |
| `qdata` | Shawnee Horse Sale, Spring 2020 | QData "Hip No." pages, read by `LegacyCatalogParser` (several hips to a page) |

**Robin Glenn overlap.** Robin Glenn already posts the Shawnee and Seymour hip pages. Richard's other Shawnee copies are sales Robin Glenn already has, so only Spring 2020 is read. Seymour 2026 skips any lot that Robin Glenn has under the same lot number.

**Waverly Fall 2021.** Richard's copy is whole, while the Internet Archive's is cut off. It was added to the Waverly loader as `root_WMHS-F21-Catalog.pdf` (origin `richard_copy`).

## Scans (OCR)

The Boone County 2022 and 2023 catalogs are scans (Corel PDFs with no text). On Oct 29, 2026 they were read with OCR. Each page was rendered at 300 dpi and run through Tesseract as a text-only PDF:

```bash
pdftoppm -r 300 -gray -png scan.pdf p
for f in p-*.png; do tesseract "$f" "${f%.png}" -l eng --psm 3 -c textonly_pdf=1 pdf; done
pdfunite p-*.pdf 2022-03.pdf
```

The result is stored as `printed-catalogs/boone/<year>-<month>.pdf` and read with the `boone` layout. `--copy` never overwrites it with the scan.

These years print a shorter tree: sire and dam, with only each one's sire line above it. That gives `s`, `d`, `ss`, `ds`, and sometimes `sss` and `dss` (see `sireLines`). Team lots ("LOT 131 & 132") are skipped in every Boone catalog.

## Not read yet

- Boone County 2022 and 2023: scans, needs OCR.
- Boone County September 2021: a form layout (`Horse:` / `Age:` / `Breed:`) with no pedigrees.
- Heart of Cowboy Country 2013: small caps that the PDF text splits into single letters.
- Timed internet offering 2020, Midpoint 2025–26, Midwest Draft Cross 2025 and its 2026 sister catalog, Colorado 2025, Virginia Horse Center 2021, South Dakota's Elite 2023, Heart and Soul 2019, Anderson County 2021, Heartland 2020, Southern Belle 2024: each has its own layout.
- Great American 2018–2024: scans (see docs/great-american-trail-sale.md).
- 5 Star Equine 2025: an image capture.
