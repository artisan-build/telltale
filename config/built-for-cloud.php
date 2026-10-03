<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Telltale',
        'slug' => 'telltale',
        'description' => 'Self-hosted product analytics and error reporting for NativePHP apps.',
        'icon' => 'https://scalpels.app/img/products/transparent/telltale.png',
        'product_url' => 'https://scalpels.app/products/telltale',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [],
    ],

    'ui' => [
        'landing_page' => false,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [],
    ],
];
