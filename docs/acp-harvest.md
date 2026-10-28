# American Classic Pedigrees harvest

`php artisan hf:harvest-acp` reads the Thoroughbred profiles at americanclassicpedigrees.com, Avalyn Hunter's library
(about 3,300 pages): American Classic winners and their equivalents in Canada, Argentina, Brazil, Chile and Peru,
champions, Hall of Famers, champion sires and Broodmares of the Year, from the 1700s on. Richard found it and asked
for it built and loaded on Oct 27 2026.

## Why it's allowed (checked Oct 27 2026)

- robots.txt blocks only `/ajax/` and `/apps/`; profiles are open. Sitemap: `/sitemap.xml`.
- No terms of use. The footer, "© 2014–2026 by Avalyn Hunter. All rights reserved.", covers her writing:
  **facts only, never the prose**. Conformation and personality sections are opinions anyway (factual record policy).
- No bot wall (Weebly).

## What is loaded

| On the profile | In Horsefax |
|---|---|
| Name and country ("Shackleford (USA)") | registered name; "(USA)" dropped, other suffixes kept |
| "February 25, 2008 – Living" / "1864 – 1890" | foal date or year; Death entry (day or year) |
| "Forestry (USA) x Oatsee (USA), by Unbridled (USA)" | sire, dam, dam's sire (ancestors get records) |
| "A chestnut mare" (first line of *As an individual*), *As a stallion* / *As a producer* | color, sex |
| Race record summary ("20 starts, 6 wins … $3,090,101") | Achievements & earnings · Race record (earnings only when in dollars) |
| Results by year ("Won Preakness Stakes (USA-G1, 9.5FD, Pimlico)") | Competition · Race result (race, class, track, placing, year) |
| Honors ("American champion 3-year-old male (2011)") | Achievements & earnings · Title |
| Connections: "bred by …" | Breeder |
| The profile itself | In the news: "Profile of … by Avalyn Hunter", linked |

Breed is Thoroughbred. Records go through `ImportPublishedHorse` (unverified; same-named records with different parents
stay apart; a record someone owns is left alone). Not loaded: family number, sire rankings, notable progeny, all prose.

    php artisan hf:harvest-acp             # fetch profiles not kept yet, then load
    php artisan hf:harvest-acp --offline   # load kept profiles, no requests

HorsefaxBot, at least 3 s apart; a full fetch is about 3 hours. A one-person site: a courtesy note to the author is
Richard's call.
