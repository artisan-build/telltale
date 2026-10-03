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
];
