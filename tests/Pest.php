<?php

use App\Domain\Ingest\AppManager;
use App\Models\Install;
use App\Models\TrackedApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/** @return array{TrackedApp, string} */
function storageApp(): array
{
    $created = resolve(AppManager::class)->create('Storage '.Str::random(8));

    return [$created->app(), $created->ingestValue()];
}

/** @return array{Install, string} */
function storageInstall(TrackedApp $app, string $ingestValue, ?string $uuid = null): array
{
    $response = test()->postJson('/api/register', [
        'install_id' => $uuid ?? (string) Str::uuid(),
    ], ['X-Telltale-Ingest' => $ingestValue])->assertSuccessful();

    return [Install::query()->where('app_id', $app->id)->latest('id')->firstOrFail(), $response->json('install_token')];
}

/** @return array<string, mixed> */
function storageEvent(string $name, string $type, string $timestamp, string $sessionId, array $overrides = []): array
{
    return array_replace([
        'event_id' => (string) Str::ulid(),
        'name' => $name,
        'type' => $type,
        'ts' => $timestamp,
        'session_id' => $sessionId,
        'props' => [],
    ], $overrides);
}

/** @param list<array<string, mixed>> $events */
function storageEnvelope(array $events): array
{
    return [
        'envelope_version' => 1,
        'client_version' => '1.0.0',
        'dropped_events_total' => 0,
        'events' => $events,
    ];
}
