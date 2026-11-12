# API Équidés import (parents and breeders for French records)

`php artisan hf:import-apiequides <file>` adds sire, dam and breeder to the French (IFCE) records that the IFCE
open-data import created (source `ifce`, about 4.2 million on production). That file had no parents.

## The source

**API Équidés** (api-equides.org), listed on data.gouv.fr as a dataservice. It is an independent mirror of IFCE's
public horse pages on infochevaux.ifce.fr, run by the Collectif Pixel association (developer Ugo Roserat,
roserat.ugo@gmail.com). It is **not IFCE**. Kate found it; Richard approved it on Oct 27 2026.

- Open: no key or account; no robots.txt on the API host; rate limit 50 units/s.
- For bulk use the docs say to download the whole file, not page through: `/v1/extractions/derniere` redirects to the
  current extraction. We downloaded it once.
- Reuse: Licence Ouverte / CRPA L321-1. Credit the source and date: "IFCE public horse records
  (infochevaux.ifce.fr), extraction of 16 Aug 2026, via API Équidés (independent service, Collectif Pixel)"
  (`ApiEquidesImporter::CREDIT`).
- Horsefax does not crawl infochevaux itself (Imperva bot protection).

File kept: `storage/app/private/harvest/apiequides/chevaux-20260816.jsonl.zst` (298,258,966 bytes, blake3
`b7bac048…2f12`). It holds 4,446,638 horses: 3,238,133 have a sire or dam and 2,626,425 name a breeder
(`naisseur`). Each line also has performance indexes and breeding status, which are not loaded yet.

## How it loads

1. The file streams (through `zstd -dc` when compressed) into a temporary table. Donkeys, mules, hinnies and zebras
   are left out ("Poitevin Mulassier" is a horse breed and is kept).
2. Each row is matched to a French record by **IFCE id** (`horses.ifce_id`, set on the first run), else on **exact
   name, foal year and sex** when exactly one record and one row share them.
3. **New horses** (Richard, Oct 27 2026: get what we missed): a row with no French record of that name and sex (same
   foal year, or either unknown) becomes a record, source `ifce`, with its IFCE id. Mostly ancestors, imports and
   horses newer than the open-data file. Other unmatched rows are left alone.
4. Breeder fills `horses.breeder` where it is blank. IFCE records show it through `Views::facts`.
5. Pedigree: sire and dam lines, each **linked** to the parent's own record, so the chart builds four generations by
   following the links (`Views::pedigreeChart`). The dam's sire is stored too when the dam has no record. Records
   that already have a pedigree keep it.

Rerunnable: a second run adds nothing. `--dry-run` only counts.

    php -d memory_limit=1G artisan hf:import-apiequides storage/app/private/harvest/apiequides/chevaux-20260816.jsonl.zst --dry-run

Not loaded yet: performance indexes, breeding status, race records.

## The regular check: `hf:check-france`

Weekly (production crontab). It reads the data.gouv.fr dataset and `/v1/extractions`:

- every IFCE CSV edition not imported yet is downloaded and imported, newest first. That takes in the 2025, 2023,
  2018 and 2014 editions once (horses later editions dropped), then each new yearly edition. Their layouts differ
  (`;` or `,`, no header in 2014, Windows-1252); `IfceImporter` reads them all. Editions after the first add a horse
  only when no French record has the same name, foal date and sex, so corrected details never make duplicates;
- a new API Équidés extraction is downloaded once and loaded as above.

Production needs `zstd` (installed on trusted-core-01).
