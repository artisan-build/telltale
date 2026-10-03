<?php

declare(strict_types=1);

use App\Domain\Analytics\ErrorGroupQuery;
use App\Domain\Ingest\EventIngestor;
use App\Enums\AggregateDimension;
use App\Models\DailyActiveInstall;
use App\Models\DailyAggregate;
use App\Models\DailyNewInstall;
use App\Models\ErrorGroup;
use App\Models\ErrorGroupInstall;
use App\Models\StoredEvent;
use App\Models\TrackedSession;
use ArtisanBuild\TelltaleContracts\EnvelopeV1;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('uses the strict inactivity boundary and projects a replay exactly once', function (): void {
    [$app, $ingestValue] = storageApp();
    [, $token] = storageInstall($app, $ingestValue);
    $payload = storageEnvelope([
        storageEvent('first', 'event', '2026-10-01T00:00:00.000Z', 'untrusted-a'),
        storageEvent('boundary', 'event', '2026-10-01T00:30:00.000Z', 'untrusted-b'),
        storageEvent('after-boundary', 'event', '2026-10-01T01:00:00.001Z', 'untrusted-c'),
    ]);

    $this->withToken($token)->postJson('/api/ingest', $payload)
        ->assertAccepted()
        ->assertJson(['accepted' => 3, 'duplicates' => 0]);
    $this->withToken($token)->postJson('/api/ingest', $payload)
        ->assertAccepted()
        ->assertJson(['accepted' => 0, 'duplicates' => 3]);

    expect(TrackedSession::query()->orderBy('started_at')->pluck('event_count')->all())->toBe([2, 1])
        ->and(StoredEvent::query()->count())->toBe(3)
        ->and(StoredEvent::query()->whereNull('server_session_id')->count())->toBe(0)
        ->and((int) DailyAggregate::query()->where('dimension', AggregateDimension::EventName)->sum('event_count'))->toBe(3)
        ->and(DailyActiveInstall::query()->count())->toBe(1)
        ->and(DailyNewInstall::query()->count())->toBe(1);
});

it('rolls back the raw event when a projection write fails', function (): void {
    [$app, $ingestValue] = storageApp();
    [$install, $token] = storageInstall($app, $ingestValue);
    $envelope = EnvelopeV1::fromArray(storageEnvelope([
        storageEvent('will-roll-back', 'event', '2026-10-01T00:00:00Z', 'rollback-session'),
    ]));
    DB::statement("ALTER TABLE daily_aggregates ADD CONSTRAINT test_reject_projection CHECK (dimension <> 'event_name')");

    expect(fn () => resolve(EventIngestor::class)->ingest($install, $token, $envelope))
        ->toThrow(QueryException::class)
        ->and(StoredEvent::query()->count())->toBe(0)
        ->and(TrackedSession::query()->count())->toBe(0)
        ->and(DailyAggregate::query()->count())->toBe(0)
        ->and(DailyActiveInstall::query()->count())->toBe(0)
        ->and(DailyNewInstall::query()->count())->toBe(0);
});

it('merges sessions when an out-of-order event bridges two exact boundaries', function (): void {
    [$app, $ingestValue] = storageApp();
    [, $token] = storageInstall($app, $ingestValue);

    foreach ([
        storageEvent('early', 'event', '2026-10-01T00:00:00Z', 'session-a'),
        storageEvent('late', 'event', '2026-10-01T01:00:00Z', 'session-b'),
        storageEvent('bridge', 'event', '2026-10-01T00:30:00Z', 'session-forged-differently'),
    ] as $event) {
        $this->withToken($token)->postJson('/api/ingest', storageEnvelope([$event]))->assertAccepted();
    }

    $session = TrackedSession::query()->sole();

    expect($session->event_count)->toBe(3)
        ->and($session->started_at->toIso8601String())->toBe('2026-10-01T00:00:00+00:00')
        ->and($session->ended_at->toIso8601String())->toBe('2026-10-01T01:00:00+00:00')
        ->and(StoredEvent::query()->distinct()->pluck('server_session_id')->all())->toBe([$session->id]);
});

it('uses the configured server inactivity period', function (): void {
    config(['telltale.storage.session_inactivity_minutes' => 15]);
    [$app, $ingestValue] = storageApp();
    [, $token] = storageInstall($app, $ingestValue);

    $this->withToken($token)->postJson('/api/ingest', storageEnvelope([
        storageEvent('first', 'event', '2026-10-01T00:00:00Z', 'session-a'),
        storageEvent('second', 'event', '2026-10-01T00:16:00Z', 'session-a'),
    ]))->assertAccepted();

    expect(TrackedSession::query()->count())->toBe(2);
});

