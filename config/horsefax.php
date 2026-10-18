<?php

return [
    'operator' => 'Horsefax, LLC',
    'contact_email' => env('HORSEFAX_CONTACT_EMAIL'),
    'notify_email' => env('HORSEFAX_NOTIFY_EMAIL'),
    'staff_requires_two_factor' => env('HORSEFAX_STAFF_2FA', true),
    // Which published sale results go onto public records (launch order, Oct 17 2026: reported auction sales first;
    // recorded high bids and no-sales are matched but held until added here). Kinds: reported_sale, high_bid, no_sale.
    'published_sale_kinds' => array_filter(explode(',', env('HORSEFAX_PUBLISHED_SALE_KINDS', 'reported_sale'))),
];
