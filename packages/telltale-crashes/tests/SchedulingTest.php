<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use ArtisanBuild\TelltaleCrashes\Events\ReportsReady;
use ArtisanBuild\TelltaleCrashes\ImportReports;
use ArtisanBuild\TelltaleCrashes\ImportScheduler;
use ArtisanBuild\TelltaleCrashes\Jobs\ImportNativeReports;
use ArtisanBuild\TelltaleCrashes\NativeBridge;
use ArtisanBuild\TelltaleCrashes\TelltaleCrashesServiceProvider;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Http;

it('queues a same launch readiness after an early empty poll and ignores empty ack signals', function (): void {
    Http::fake();
    $nativeCalls = 0;
    app()->instance(NativeBridge::class, new NativeBridge(function () use (&$nativeCalls): array {
        $nativeCalls++;

        return ['schema_version' => 1, 'reports' => []];
    }));
    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('later')->twice()->with(0, Mockery::type(ImportNativeReports::class), '', null);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->times(3)->with('database')->andReturn($queue);
    app()->instance(Factory::class, $factory);
    app(ImportScheduler::class)->schedule();
    (new ImportNativeReports)->handle(app(ImportReports::class), app(ClientDatabase::class));
    event(new ReportsReady(pending_count: 1));
    event(new ReportsReady(pending_count: 1));
    event(new ReportsReady(pending_count: 0));
    event(new ReportsReady(schema_version: 2, pending_count: 1));
    expect($nativeCalls)->toBe(1);
    Http::assertNothingSent();
});

it('provider startup schedules only queued work in an interactive runtime', function (): void {
    Http::fake();
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), false);
    $nativeCalls = 0;
    app()->instance(NativeBridge::class, new NativeBridge(function () use (&$nativeCalls): array {
        $nativeCalls++;

        return [];
    }));
    $queue = Mockery::mock(Queue::class);
    $queue->shouldReceive('later')->once()->with(0, Mockery::type(ImportNativeReports::class), '', null);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->once()->andReturn($queue);
    app()->instance(Factory::class, $factory);
    try {
        (new TelltaleCrashesServiceProvider(app()))->boot(app('events'));
        expect($nativeCalls)->toBe(0);
        Http::assertNothingSent();
    } finally {
        $property->setValue(app(), true);
    }
});

it('refuses a synchronously dispatched job before reading native reports', function (): void {
    $nativeCalls = 0;
    app()->instance(NativeBridge::class, new NativeBridge(function () use (&$nativeCalls): array {
        $nativeCalls++;

        return [];
    }));
    $nativeJob = Mockery::mock(Job::class);
    $nativeJob->shouldReceive('getConnectionName')->once()->andReturn('sync');
    $nativeJob->shouldNotReceive('release');
    $job = new ImportNativeReports;
    $job->setJob($nativeJob);
    $job->handle(app(ImportReports::class), app(ClientDatabase::class));
    expect($nativeCalls)->toBe(0);
});

it('refuses named sync connections and aliases resolving to a SyncQueue', function (string $mode): void {
    $bridge = new NativeBridge(fn () => throw new RuntimeException('must not read native'));
    app()->instance(NativeBridge::class, $bridge);
    $factory = Mockery::mock(Factory::class);
    if ($mode === 'instance') {
        $factory->shouldReceive('connection')->once()->with('database')->andReturn(new SyncQueue);
    } else {
        $factory->shouldNotReceive('connection');
        config($mode === 'named' ? ['telltale.queue.connection' => 'sync'] : ['queue.connections.database.driver' => 'sync']);
    }
    app()->instance(Factory::class, $factory);
    app(ImportScheduler::class)->schedule();
})->with(['named', 'driver', 'instance']);

it('contains queue storage errors and never polls native while scheduling', function (): void {
    app()->instance(NativeBridge::class, new NativeBridge(fn () => throw new RuntimeException('no inline bridge')));
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Factory::class, $factory);
    app(ImportScheduler::class)->schedule();
});

it('does not reschedule imports when booting an isolated console worker', function (): void {
    $factory = Mockery::mock(Factory::class);
    $factory->shouldNotReceive('connection');
    app()->instance(Factory::class, $factory);
    app()->instance(NativeBridge::class, new NativeBridge(fn () => throw new RuntimeException('no inline native call')));
    $provider = new TelltaleCrashesServiceProvider(app());
    $provider->boot(app('events'));
    expect(app()->runningInConsole())->toBeTrue();
});

it('releases a transient import job with bounded backoff', function (): void {
    app()->instance(NativeBridge::class, new NativeBridge(fn () => null));
    $nativeJob = Mockery::mock(Job::class);
    $nativeJob->shouldReceive('getConnectionName')->once()->andReturn('database');
    $nativeJob->shouldReceive('attempts')->once()->andReturn(2);
    $nativeJob->shouldReceive('release')->once()->with(30);
    $job = new ImportNativeReports;
    $job->setJob($nativeJob);
    $job->handle(app(ImportReports::class), app(ClientDatabase::class));
});

it('roundtrips the import job through the actual SQLite database queue without inline collection', function (): void {
    Http::fake();
    config([
        'database.connections.crash_queue_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.connections.crash_fixture' => ['driver' => 'database', 'connection' => 'crash_queue_fixture', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
        'telltale.queue.connection' => 'crash_fixture',
    ]);
    $database = app('db')->connection('crash_queue_fixture');
    $database->getSchemaBuilder()->create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $nativeCalls = 0;
    $reports = [];
    app()->instance(NativeBridge::class, new NativeBridge(function (string $method) use (&$nativeCalls, &$reports): array {
        $nativeCalls++;
        if ($method === 'TelltaleCrashes.Acknowledge') {
            // Model failed asynchronous native persistence: ack emits ready with the SAME rows.
            event(new ReportsReady(pending_count: count($reports)));

            return ['scheduled' => true];
        }

        return ['schema_version' => 1, 'reports' => $reports];
    }));
    app(ImportScheduler::class)->schedule();
    expect($nativeCalls)->toBe(0)->and($database->table('jobs')->count())->toBe(1);
    $job = app(Factory::class)->connection('crash_fixture')->pop();
    expect($job)->not->toBeNull();
    $job->fire();
    expect($nativeCalls)->toBe(1)->and($database->table('jobs')->count())->toBe(0);

    $reports = [(new CrashDetails(
        reportId: hash('sha256', 'queue-death'), platform: 'android', kind: 'crash',
        source: 'application_exit_info', reason: 'native_crash', fatal: true,
        occurredAt: '2026-01-01T00:00:00Z', timestampPrecision: 'exact', occurrenceCount: 1,
    ))->toArray()];
    event(new ReportsReady(pending_count: 1));
    app(Factory::class)->connection('crash_fixture')->pop()->fire();
    expect($nativeCalls)->toBe(3)->and($database->table('jobs')->count())->toBe(1)
        ->and($database->table('jobs')->value('attempts'))->toBe(1)
        ->and($database->table('jobs')->value('available_at'))->toBeGreaterThan(time());
    $reports = [];
    $database->table('jobs')->update(['available_at' => time() - 1]);
    app(Factory::class)->connection('crash_fixture')->pop()->fire();
    expect($nativeCalls)->toBe(4)->and($database->table('jobs')->count())->toBe(0);
    event(new ReportsReady(pending_count: 1));
    expect($database->table('jobs')->count())->toBe(1);
    Http::assertNothingSent();
});
