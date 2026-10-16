<?php

return [
    'operator' => 'Horsefax, LLC',
    'contact_email' => env('HORSEFAX_CONTACT_EMAIL'),
    'notify_email' => env('HORSEFAX_NOTIFY_EMAIL'),
    'staff_requires_two_factor' => env('HORSEFAX_STAFF_2FA', true),
];
