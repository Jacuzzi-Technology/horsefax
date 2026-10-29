# Buckeye Classic Yearling Sale (Standardbred)

Ohio-sired Standardbred yearlings, sold each October in Springfield, OH (Hoosier Classic Sale Company).
buckeyeclassicsale.com has no robots.txt, no terms of use and no bot wall (checked Oct 28 2026).

`BuckeyeClassicHarvester::run()` reads one results page per year since 2015 (`2025_sale_results.asp`, 25 hips a page
with `?offset=` links on most years): hip, name, sex and color, sire and dam, price, or "Out" (withdrawn). Every horse is
a yearling, so its foal year is the sale year minus one. Consignors and buyers are never stored. Pages are kept as
harvest pages, so a rerun reads them from storage.

Oct 28 2026 (local): 2,895 lots 2015-2026, 2,742 with prices, 2,045 new unverified records.

## Hip pages (2020 on)

`php artisan hf:harvest-buckeye-classic --hips` reads each 2020+ lot's catalog page (`hips/2026/1.pdf`, kept under
`storage/app/private/buckeye/hips/`): foaling date, the USTA identifier ("Registration #62R55"; "Freeze Brand #" in 2020),
the microchip, and the 3-generation tree (race marks cut off the names). Used only when the page names the same horse.
The record gets what it's missing (AddPublishedIdentity). Hip pages before 2020 are gone (404).

Microchips (Richard, Oct 28 2026: "store microchips as catalog-printed"): kind `microchip`, registry "Sale catalog", so the
record shows "Microchip (Sale catalog)"; unverified, source historical, private and masked like every microchip, and
never added to a record someone looks after. A chip already on another record goes to staff as a conflict.

## Hoosier Classic

`--site=hoosier`: the same company's Indiana-sired yearling sale (hoosierclassicyearlingsale.com, same site software),
results 2013-2024 (2010-2012 are PDFs, not read). Two sale days: each hip gets its day from "Friday, October 18:
Selling Hips 1-175". Colors and sexes are abbreviated ("b.f." bay filly). In 2025 the sale became the Midwest
Standardbred Horse Sale under new owners (not checked).
