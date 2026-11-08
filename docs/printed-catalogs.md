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
| `model` | Stroud Oklahoma Horse Auction, Oct 31 2026 (Google Drive PDF from Richard) | `LOT 1  MC GENUINE LITTLEJET  LOT 1` / `2019 MODEL PALOMINO GELDING`; unnamed lots ("DUN STUD COLT") are skipped |
| `seymour` | Seymour Select Horse Sale 2026 | `Lot 25 - Coco Blackburn Champ, "Champ"   Quarter Horse` / `Reg. #6216001  DOB: 06/10/2022  Gelding` |
| `farmers` | Farmers & Ranchers Fall Classic 2021 (Salina, KS) | read from text: `LOT # 5 - MARE` / `NAME - AQHA` / `Foaled: 2010. Color: Bay.`; sire and dam only (the trees are justified type) |
| `qdata` | Shawnee Horse Sale, Spring 2020 | QData "Hip No." pages, read by `LegacyCatalogParser` (several hips to a page) |

The layouts below are "generic" (Nov 2026): set up by rules in `LAYOUTS` rather than code. The name is inline, on the line
below, or in a labelled field; the description is the next line or the lines matching a pattern, read by `free()`; the tree
is whatever names sit below. `nameFacts()` reads what's printed with a name ("NAME - AQHA 5757650", "NAME - QH Grade",
"Name “Barn name”"). A line ending in " - <State>" is a consignor's, never a name: that lot is skipped.

| Layout | Sale | What a lot looks like |
|---|---|---|
| `lotname` | Five Star Horse Sale 2018 (Schmitt Horse Ranch, OCR), Anderson County 2021, Colorado Horse Sale 2025 | `LOT# 23` / `GETTIN HUNTED` / `2013 AQHA SORREL GELDING` |
| `hipfields` | Schmitt Horse Ranch Timed Internet Offering, Dec 2020 | `HIP 9` / `HORSE NAME:` / `REGISTRATION NUMBER: AQHA 5710562` / `AGE: 2015 AQHA Buckskin Gelding` |
| `dots` | Midpoint Riding Horse Sale 2025-26 (Middlefield, OH) | `41 · a streak of nitro · $` / `7-23-2022 • AQHA Gelding • Red roan` |
| `starred` | Midwest Draft Cross & Quarter Horse Sale 2025 (Jamesport, MO); 5 Star Equine Sale 2026 (Arcola, IL) | `129 GENUINE'S SMART PEARL` / `Consigned By:` / `*Foaled: 2022 *Sorrel *AQHA *Mare`; trees drawn on "____" rules, draft-cross trees naming breeds |
| `bignum` | South Dakota's Elite Horse Sale 2023 | `16 TENDER BEAUTY` (large) / `DOB: 6-2-22 • Chestnut Mare • REG: 6213427` |
| `hipline` | Heart of Cowboy Country 2013 (Stillwater, OK; OCR) | `Hip # 25   AQHA Sorrel Gelding` / `SONNY RUNNING CASH - 870841` / `Foaled 2006` |
| `gatrhs21` | Great American Ranch & Trail 2018-2024 | the hip printed large beside or just below `NAME - AQHA 4198657`, then `2002 Palomino Mare`; sire and dam alone where the rest of the tree is dotted leaders |
| `form` | Boone County, September 2021 | `LOT 429-  Horse: Revenue Lauxmont  Age: 6 yrs` / `Registered: x  Color: Bay  Breed: Standardbred  Sex: Mare`; teams skipped |

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

**Great American.** TurnbullHarvester reads 2025-26. Its 2018-2024 copies in `storage/app/private/turnbull/pdf` were
damaged in the download (each cut to about 60% of the size it declares), which is why they looked like scans. Fresh copies,
fetched Nov 6 2026 at one a minute, have text and are read here from `printed-catalogs/greatamerican` (2021 from Richard's
copy). Don't also point TurnbullHarvester at them: the same lots would load twice.

## From the sales' own sites (Nov 8 2026)

Richard asked to collect from every site found while reading the catalogs:

- **Colorado Horse Sale results** (`ColoradoHorseSaleResults`): the Sale Results page's files, kept in
  `printed-catalogs/colorado/results`. Full tables (every horse, final bid, sold/unsold) for February, April, May, August
  and October 2026 and October 2025; top-seller summaries for 2023-2025. A lot the May 2025 catalog already has gets the
  price; an unmatched catalog lot takes the results' name and is placed again. Consignors, buyers and destinations in
  the summaries are cut before anything is read.
- **South Dakota's Elite 2026**: the catalog PDF on its Catalog page. The barn name is printed large; the registered name
  is the horse's own place in the tree (`treeName`).
- **Schmitt Horse Ranch, Five Star May 2022**: the catalog on its Five Star page (QData hip pages; 97 MB, stored with
  the photos stripped).
- **Great American 2016 and 2023**: 2023 from the site; 2016 from the Internet Archive (the site's link now returns a
  web page).
- **Southern Belle Classic 2024** (`webCards`): Richard's screenshot of the listing site. Its cards hold labelled fields;
  only the horse's are read. The listing site (Horse Sale Management) is empty between sales and the Archive has none.
- **Heartland 2020** (`heartland`): a registered name read from the first words of a description, kept only when the
  year printed with it matches the hip heading (the text layer sometimes puts a description under the other hip).
- **Heart and Soul 2019** (`heartAndSoul`): a header is kept only where OCR (`2019-11.pdf.ocr.txt`) and the text layer
  agree letter for letter.

Index lines naming consignors ("HIP 47 • Jessica Bunch / Jacob Littlepage • Strutin for the Money") are collected, and
a lot whose "name" is one of them, or ends in a business word (Stables, Farms, LLC, Performance Horses), is dropped.

## Not read

- LMA Auctions (lmaauctions.com), where Anderson County posts later catalogs: its Terms of Use allow personal use only
  without written permission. Nothing collected from it.
- 5 Star Equine Sale 2025: Richard's copy is a screenshot of the FlipHTML5 cover; the flipbook's address isn't known.
- Midwest Draft Cross (gallopingbranch.com is an empty page), Midpoint and Heart of Cowboy Country: no sites.
