# Buckeye Classic Yearling Sale (Standardbred)

Ohio-sired Standardbred yearlings, sold each October in Springfield, OH (Hoosier Classic Sale Company).
buckeyeclassicsale.com has no robots.txt, no terms of use and no bot wall (checked Oct 28 2026).

`BuckeyeClassicHarvester::run()` reads one results page per year since 2015 (`2025_sale_results.asp`, 25 hips a page
with `?offset=` links on most years): hip, name, sex and color, sire and dam, price, or "Out" (withdrawn). Every horse is
a yearling, so its foal year is the sale year minus one. Consignors and buyers are never stored. Pages are kept as
harvest pages, so a rerun reads them from storage.

Oct 28 2026 (local): 2,895 lots 2015-2026, 2,742 with prices, 2,045 new unverified records.

Not built yet: the per-hip catalog PDFs (`hips/2026/1.pdf`, the full pedigree) and the Hoosier Classic sale run by the
same company.
