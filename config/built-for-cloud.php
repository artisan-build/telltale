<?php

declare(strict_types=1);

use App\Http\Controllers\TelltaleDashboard;

return [
    'manifest' => [
        'name' => 'Telltale',
        'slug' => 'telltale',
        'description' => 'Self-hosted NativePHP product analytics and PHP error reporting. Native crashes are not captured in v1.',
        'icon' => 'https://scalpels.app/img/products/transparent/telltale.png',
        'product_url' => 'https://scalpels.app/products/telltale',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [],
    ],

    'dashboard' => TelltaleDashboard::class,

    'ui' => [
        'landing_page' => true,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [],
    ],

    'dashboard_prompts' => [
        [
            'title' => 'Who is using my apps?',
            'ask' => 'For each of my Telltale apps, report daily active users over the last 30 days and how adoption of the newest release is going.',
        ],
        [
            'title' => 'What is breaking?',
            'ask' => 'List the errors with the most occurrences this week across my apps, then pull the detail for the worst one and tell me what it points to.',
        ],
        [
            'title' => 'How is the latest release doing?',
            'ask' => 'Compare the newest release of each app with the one before it: adoption, error volume and anything that got worse.',
        ],
        [
            'title' => 'Where do people drop off?',
            'ask' => 'Look at screen flow and funnels for my busiest app and tell me where people most often stop.',
        ],
        [
            'title' => 'Is ingest healthy?',
            'ask' => 'Check ingest health for every app: rejections, rate-limit hits and client-reported drops, and flag anything unusual.',
        ],
    ],
];
