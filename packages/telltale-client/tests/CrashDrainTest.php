<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function queueDrainCrash(string $identity): void
{
    app(TelltaleClient::class)->importCrash(new CrashDetails(
        reportId: hash('sha256', $identity),
        platform: 'ios',
        kind: 'crash',
        source: 'metrickit_diagnostic',
        reason: 'signal',
        fatal: true,
        occurredAt: '2026-10-29T00:00:00Z',
        timestampPrecision: 'exact',
        occurrenceCount: 1,
        trace: str_repeat('frame ', 100),
    ));
}

it('selects a prefix bounded by complete uncompressed JSON and upgrades only crash batches to v2', function (): void {
    $client = app(TelltaleClient::class);
    $database = app(ClientDatabase::class);
    $database->storeToken('test-token');
    $client->event('ordinary');
    queueDrainCrash('one');
    queueDrainCrash('two');
    config()->set('telltale.batch_max_bytes', 1600);
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        $json = gzdecode($request->body());
        expect(strlen($json))->toBeLessThanOrEqual(1600);
        $sent[] = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return Http::response([], 202);
    });
    for ($attempt = 0; $attempt < 6 && $database->outboxCount() > 0; $attempt++) {
        expect($client->drain()->successful)->toBeTrue();
    }
    expect($database->outboxCount())->toBe(0)
        ->and($database->droppedEventsTotal())->toBe(0)
        ->and(count($sent))->toBeGreaterThan(1);
    $ids = [];
    foreach ($sent as $envelope) {
        $crashes = array_filter($envelope['events'], fn (array $event): bool => $event['type'] === 'crash');
        expect($envelope['envelope_version'])->toBe($crashes === [] ? 1 : 2);
        $ids = [...$ids, ...array_column($envelope['events'], 'event_id')];
    }
    expect(count($ids))->toBe(5)->and(count(array_unique($ids)))->toBe(5);
});

it('drops a single oversized row with accounting and sends the next row', function (): void {
    $database = app(ClientDatabase::class);
    $database->storeToken('test-token');
    queueDrainCrash('large');
    app(TelltaleClient::class)->event('small');
    config()->set('telltale.batch_max_bytes', 1000);
    $payload = null;
    Http::fake(function (Request $request) use (&$payload) {
        $payload = json_decode(gzdecode($request->body()), true, flags: JSON_THROW_ON_ERROR);

        return Http::response([], 202);
    });
    expect(app(TelltaleClient::class)->drain()->successful)->toBeTrue()
        ->and($database->droppedEventsTotal())->toBe(1)
        ->and($database->outboxCount())->toBe(0)
        ->and($payload['dropped_events_total'])->toBe(1)
        ->and(array_column($payload['events'], 'name'))->toBe(['session_start', 'context', 'small']);
});

it('persists a reduced batch after 413 and never acknowledges the unsent suffix', function (): void {
    $database = app(ClientDatabase::class);
    $database->storeToken('test-token');
    foreach (['one', 'two', 'three', 'four'] as $identity) {
        queueDrainCrash($identity);
    }
    $sizes = [];
    Http::fake(function (Request $request) use (&$sizes) {
        $payload = json_decode(gzdecode($request->body()), true, flags: JSON_THROW_ON_ERROR);
        $sizes[] = count($payload['events']);

        return Http::response([], count($sizes) === 1 ? 413 : 202);
    });
    $client = app(TelltaleClient::class);
    expect($client->drain()->acknowledged)->toBe(0)->and($database->outboxCount())->toBe(4);
    expect($client->drain()->acknowledged)->toBe(2)->and($database->outboxCount())->toBe(2);
    expect($client->drain()->acknowledged)->toBe(2)->and($database->outboxCount())->toBe(0)
        ->and($sizes)->toBe([4, 2, 2]);
});

it('accounts a server rejected single row and leaves later rows available', function (): void {
    $database = app(ClientDatabase::class);
    $database->storeToken('test-token');
    config()->set('telltale.batch_size', 1);
    queueDrainCrash('bad');
    queueDrainCrash('good');
    Http::fakeSequence()->push([], 413)->push([], 202);
    $client = app(TelltaleClient::class);
    expect($client->drain()->acknowledged)->toBe(0)
        ->and($database->outboxCount())->toBe(1)
        ->and($database->droppedEventsTotal())->toBe(1);
    expect($client->drain()->acknowledged)->toBe(1)->and($database->outboxCount())->toBe(0);
});

it('keeps crash rows offline with existing backoff and stays inert without a URL', function (bool $configured): void {
    $database = app(ClientDatabase::class);
    queueDrainCrash('offline');
    if ($configured) {
        $database->storeToken('test-token');
    } else {
        config()->set('telltale.url', null);
    }
    Http::fake(fn () => throw new ConnectionException('offline'));
    $result = app(TelltaleClient::class)->drain();
    expect($result->successful)->toBeFalse()
        ->and($result->retryAfterSeconds)->toBe(2)
        ->and($database->outboxCount())->toBe(1);
    if (! $configured) {
        Http::assertNothingSent();
    }
})->with([true, false]);
