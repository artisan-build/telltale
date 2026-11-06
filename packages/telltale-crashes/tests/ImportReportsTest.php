<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use ArtisanBuild\TelltaleCrashes\ImportReports;
use ArtisanBuild\TelltaleCrashes\NativeBridge;
use Illuminate\Support\Facades\Http;

function pendingCrash(string $key = 'death'): array
{
    return (new CrashDetails(
        reportId: hash('sha256', $key), platform: 'android', kind: 'crash',
        source: 'application_exit_info', reason: 'native_crash', fatal: true,
        occurredAt: '2026-01-01T00:00:00Z', timestampPrecision: 'exact', occurrenceCount: 1,
        build: 'historical-build', trace: 'private text',
    ))->toArray();
}

final class WorkerRedactor
{
    public function __invoke(array $event): array
    {
        $event['crash']['trace'] = 'worker redaction';
        $event['event_id'] = 'cannot replace identity';

        return $event;
    }
}

function installBridge(array &$calls, array &$reports, bool &$ackWorks, bool &$suppressed): NativeBridge
{
    $bridge = new NativeBridge(function (string $method, string $json) use (&$calls, &$reports, &$ackWorks, &$suppressed): string {
        $params = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $calls[] = [$method, $params];
        $data = match ($method) {
            'TelltaleCrashes.Pending' => ['schema_version' => 1, 'reports' => $reports, 'revision' => 1, 'suppressed' => $suppressed],
            'TelltaleCrashes.Acknowledge' => ['scheduled' => $ackWorks],
            'TelltaleCrashes.SetSuppressed' => ['scheduled' => true],
        };
        if ($method === 'TelltaleCrashes.SetSuppressed') {
            $suppressed = $params['suppressed'];
            if ($suppressed) {
                $reports = [];
            }
        }

        return json_encode(['status' => 'success', 'data' => $data], JSON_THROW_ON_ERROR);
    });
    app()->instance(NativeBridge::class, $bridge);

    return $bridge;
}

beforeEach(function (): void {
    Http::fake();
    $this->calls = [];
    $this->reports = [pendingCrash()];
    $this->ackWorks = true;
    $this->suppressed = false;
    installBridge($this->calls, $this->reports, $this->ackWorks, $this->suppressed);
});

it('imports durable history and replays idempotently after an acknowledgment failure', function (): void {
    $this->ackWorks = false;
    expect(app(ImportReports::class)->run())->toBeFalse();
    $this->ackWorks = true;
    expect(app(ImportReports::class)->run())->toBeFalse()
        ->and(app(ClientDatabase::class)->outboxCount())->toBe(1)
        ->and(app(ClientDatabase::class)->sessionState()['id'])->toBeNull();
    $event = app(ClientDatabase::class)->batch(1)[0]->event;
    expect($event['event_id'])->toBe(CrashDetails::fromArray($this->reports[0])->eventId())
        ->and($event['crash']['build'])->toBe('historical-build')
        ->and($this->calls[1])->toBe(['TelltaleCrashes.Acknowledge', ['report_ids' => [$this->reports[0]['report_id']]]]);
    $this->reports = [];
    expect(app(ImportReports::class)->run())->toBeTrue();
    Http::assertNothingSent();
});

it('never acknowledges a transient outbox failure', function (): void {
    $database = app(ClientDatabase::class);
    $sqlite = (new ReflectionProperty($database, 'database'))->getValue($database);
    $sqlite->exec("CREATE TRIGGER reject_crash BEFORE INSERT ON telltale_outbox BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    $sqlite->enableExceptions(true);
    expect(app(ImportReports::class)->run())->toBeFalse()
        ->and(array_column($this->calls, 0))->toBe(['TelltaleCrashes.Pending'])
        ->and(app(ClientDatabase::class)->outboxCount())->toBe(0);
});

it('acknowledges deliberate callback drops and isolates malformed reports', function (): void {
    app(TelltaleClient::class)->beforeSend(fn () => null);
    $bad = pendingCrash('bad');
    $bad['kind'] = 'invalid';
    $this->reports[] = $bad;
    $this->reports[] = ['report_id' => 'invalid receipt'];
    expect(app(ImportReports::class)->run())->toBeFalse()
        ->and(app(ClientDatabase::class)->outboxCount())->toBe(0)
        ->and(app(ClientDatabase::class)->droppedEventsTotal())->toBe(3)
        ->and($this->calls[1][1]['report_ids'])->toBe([$this->reports[0]['report_id'], $bad['report_id']]);
});

it('resolves the configured callback in a new worker client without interactive closure memory', function (): void {
    app(TelltaleClient::class)->beforeSend(fn () => null);
    app()->forgetInstance(TelltaleClient::class);
    config(['telltale-crashes.before_send' => WorkerRedactor::class]);
    expect(app(ImportReports::class)->run())->toBeFalse();
    $event = app(ClientDatabase::class)->batch(1)[0]->event;
    expect($event['crash']['trace'])->toBe('worker redaction')
        ->and($event['event_id'])->toBe(CrashDetails::fromArray($this->reports[0])->eventId());
});

it('does not acknowledge a callback configuration or callback execution failure', function (bool $throws): void {
    if ($throws) {
        app(TelltaleClient::class)->beforeSend(fn () => throw new RuntimeException('callback unavailable'));
    } else {
        config(['telltale-crashes.before_send' => 'MissingWorkerClass']);
    }
    expect(app(ImportReports::class)->run())->toBeFalse()
        ->and(array_column($this->calls, 0))->toBe(['TelltaleCrashes.Pending']);
})->with([false, true]);

it('suppresses and discards native backlog on opt out and resumes on opt in', function (): void {
    app(TelltaleClient::class)->optOut();
    expect($this->suppressed)->toBeTrue()->and($this->reports)->toBe([]);
    $this->calls = [];
    expect(app(ImportReports::class)->run())->toBeTrue()
        ->and(array_column($this->calls, 0))->toBe(['TelltaleCrashes.SetSuppressed']);
    app(TelltaleClient::class)->optIn();
    expect($this->suppressed)->toBeFalse();
    Http::assertNothingSent();
});

it('repairs persisted suppression on worker startup without repeated ready notifications', function (): void {
    $this->suppressed = true;
    expect(app(ImportReports::class)->run())->toBeFalse()->and($this->suppressed)->toBeFalse();
    $this->calls = [];
    $this->reports = [];
    expect(app(ImportReports::class)->run())->toBeTrue()
        ->and(array_column($this->calls, 0))->toBe(['TelltaleCrashes.Pending']);
});

it('is inert without the native bridge and contains bridge errors and malformed wrappers', function (): void {
    app()->instance(NativeBridge::class, new NativeBridge);
    expect(app(ImportReports::class)->run())->toBeTrue();
    foreach ([fn () => throw new RuntimeException('native unavailable'), fn () => '{bad', fn () => ['status' => 'error'], fn () => ['schema_version' => 2, 'reports' => []]] as $call) {
        app()->instance(NativeBridge::class, new NativeBridge($call));
        expect(app(ImportReports::class)->run())->toBeFalse();
    }
    expect($this->calls)->toBe([]);
    Http::assertNothingSent();
});

it('accepts direct Android fallback payloads without a suppression key', function (): void {
    $bridge = new NativeBridge(fn () => json_encode(['schema_version' => 1, 'reports' => [], 'revision' => 0]));
    expect($bridge->pending()['reports'])->toBe([]);
});
