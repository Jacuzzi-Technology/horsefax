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
- **Not read:** Fall 2023 and Winter 2025, whose every version was exported with scrambled fonts (would need OCR).
  Spring 2024 isn't on the site or in the Wayback Machine.
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
