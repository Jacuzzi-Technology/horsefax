<?php

return [
    'operator' => 'Horsefax, LLC',
    'contact_email' => env('HORSEFAX_CONTACT_EMAIL'),
    'notify_email' => env('HORSEFAX_NOTIFY_EMAIL'),
    'staff_requires_two_factor' => env('HORSEFAX_STAFF_2FA', true),
    // Which published sale results go onto public records (launch order, Oct 17 2026: reported auction sales first;
    // recorded high bids and no-sales are matched but held until added here). Kinds: reported_sale, high_bid, no_sale,
    // asking, offered ("Offered at auction": catalogued, no price published; not a price, so shown from the start).
    // Headless Chrome for one-click PDF downloads (Chrome for Testing's chrome-headless-shell, installed in /opt).
    'chrome' => env('HORSEFAX_CHROME', '/opt/chrome-headless-shell/chrome-headless-shell'),
    // Where radiograph files live: the server's private disk for now; set to a DigitalOcean Spaces (s3) disk later.
    'radiographs_disk' => env('HORSEFAX_RADIOGRAPHS_DISK', 'local'),
    // Google Drive API key (Drive API only), for show offices that publish results in public Drive folders.
    'google_drive_key' => env('GOOGLE_DRIVE_API_KEY'),
    'published_sale_kinds' => array_filter(explode(',', env('HORSEFAX_PUBLISHED_SALE_KINDS', 'reported_sale,offered'))),
    // Search-backed review suggestions (Richard, Oct 19 2026). Brave Search API: its results are used only to find
    // pages and are never stored (the Search plan carries no storage rights). Pages are fetched only from the sources
    // below, each checked ALLOWED (robots + terms) in the source catalog, as HorsefaxBot, 3 s apart per site, robots.txt
    // obeyed, with a gzipped evidence copy. Facebook and AQHA are only ever linked for staff, never fetched (Facebook's
    // terms; AQHA needs a license). Everything else found is ignored.
    'search' => [
        'brave_key' => env('BRAVE_SEARCH_API_KEY'),
        'cost_per_search_cents' => (float) env('BRAVE_SEARCH_CENTS', 0.5), // Search plan: $5 per 1,000 (Oct 2026)
        'delay' => 3,
        'max_pages_per_item' => 5,
        'fetch_hosts' => [
            'bid.live-ag.com', 'wsqharesults.com', 'premierhorsesales.com', 'platinumequineauction.com', 'billingslivestock.com',
            'nrha.com', 'results.nrcha.com', 'supersires.org', 'highpointperformance.com', 'nebraskarha.org',
            'quarterhorsecongress.com', 'horseofmydreams.com',
        ],
    ],
];
