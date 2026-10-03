<?php

declare(strict_types=1);

use App\Domain\Ingest\AppManager;
use App\Domain\Ingest\Credential;
use App\Models\StoredEvent;
use App\Models\TrackedApp;

it('creates apps with a one-time high-entropy ingest value and only a hash at rest', function (): void {
    $created = resolve(AppManager::class)->create('Dreiland');
    $app = $created->app();
    $ingestValue = $created->ingestValue();

    expect($ingestValue)->toMatch('/^tti_[a-f0-9]{64}$/')
        ->and(strlen($ingestValue))->toBeGreaterThanOrEqual(68)
        ->and($app->ingest_key_hash)->toBe(Credential::hash($ingestValue))
        ->and($app->ingest_key_hash)->not->toBe($ingestValue)
        ->and($app->toJson())->not->toContain($ingestValue)
        ->and($app->toJson())->not->toContain($app->ingest_key_hash)
        ->and(json_encode($created, JSON_THROW_ON_ERROR))->toBe('{}');
});

it('rotates an app ingest value and immediately rejects the old value', function (): void {
    $manager = resolve(AppManager::class);
    $created = $manager->create('Dreiland');
    $app = $created->app();
    $oldValue = $created->ingestValue();
    $newValue = $manager->rotateIngestKey($app);

    expect($newValue)->not->toBe($oldValue)
        ->and(TrackedApp::query()->findOrFail($app->id)->ingest_key_hash)->toBe(Credential::hash($newValue));

    $this->postJson('/api/register', ['install_id' => fake()->uuid()], [
        'X-Telltale-Ingest' => $oldValue,
    ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);

    $this->postJson('/api/register', ['install_id' => fake()->uuid()], [
        'X-Telltale-Ingest' => $newValue,
    ])->assertCreated()->assertJsonStructure(['install_token', 'token_type']);
});

it('stores configurable positive limits per app', function (): void {
    $app = resolve(AppManager::class)->create('Limited', [
        'rate_per_minute' => 7,
        'install_rate_per_minute' => 3,
        'daily_event_cap' => 90,
        'install_daily_event_cap' => 20,
    ])->app();

    expect($app->rate_per_minute)->toBe(7)
        ->and($app->install_rate_per_minute)->toBe(3)
        ->and($app->daily_event_cap)->toBe(90)
        ->and($app->install_daily_event_cap)->toBe(20);
});

it('accepts app names at the storage boundary and rejects invalid names', function (): void {
    $manager = resolve(AppManager::class);

    expect($manager->create(str_repeat('a', AppManager::MAX_NAME_LENGTH))->app()->name)
        ->toHaveLength(AppManager::MAX_NAME_LENGTH);

    expect(fn () => $manager->create(str_repeat('a', AppManager::MAX_NAME_LENGTH + 1)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $manager->create("invalid\0name"))
        ->toThrow(InvalidArgumentException::class);
});

it('builds coherent app install and event fixtures', function (): void {
    $event = StoredEvent::factory()->create();

    expect($event->install->app_id)->toBe($event->app_id);
});
