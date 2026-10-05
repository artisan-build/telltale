<?php

declare(strict_types=1);

use App\Models\DailyActiveInstall;
use App\Models\DailyAggregate;
use App\Models\DailyNewInstall;
use App\Models\ErrorGroup;
use App\Models\ErrorGroupInstall;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedSession;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

it('prunes expired raw events and deletes only the selected app install without changing aggregates', function (): void {
    Date::setTestNow('2026-10-03T12:00:00Z');
    [$firstApp, $firstIngest] = storageApp();
    [$secondApp, $secondIngest] = storageApp();
    $sharedUuid = (string) Str::uuid();
    [$firstInstall, $firstToken] = storageInstall($firstApp, $firstIngest, $sharedUuid);
    [$secondInstall, $secondToken] = storageInstall($secondApp, $secondIngest, $sharedUuid);
    [$remainingInstall, $remainingToken] = storageInstall($firstApp, $firstIngest);
    $error = [
        'class' => 'RuntimeException',
        'message' => 'Shared failure',
        'file' => '/app/Action.php',
        'line' => 10,
        'stack' => ['Action::run'],
        'fingerprint' => 'shared-delete-test',
    ];

    $this->withToken($firstToken)->postJson('/api/ingest', storageEnvelope([
        storageEvent('expired', 'event', '2026-06-01T00:00:00Z', 'old-session'),
        storageEvent('current', 'event', '2026-10-01T00:00:00Z', 'new-session'),
    ]))->assertAccepted();
    $this->withToken($secondToken)->postJson('/api/ingest', storageEnvelope([
        storageEvent('other-app', 'event', '2026-10-01T00:00:00Z', 'other-session'),
    ]))->assertAccepted();
    foreach ([[$firstToken, 'error-first'], [$remainingToken, 'error-remaining']] as [$token, $session]) {
        $this->withToken($token)->postJson('/api/ingest', storageEnvelope([
            storageEvent('session.context', 'context', '2026-10-02T00:00:00Z', $session, [
                'props' => ['app_version' => '2.0.0', 'platform' => 'desktop', 'platform_name' => 'macOS'],
            ]),
            storageEvent('error.reported', 'error', '2026-10-02T00:00:01Z', $session, ['error' => $error]),
        ]))->assertAccepted();
    }

    $aggregateSnapshot = [
        DailyAggregate::query()->count(),
        (int) DailyAggregate::query()->sum('event_count'),
        DailyActiveInstall::query()->count(),
        DailyNewInstall::query()->count(),
    ];

    expect(Artisan::call('telltale:prune-events', ['--days' => 90]))->toBe(0)
        ->and(StoredEvent::query()->where('install_id', $firstInstall->id)->oldest('occurred_at')->pluck('name')->all())->toBe([
            'current',
            'session.context',
            'error.reported',
        ])
        ->and(TrackedSession::query()->where('install_id', $firstInstall->id)->count())->toBe(3)
        ->and([
            DailyAggregate::query()->count(),
            (int) DailyAggregate::query()->sum('event_count'),
            DailyActiveInstall::query()->count(),
            DailyNewInstall::query()->count(),
        ])->toBe($aggregateSnapshot);

    expect(Artisan::call('telltale:delete-install', [
        'app' => $firstApp->id,
        'install' => $sharedUuid,
    ]))->toBe(0)
        ->and(Install::query()->whereKey($firstInstall->id)->exists())->toBeFalse()
        ->and(StoredEvent::query()->where('install_id', $firstInstall->id)->exists())->toBeFalse()
        ->and(Install::query()->whereKey($secondInstall->id)->exists())->toBeTrue()
        ->and(StoredEvent::query()->where('install_id', $secondInstall->id)->count())->toBe(1)
        ->and(Install::query()->whereKey($remainingInstall->id)->exists())->toBeTrue()
        ->and(ErrorGroup::query()->where('app_id', $firstApp->id)->sole()->total_occurrences)->toBe(1)
        ->and(ErrorGroup::query()->where('app_id', $firstApp->id)->sole()->installs_affected)->toBe(1)
        ->and(ErrorGroupInstall::query()->where('install_id', $firstInstall->id)->exists())->toBeFalse()
        ->and(ErrorGroupInstall::query()->where('install_id', $remainingInstall->id)->count())->toBe(1)
        ->and([
            DailyAggregate::query()->count(),
            (int) DailyAggregate::query()->sum('event_count'),
            DailyActiveInstall::query()->count(),
            DailyNewInstall::query()->count(),
        ])->toBe($aggregateSnapshot);

    $this->withToken($firstToken)->postJson('/api/ingest', storageEnvelope([
        storageEvent('rejected', 'event', '2026-10-03T12:00:00Z', 'deleted-install'),
    ]))->assertUnauthorized();
});

it('schedules the raw event prune daily so the configured retention window is enforced without anyone running it by hand', function (): void {
    $scheduled = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'telltale:prune-events'))
        ->values();

    expect($scheduled)->toHaveCount(1);

    $event = $scheduled->first();

    expect($event->expression)->toBe('10 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
