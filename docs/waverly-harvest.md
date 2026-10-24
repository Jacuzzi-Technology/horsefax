# Waverly Midwest Horse Sale catalogs

Waverly Sales Company (Waverly, IA) runs a spring (March/April) and a fall (late September/October) horse sale, mostly
draft horses plus light horses, ponies and Gypsies. Checked Oct 23 2026 and collected under Richard's standing approval;
he asked for the archived catalogs too.

- `waverlysales.com` robots.txt (Yoast) allows everything, and so does `www.waverlysales.com`. The "Privacy Policy and
  Terms & Conditions" page covers privacy and SMS consent only, with no scraping, crawling or database clause. There's
  no bot wall.
- `web.archive.org` has no robots.txt (404).

This is separate from the waverlysales.bid harvester (`hf:harvest-auctioneersoftware`, source `as-waverly`), which
collects the same sales from Fall 2024 on, with prices but without registration numbers or pedigrees.

## Finding the PDFs

The current WordPress site keeps catalogs under `/wp-content/uploads/<yyyy>/<mm>/`. The site before it kept PDFs at
the root (`Catalog_F20.pdf`, `Thursday_F17.pdf`, `HORSES~1.PDF`, ...). Every PDF the Internet Archive has seen on
either host is listed from its CDX API (the site's own media API strips query strings, so it can't be filtered).
Each one is read from Waverly's server first and from the Archive only when it's gone there.

- The Archive's first capture of a large PDF is often cut off at exactly 1 MB, so the harvester takes the largest
  capture of each file. A file counts as whole only when its last 4 KB hold `%%EOF`. Fall 2020 (`Catalog_F20.pdf`),
  Fall 2021 (`WMHS-F21-Catalog.pdf`) and two dispersal catalogs survive only as cut-off copies and are skipped
  (`truncated: true` in `pdf-index.json`).
- Never fetched: consignors' own uploads under `/gravity_forms/` (pedigrees, registration papers), and forms,
  certificates, schedules, calendars, flyers and sale bills (`WaverlyHarvester::candidate`).
- A PDF with fewer than 5 horses is not a catalog. Two printings of one catalog (an early and a final version of
  Spring 2024) keep the fuller one.

## Layouts (`WaverlyParser`)

1. **2013–2025**: `LOT 11 - Name M140348      Belgian Mare`, `Foaled: March 18, 2019`, a color line, then
   `Sire: <name> <reg>, by <name>, out of <name>` with the sire's sire and sire's dam on the next lines, and the same
   for `Dam:`. That gives three generations. 2013 prints `2nd Dam:` / `3rd Dam:` instead. Grade horses are prose
   (`LOT 8 - Pete, a grade 2 year old black Percheron gelding.`): name, age, color, breed and sex only. Teams and
   pairs (two horses in one lot) and unnamed lots are skipped.
2. **2026**: `Lot 66 - Name      Registered Clydesdale Mare` with a 3-generation chart in columns, `Reg. #29473`, and
   `Foaled 03/04/2023` in the description. The horse's own name prints at the left edge of the chart and is blanked
   before the columns are read (`TopekaParser::chart`, counts must be 2 / 4 / 8).
3. **2003–04 flyers** ("Partial Listing", two or three columns, no lot numbers): read in reading order (`pdftotext`
   without `-layout`). Only registered horses are kept: name, breed, sex, foal date, color, sire and dam.

Registration numbers come off the names in every shape these catalogs print: `S68529`, `23839-M`, `A-22807-M`,
`#290737`, `70957 CN` and `M33478(CN)` (Canadian, never taken as the horse's own number), `-1039184-`, and
`Molly-2113-88`. A 7-digit Quarter Horse number is AQHA. No other registry is inferred (draft numbers stay without
one, as in the Topeka catalogs).

**Sale:** the season and year come from the file name (`WAV-S26`, `WMHS_F19`, `WHS-F24`) or the cover ("2004 Fall",
"Spring 2013"). The 2015 Thursday catalog prints only weekdays and dates ("Thursday, October 8"), so the year is the
one at or before the Archive's capture whose calendar puts most of those dates on those weekdays.

## Loading and the .bid lots

Each lot is a `sale_lots` row of source `waverly`, external id `wav:<season-year>:<lot>` (flyers:
`wav:<season-year>:n-<name key>`). Status is `offered` (the catalogs publish no results) and no price is stored.

When the waverlysales.bid lot of the same horse exists (same season, same lot number, names that agree or one holds
the other, as "ConnRoyal" for "Riverglen's ConnRoyal"):

- the catalog lot goes onto the .bid lot's record, or the waiting .bid lot goes onto the record the catalog found;
- the catalog lot adds only identity (registration number, foal year, pedigree, through `AddPublishedIdentity`) and
  is marked `review_status = same_sale`, so the nightly `hf:match-sales` never adds an "offered" entry beside the
  .bid lot's reported sale;
- its sale date is the .bid lot's day. Otherwise the date is the sale week's first day, `date_precision = month`.

Other lots are placed by `RegistrationOnlyMatch::place` (registration number, then the registered-name rule), like
the Heritage and Topeka catalogs.

**Facts only:** consignor, "Fitted by", towns, phone numbers and the description text are never kept. The kept PDFs
still print them.

First load (local database, Oct 23 2026): 15 catalogs (Fall 2003 to Spring 2026), 3,642 lots, 374 with registration
numbers. 141 were paired with .bid lots, 913 made new unverified records, 8 were matched on number, 8 linked to a
record already made from this source, 31 proposed to staff, 32 queued, 2 conflicts, and 2,648 left unmatched (mostly
grade horses).

Files (in `storage/app/private/waverly/`): `pdf/`, `pdf-index.json` (live URL, the Archive capture used, where it came
from) and `waverly-catalogs.json` (sale, lot and number counts per catalog).

```bash
php artisan hf:harvest-waverly
```

```bash
php artisan hf:harvest-waverly --offline
```

`--no-load` downloads only. For production, rsync `storage/app/private/waverly/` first and run `--offline`.
