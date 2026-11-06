<?php

use App\Http\Controllers\TelltaleDashboard;
use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('owns only the Telltale application configuration overlay', function (): void {
    /** @var array<string, mixed> $appConfig */
    $appConfig = require config_path('built-for-cloud.php');

    expect($appConfig)->toBe([
        'manifest' => [
            'name' => 'Telltale',
            'slug' => 'telltale',
            'description' => 'Self-hosted NativePHP product analytics and PHP error reporting. Native crashes are not captured in v1.',
            'icon' => 'https://scalpels.app/img/products/transparent/telltale.png',
            'product_url' => 'https://scalpels.app/products/telltale',
        ],
        'credentials' => [
            'guard' => 'bfc',
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
    ]);
});

it('merges package defaults and auto-discovers the released provider', function (): void {
    expect(config('built-for-cloud.manifest'))->toBe([
        'name' => 'Telltale',
        'slug' => 'telltale',
        'description' => 'Self-hosted NativePHP product analytics and PHP error reporting. Native crashes are not captured in v1.',
        'icon' => 'https://scalpels.app/img/products/transparent/telltale.png',
        'product_url' => 'https://scalpels.app/products/telltale',
    ])->and(config('built-for-cloud.ui'))->toBe([
        'landing_page' => true,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [],
    ])->and(config('built-for-cloud.dashboard'))->toBe(TelltaleDashboard::class)
        ->and(config('built-for-cloud.product'))->toBe(config('app.name'))
        ->and(config('built-for-cloud.credentials.guard'))->toBe('bfc')
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true);
});

it('runs fresh package-owned migrations on PostgreSQL', function (): void {
    expect(Artisan::call('migrate:fresh', [
        '--database' => 'pgsql',
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('bfc_authority'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'normalized_email'))->toBeTrue()
        ->and(glob(database_path('migrations/*users*')) ?: [])->toBe([])
        ->and(class_exists('App\\Models\\User'))->toBeFalse();
});
