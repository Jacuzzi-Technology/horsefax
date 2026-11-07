# Lolli Bros. horse-sale catalogs

Lolli Bros. Livestock Market (Macon, MO) holds catalog horse sales in March, May, September and November. Lolli posts
each catalog as a PDF on lollibros.com before the sale and takes it down afterwards, and publishes no per-horse
results (only "Top 10" lists by color and sex, without names, which are not loaded).

```
php artisan hf:import-lolli-catalogs             # read every catalog in storage/app/private/lolli and load
php artisan hf:import-lolli-catalogs --dry-run   # count lots and pedigrees, change nothing
```

## Where the catalogs come from

`storage/app/private/lolli/pdfs/` holds one file per catalog, named `2015-03-fri.pdf` (sale year, month, and the day or
part it covers); `index.json` records where each came from:

| origin | catalogs | source_url on the lots |
|---|---|---|
| `richard_copy` | 29 catalogs, 2015-2024, that Richard kept in PDFNotes on his iPad (Dropbox › Horsefax › Sale Catalogs › DoNotEdit, listed in Catalog Inventory.xlsx). Copied, never changed; his notes are separate files and never read. | Lolli's horse page |
| `archive` | Nov 2022, Nov 2023, Nov 2024, Mar 2026, May 2026: Internet Archive copies of the PDFs Lolli posted | the Archive copy |
| `dvauction` | Sept 2013: the catalog's public PDF download on DVAuction's file server (the flipbook's `files/assets/common/downloads/publication.pdf`) | that file |

Not found anywhere: 2016-2019 catalogs not in Richard's copies, 2021 May-Nov, 2022 Mar-May, Sept 2024, 2025 (all four
sales), Nov 2025. The Archive has cut-off copies (1 or 5 MB) of Nov 2021, Sept 2024 and Nov 2025 that can't be read.
Richard downloads each catalog before the sale: new ones go in `pdfs/` with an `index.json` entry.

## What a lot gives

Registered name, registry (AQHA, APHA, ApHC, POA, ...), foaling date, sex, color and a 3-generation pedigree (14 names).
No registration numbers (three names carry their AQHA number, which is kept) and no prices: every past lot is
`offered`. The consignor ("OWNER:") and the description are never stored.

Lots are placed by `RegistrationOnlyMatch::place` (registration number, else the registered-name rule: a new
unverified record when a registered name with sire and dam has no record yet).

## How the reader works (LolliParser)

Word positions from `pdftotext -bbox`. Two layouts: 2013-2024 headers ("LOT 21 NAME AQHA" / "FOALED: ..." / "OWNER:
..."), and from Nov 2023 the name in large type above the lot number and "04/28/2013 • MARE BRED • BUCKSKIN • AQHA".
Two lots sit side by side; a lot's words are those below its header, in its column, above the next header in the
column. In the tree, sire and dam are left-aligned in one column and the grandparents in a second; the
great-grandparents sit further right, often right-aligned. A tree is kept only when it reads as exactly 2 + 4 + 8
names and repeats no name inside itself; otherwise the lot loads without a pedigree. The 2015-2017 catalogs use a
small-caps font that reads as mixed case ("blueligHt jAc e"): names are stored in capitals.

Checked Oct 28 2026 across all 35 catalogs: 7,258 lots, 5,640 full pedigrees. Of 22,200 parent pairs seen for the same
horse in different lots, 2% disagree, mostly spelling slips in the catalogs themselves.

Sale days come from the cover, only in the catalog's own month (covers also advertise the next sale). Saturday lots are
numbered from 500; a two-day catalog numbered straight through gets the month only.
