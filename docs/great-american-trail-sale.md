# Great American Ranch & Trail Horse Sale (Turnbull Equine Productions)

Annual ranch and trail horse sale, Lexington VA. greatamericantrailhorsesale.com: robots.txt allows all with
Crawl-delay 60 (one request a minute), no terms of use beyond a copyright line, privacy policy and conditions of sale.

`TurnbullHarvester`: `collect()` downloads the catalog PDFs listed in `CATALOGS` (2016-2024 on the site, found through the
Internet Archive's copies of the catalog page; 2025-2026 from the Heyzine flipbooks, whose owner enabled download) and the
sale page with the High Sellers list; `load()` reads them.

Only 2025 and 2026 have a text layer. 2018-2024 are scanned pages (OCR not built) and 2022's file is damaged; 2016's link
now returns a web page. Each hip: hip number, registered name, "2022 AQHA Grey Roan Gelding", a three-generation tree read
with `LolliParser::pedigree`. Prices only for the High Sellers (the year's top 10, one top seller for earlier years),
attached when hip and name agree. Consignors, descriptions and contacts are never stored.

Oct 28 2026 (local): 138 lots, 82 pedigrees, 7 prices, 77 new unverified records.
