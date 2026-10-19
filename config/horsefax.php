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
    'published_sale_kinds' => array_filter(explode(',', env('HORSEFAX_PUBLISHED_SALE_KINDS', 'reported_sale,offered'))),
];
