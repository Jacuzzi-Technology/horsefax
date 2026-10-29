# Triangle Horse Sales catalogs

trianglehorsesales.net (Shawnee, Oklahoma; Winter, Spring and Fall sales). Checked Oct 22 2026: WordPress/Yoast
robots.txt allows everything but one plugin file, the only terms posted cover marketing emails and texts, and there is
no bot wall. Collected under Richard's standing approval.

- **Finding catalogs:** the site's own media list (`/wp-json/wp/v2/media?mime_type=application/pdf`). For each sale
  the newest version (`_v2`, `_v3`, `Final`) whose text layer reads is used; an older version is tried when the
  newest has scrambled fonts. "Readable" means the catalog reader gets at least 20 hips from it.
- **Reading:** the catalogs are QData-made, so the Heritage/QData reader is reused (`HeritageParser::catalog`,
  `HeritageHarvester::bestPerHip`): hip, name, AQHA number, foal date, color, sex, 3-generation pedigree.
- **Sale day:** a dated cover line ("OCTOBER 30-31, 2026", "JANUARY 25, 26 & 27, 2024"), else the weekday + date the
  schedule pages mention most; it must fall in the sale's season (covers also advertise the next sale).
- **No double counting with Robin Glenn:** a lot whose hip and name match a Robin Glenn lot within three days is
  skipped, and a whole sale is skipped when most of its hips match (its few unmatched hips are spelling or reading
  differences). On Oct 22 2026 the local Robin Glenn import already held Spring 2023, Winter 2024, Fall 2024,
  Spring 2025, Fall 2025 and Spring 2026, so only Winter 2026 and Fall 2026 load from Triangle.
- **Scrambled catalogs (Fall 2023, Winter 2025):** every version was exported with scrambled fonts, so `--ocr` reads
  them with OCR (`pdftoppm` at 200 dpi + `tesseract --psm 4`, one TSV per page in `storage/app/private/triangle/ocr/`,
  only pages not yet done). Only the hip header is read (hip, name, foal date, color, sex, registration number); the
  pedigree chart OCRs too noisily and isn't used.
  Both sales are already in Robin Glenn (from the same QData catalogs, but without registration numbers or foal
  years), so no lot is loaded again. Instead each OCR'd hip whose name agrees with its Robin Glenn lot (marks like
  "(P)"/"(Grade)" dropped; one character's slip allowed on names of 8+ letters) fills what that lot's record is missing
  through `AddPublishedIdentity` (never over existing facts, never on an owner's record; a number already on another
  record goes to staff). Numbers are checked first (`checkedNumber`): a number needs OCR confidence of 90 or more and
  seven digits; a Paint mark on either name makes it APHA; a grade horse gets no number; an AQHA number under 2,000,000
  or more than 300,000 from its foal year's typical number is dropped.
  Oct 23 2026 (local): 443 hips OCR'd, 324 registration numbers and 369 foal years added to existing records.
- Spring 2024 isn't on the site or in the Wayback Machine.
- **Results:** Triangle publishes no per-hip prices. Its online results are on CCI.live, whose terms forbid copying,
  so they are never fetched. Past-sale lots are "offered".

Facts only: NOTES text, consignors and phone numbers are never kept (the kept PDFs still print them). Large PDFs need
`php -d memory_limit=2G`.

```bash
php -d memory_limit=2G artisan hf:harvest-triangle
```

```bash
php -d memory_limit=2G artisan hf:harvest-triangle --offline
```

```bash
php -d memory_limit=2G artisan hf:harvest-triangle --offline --ocr
```
