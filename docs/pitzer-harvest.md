# Pitzer Ranch sale catalogs

pitzerranch.net (Ericson, Nebraska; a long-running, high-volume Quarter Horse ranch and rope-horse sale). Collected
Oct 23 2026 under Richard's standing approval. It's a WordPress.com site: robots.txt disallows only WooCommerce and
`/wp-admin/` paths, there are no site terms, and WordPress.com's own terms have no scraping clause.

Four Pitzer sales are already in through the Robin Glenn crawl (Spring 2024, Fall 2024, Spring 2025, Spring 2026;
catIDs 2122, 2282, 2484, 2860). Robin Glenn emptied the Fall 2025 and Fall 2026 catalogs after those sales, so this
harvester reads the PDFs Pitzer posts on its own site instead:

| Sale | Dates | PDF | Lots loaded |
|---|---|---|---|
| Fall 2022 | Sep 9–10, 2022 | `Website-List-F22.pdf` (line-up list, from the Wayback Machine) | 551 |
| Fall 2025 | Sep 5–6, 2025 | `FALL-25-2-web.pdf` (252 pp) | 557 |
| Fall 2026 | Sep 4–5, 2026 | `Fall-26-Web-Catalog.pdf` (276 pp) | 551 |

The 2025 and 2026 dates come from the catalog covers (and match Robin Glenn's empty catalogs 2599 and 3030); 2022 from
Pitzer's homepage of Aug 2022 on the Wayback Machine ("2022 Fall Sale – Sept. 9 – 10").

Not read: `F24-Catalog-pdf-Final.pdf` and `S26-full-pdf-Catalog.pdf` (already loaded from Robin Glenn), and
`fall16catalog.pdf` (Wayback; a different InDesign layout with unnamed foals and prose pedigrees; it's kept in the
cache but not parsed).

## Layout

The catalogs are Robin Glenn QData pages, three lots to a page, set alternately left and right of a photo: "Hip No."
and the hip number, a "Consigned by" line, the name in large type, "April 7, 2026 Red Roan Wn Stud" (foaling date,
color, age class, sex; older horses give the year only, "2021 Bay Gelding"), a 3-generation chart, then "NOTES:".
Plain `pdftotext -layout` wraps the right-hand charts badly, so `PitzerParser` reads word positions
(`pdftotext -bbox`), joins words on the same baseline into cells, and groups the chart cells into columns by left
edge. A generation is used only with exactly 2, 4 and 8 names. The chart's left column repeats the horse's name and
sometimes prints its registration number (303 lots); that number is stored with registry AQHA.

Sex: a weanling or yearling "Stud" is a colt, as Robin Glenn codes Pitzer foals; "Broodmare" is a mare.

**Scanned pages (Fall 2026, hips 330–615).** These pages are scans with an Acrobat OCR text layer, and the OCR is
garbled ("Ova Drifly Peppy" for Dva Drifty Peppy). They're found by font: Robin Glenn pages embed subset Arial
(`AAAAAA+ArialMT`), OCR pages don't. On a scanned page a lot is kept only when the name reads the same in both places
it's printed (the heading and the chart's left column); its chart and registration number are dropped. 223 lots kept
this way; about 60 were dropped because the two readings disagreed.

**Fall 2022 line-up** (an Excel printout): lot, name, foaling year, color and sex. No pedigree, so those lots stay
unmatched unless a later source supplies more.

**Unnamed foals.** Most weanlings are listed as sire's initials "x" dam ("MB x Jessie Jo Jackie"; in 2022, dam "x"
sire's initials). These labels aren't registered names: the lot is stored with the label, but no record is made from
it (registration number only, and there is none). Barn names in quotes ("“Blazer”") are treated the same way.

Hips that aren't printed in the catalog (scratched, or only in the index) aren't loaded.

## Loading

Each lot is a `sale_lots` row of DataSource `pitzer`, external id `pitzer:<season-year>:<hip>`, sale name
"Pitzer Ranch Fall Sale 2025" (Robin Glenn's naming for the other Pitzer sales), status `offered` (no prices: Pitzer
posts only sale averages, as images). Each named lot is placed with `RegistrationOnlyMatch::place($lot, 'Pitzer Ranch')`:
the registration number first, then the registered-name rule, which can create an Identity-unverified record.

First local load (Oct 23 2026): 1,659 lots; 882 with a pedigree, 303 with a registration number, 223 from scanned
pages, 1,015 unnamed-foal labels. Placement: 5 matched on registration number, 201 records created, 37 proposed to
staff, 53 queued, 1,363 unmatched. A rerun changes nothing.

Never kept: consignor names, phone numbers, NOTES text, agents and photos (the cached PDFs still print them).

PDFs are cached in `storage/app/private/pitzer/pdf/`. One request at a time, at least 3 s apart, as HorsefaxBot; a
403/429 or a bot check stops the run. An archived PDF must end with `%%EOF` (first captures are often cut at 1 MB).

```bash
php artisan hf:harvest-pitzer
```

```bash
php artisan hf:harvest-pitzer --offline
```

`--no-load` only downloads; `--dir=` uses another cache folder.