it('maintains exact daily dimensions and active and new install facts', function (): void {
    [$app, $ingestValue] = storageApp();
    [, $firstToken] = storageInstall($app, $ingestValue);
    [, $secondToken] = storageInstall($app, $ingestValue);
    $firstPayload = storageEnvelope([
        storageEvent('session.context', 'context', '2026-10-02T10:00:00Z', 'session-1', [
            'props' => ['app_version' => '2.1.0', 'platform' => 'mobile', 'os' => 'iOS'],
        ]),
        storageEvent('home', 'screen', '2026-10-02T10:00:01Z', 'session-1'),
        storageEvent('settings', 'screen', '2026-10-02T10:00:02Z', 'session-1'),
    ]);

    $this->withToken($firstToken)->postJson('/api/ingest', $firstPayload)->assertAccepted();
    $this->withToken($firstToken)->postJson('/api/ingest', $firstPayload)->assertAccepted();
    $this->withToken($secondToken)->postJson('/api/ingest', storageEnvelope([
        storageEvent('session.context', 'context', '2026-10-02T11:00:00Z', 'session-2', [
            'props' => ['app_version' => '3.0.0', 'platform' => 'desktop', 'platform_name' => 'macOS'],
        ]),
        storageEvent('home', 'screen', '2026-10-02T11:00:01Z', 'session-2'),
    ]))->assertAccepted();

    $count = fn (AggregateDimension $dimension, string $value): int => (int) DailyAggregate::query()
        ->where('app_id', $app->id)
        ->where('aggregate_date', '2026-10-02')
        ->where('dimension', $dimension)
        ->where('dimension_value', $value)
        ->value('event_count');

    expect($count(AggregateDimension::EventName, 'home'))->toBe(2)
        ->and($count(AggregateDimension::Screen, 'home'))->toBe(2)
        ->and($count(AggregateDimension::Screen, 'settings'))->toBe(1)
        ->and($count(AggregateDimension::Version, '2.1.0'))->toBe(3)
        ->and($count(AggregateDimension::Version, '3.0.0'))->toBe(2)
        ->and($count(AggregateDimension::Platform, 'mobile'))->toBe(3)
        ->and($count(AggregateDimension::OperatingSystem, 'macOS'))->toBe(2)
        ->and(DailyActiveInstall::query()->where('active_date', '2026-10-02')->count())->toBe(2)
        ->and(DailyNewInstall::query()->where('app_version', '2.1.0')->count())->toBe(1)
        ->and(DailyNewInstall::query()->where('app_version', '3.0.0')->count())->toBe(1);
});

it('groups equivalent errors across installs with bounded samples and new-since semantics', function (): void {
    [$app, $ingestValue] = storageApp();
    [, $firstToken] = storageInstall($app, $ingestValue);
    [, $secondToken] = storageInstall($app, $ingestValue);
    $error = [
        'class' => 'RuntimeException',
        'message' => 'Payment failed for [redacted]',
        'file' => '/app/Checkout.php',
        'line' => 42,
        'stack' => ['Checkout::pay'],
        'fingerprint' => 'checkout-pay-v1',
    ];

    foreach ([[$firstToken, 'error-session-1'], [$secondToken, 'error-session-2']] as [$token, $session]) {
        $this->withToken($token)->postJson('/api/ingest', storageEnvelope([
            storageEvent('session.context', 'context', '2026-10-02T12:00:00Z', $session, [
                'props' => ['app_version' => '2.0.0', 'platform' => 'desktop', 'platform_name' => 'macOS'],
            ]),
            storageEvent('error.reported', 'error', '2026-10-02T12:00:01Z', $session, ['error' => $error]),
        ]))->assertAccepted();
    }

    $group = ErrorGroup::query()->sole();

    expect($group->total_occurrences)->toBe(2)
        ->and($group->installs_affected)->toBe(2)
        ->and($group->first_version)->toBe('2.0.0')
        ->and($group->last_version)->toBe('2.0.0')
        ->and($group->sample_error)->not->toHaveKey('fingerprint')
        ->and(ErrorGroupInstall::query()->count())->toBe(2)
        ->and(StoredEvent::query()->whereNotNull('error_group_id')->distinct()->pluck('error_group_id')->all())->toBe([$group->id])
        ->and(resolve(ErrorGroupQuery::class)->newSinceVersion($app, '1.9.0'))->toHaveCount(1)
        ->and(resolve(ErrorGroupQuery::class)->newSinceVersion($app, '2.0.0'))->toBe([]);
});

it('derives the same stable fallback fingerprint from bounded error fields', function (): void {
    [$app, $ingestValue] = storageApp();
    [, $firstToken] = storageInstall($app, $ingestValue);
    [, $secondToken] = storageInstall($app, $ingestValue);

    foreach ([[$firstToken, 'first message'], [$secondToken, 'different instance message']] as [$token, $message]) {
        $this->withToken($token)->postJson('/api/ingest', storageEnvelope([
            storageEvent('error.reported', 'error', '2026-10-02T12:00:01Z', (string) Str::uuid(), ['error' => [
                'class' => 'LogicException',
                'message' => $message,
                'file' => '/app/Action.php',
                'line' => 17,
                'stack' => ['Action::run'],
            ]]),
        ]))->assertAccepted();
    }

    expect(ErrorGroup::query()->sole()->installs_affected)->toBe(2);
});
