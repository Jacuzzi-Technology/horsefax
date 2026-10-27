<?php

/*
 * Horsefax plans and what each includes (Richard, Oct 27 2026). Everyone is on Free and nothing is charged yet.
 * Pages ask App\Support\Plans::allows($user, 'feature') rather than checking plan names, so turning on a paid
 * tier later is a change here (plus billing), not a rewrite.
 *
 * Free includes everything the site does today. The paid plans are placeholders: names, prices and contents are
 * TODO (Richard). Staff can put an account on one by hand (comp or test); nobody can buy one.
 *
 * Limits: null = unlimited.
 */
return [
    'default' => 'free',

    'features' => [
        // What the site does today (all on Free)
        'search' => 'Search horses',
        'create_records' => 'Create and look after horse records',
        'share_reports' => 'Share a horse\'s report by private link',
        'view_public_records' => 'View public horse records',
        'invite_professionals' => 'Invite vets, farriers and trainers to contribute',
        // Candidates for paid tiers later (not used by any page yet)
        'full_history_reports' => 'Full history reports on horses you don\'t own (sales, results, records)',
        'report_downloads' => 'Download reports as PDF',
        'saved_searches' => 'Saved searches and alerts',
        'api_access' => 'Data access by API (partners)',
    ],

    'plans' => [
        'free' => [
            'name' => 'Free',
            'public' => true,
            'price' => null,
            'features' => ['search', 'create_records', 'share_reports', 'view_public_records', 'invite_professionals'],
            'limits' => ['history_reports_per_month' => null, 'saved_searches' => 0],
        ],
        // Placeholders: TODO (Richard) names, prices, contents.
        'pro' => [
            'name' => 'Pro (placeholder)',
            'public' => false,
            'price' => null,
            'features' => ['search', 'create_records', 'share_reports', 'view_public_records', 'invite_professionals', 'full_history_reports', 'report_downloads', 'saved_searches'],
            'limits' => ['history_reports_per_month' => null, 'saved_searches' => 25],
        ],
        'business' => [
            'name' => 'Business (placeholder)',
            'public' => false,
            'price' => null,
            'features' => ['search', 'create_records', 'share_reports', 'view_public_records', 'invite_professionals', 'full_history_reports', 'report_downloads', 'saved_searches', 'api_access'],
            'limits' => ['history_reports_per_month' => null, 'saved_searches' => null],
        ],
    ],
];
