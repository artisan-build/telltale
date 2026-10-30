<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\CrashImportOutcome;
use ArtisanBuild\TelltaleClient\NullTelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Testing\TelltaleFake;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use ArtisanBuild\TelltaleContracts\CrashFrame;
use Illuminate\Support\Facades\Http;

function importedCrash(string $identity = 'death'): CrashDetails
{
    return new CrashDetails(
        reportId: hash('sha256', $identity),
        platform: 'android',
        kind: 'crash',
        source: 'application_exit_info',
        reason: 'native_crash',
        fatal: true,
        occurredAt: '2026-01-01T00:00:00.123456Z',
        timestampPrecision: 'exact',
        occurrenceCount: 1,
        frames: [new CrashFrame(
            binaryName: 'test.so',
            binaryUuid: '1234567890123456',
            address: '0x1234567890123456',
            offset: '0x12345678',
            symbol: 'frame person@example.test +1 212 555 1212',
        )],
        appVersion: 'old-version',
        build: 'old-build',
        trace: 'person@example.test +1 212 555 1212',
        metadata: ['note' => 'person@example.test'],
    );
}

it('durably imports history without launch context and replays one stable event', function (): void {
    Http::fake();
    $client = app(TelltaleClient::class);
    $database = app(ClientDatabase::class);
    $crash = importedCrash();

    expect($client->importCrash($crash))->toBe(CrashImportOutcome::Enqueued)
        ->and($client->importCrash($crash))->toBe(CrashImportOutcome::Enqueued)
        ->and($database->outboxCount())->toBe(1)
        ->and($database->sessionState()['id'])->toBeNull();
    $event = $database->batch(10)[0]->event;
    expect($event['event_id'])->toBe($crash->eventId())
        ->and($event['ts'])->toBe($crash->occurredAt)
        ->and($event['session_id'])->toBe('native-'.$crash->reportId)
        ->and($event['crash']['build'])->toBe('old-build')
        ->and($event['crash']['app_version'])->toBe('old-version');
    $frame = $event['crash']['frames'][0];
    expect($frame['binary_uuid'])->toBe('1234567890123456')
        ->and($frame['address'])->toBe('0x1234567890123456')
        ->and($frame['offset'])->toBe('0x12345678')
        ->and($frame['load_address'])->toBe($crash->frames[0]->loadAddress);
    $encoded = json_encode($event, JSON_THROW_ON_ERROR);
    foreach (['person@example.test', '212 555 1212'] as $canary) {
        expect($encoded)->not->toContain($canary);
    }
    Http::assertNothingSent();
});

it('keeps import identity when a callback replaces it while allowing text redaction', function (): void {
    $client = app(TelltaleClient::class);
    $crash = importedCrash();
    $client->beforeSend(function (array $event): array {
        $event['event_id'] = 'replaced';
        $event['session_id'] = 'replaced';
        $event['type'] = 'event';
        $event['ts'] = '2026-10-30T00:00:00Z';
        $event['crash']['report_id'] = hash('sha256', 'replaced');
        $event['crash']['occurred_at'] = '2026-10-30T00:00:00Z';
        $event['crash']['trace'] = 'callback redaction';

        return $event;
    });
    expect($client->importCrash($crash)->mayAcknowledge())->toBeTrue();
    $event = app(ClientDatabase::class)->batch(1)[0]->event;
    expect($event['event_id'])->toBe($crash->eventId())
        ->and($event['type'])->toBe('crash')
        ->and($event['ts'])->toBe($crash->occurredAt)
        ->and($event['session_id'])->toBe('native-'.$crash->reportId)
        ->and($event['crash']['report_id'])->toBe($crash->reportId)
        ->and($event['crash']['trace'])->toBe('callback redaction');
});

it('deliberately drops rejected privacy content and callback drops with accounting', function (bool $invalid): void {
    $client = app(TelltaleClient::class);
    $client->beforeSend($invalid ? function (array $event): array {
        $event['crash']['trace'] = "invalid\0text";

        return $event;
    } : fn () => null);
    expect($client->importCrash(importedCrash()))->toBe(CrashImportOutcome::Dropped)
        ->and(app(ClientDatabase::class)->outboxCount())->toBe(0)
        ->and(app(ClientDatabase::class)->droppedEventsTotal())->toBe(1);
})->with([true, false]);

it('keeps callback exceptions retryable and opt out deliberate', function (): void {
    $client = app(TelltaleClient::class);
    $client->beforeSend(fn () => throw new RuntimeException('callback failure'));
    expect($client->importCrash(importedCrash()))->toBe(CrashImportOutcome::Retry)
        ->and(CrashImportOutcome::Retry->mayAcknowledge())->toBeFalse();
    $client->optOut();
    expect($client->importCrash(importedCrash()))->toBe(CrashImportOutcome::Dropped)
        ->and(app(ClientDatabase::class)->outboxCount())->toBe(0);
});

it('never acknowledges unavailable client storage', function (): void {
    expect((new NullTelltaleClient)->importCrash(importedCrash()))->toBe(CrashImportOutcome::Retry);
    $database = app(ClientDatabase::class);
    $sqlite = (new ReflectionProperty($database, 'database'))->getValue($database);
    $sqlite->exec("CREATE TRIGGER reject_crash BEFORE INSERT ON telltale_outbox BEGIN SELECT RAISE(ABORT, 'disk failure harness'); END");
    $sqlite->enableExceptions(true);

    expect(app(TelltaleClient::class)->importCrash(importedCrash()))->toBe(CrashImportOutcome::Retry)
        ->and($database->outboxCount())->toBe(0);
});

it('preserves historical dotted versions and numeric builds while scrubbing crash free text', function (string $version, string $build): void {
    Http::fake();
    $data = importedCrash()->toArray();
    $data['app_version'] = $version;
    $data['build'] = $build;
    $data['frames'][0]['binary_name'] = 'person@example.test';
    $crash = CrashDetails::fromArray($data);
    $client = app(TelltaleClient::class);
    $client->beforeSend(function (array $event): array {
        $event['name'] = 'crash person@example.test';

        return $event;
    });

    expect($client->importCrash($crash))->toBe(CrashImportOutcome::Enqueued);
    $event = app(ClientDatabase::class)->batch(1)[0]->event;
    expect($event['crash']['app_version'])->toBe($version)
        ->and($event['crash']['build'])->toBe($build)
        ->and($event['event_id'])->toBe($crash->eventId())
        ->and($event['crash']['report_id'])->toBe($crash->reportId)
        ->and($event['crash']['frames'][0]['binary_uuid'])->toBe('1234567890123456')
        ->and($event['crash']['frames'][0]['address'])->toBe('0x1234567890123456')
        ->and($event['crash']['frames'][0]['offset'])->toBe('0x12345678')
        ->and($event['crash']['frames'][0]['load_address'])->toBe($crash->frames[0]->loadAddress)
        ->and($event['crash']['reason'])->toBe('native_crash')
        ->and($event['name'])->toBe('crash [redacted-email]')
        ->and($event['crash']['frames'][0]['binary_name'])->toBe('[redacted-email]')
        ->and($event['crash']['frames'][0]['symbol'])->toBe('frame [redacted-email] [redacted-phone]')
        ->and($event['crash']['trace'])->toBe('[redacted-email] [redacted-phone]')
        ->and($event['crash']['metadata']['note'])->toBe('[redacted-email]');
    foreach (['person@example.test', '212 555 1212'] as $canary) {
        expect(json_encode($event, JSON_THROW_ON_ERROR))->not->toContain($canary);
    }
    Http::assertNothingSent();
})->with([
    'calendar version and numeric build' => ['2026.10.30', '123456789'],
    'dotted version and longVersionCode' => ['1.2.345678', '4294967295'],
]);

it('reports explicit fake crash import outcomes without acknowledging callback failures', function (string $mode, CrashImportOutcome $outcome): void {
    $fake = new TelltaleFake;
    $callbackCalls = 0;
    $fake->beforeSend(function (array $event) use ($mode, &$callbackCalls): ?array {
        $callbackCalls++;
        if ($mode === 'throw') {
            throw new RuntimeException('fake callback failure');
        }
        if ($mode === 'drop') {
            return null;
        }
        $event['props']['note'] = 'accepted callback';

        return $event;
    });
    if ($mode === 'opt-out') {
        $fake->optOut();
    }

    $actual = $fake->importCrash(importedCrash());
    expect($actual)->toBe($outcome)
        ->and($actual->mayAcknowledge())->toBe($mode !== 'throw')
        ->and($callbackCalls)->toBe($mode === 'opt-out' ? 0 : 1);
    if ($mode === 'accept') {
        $fake->assertEventSent('native_crash', fn (array $props): bool => $props['note'] === 'accepted callback'
            && $props['crash']['report_id'] === importedCrash()->reportId);
    } else {
        $fake->assertNothingSent();
    }
})->with([
    'accepted' => ['accept', CrashImportOutcome::Enqueued],
    'deliberate drop' => ['drop', CrashImportOutcome::Dropped],
    'callback throw' => ['throw', CrashImportOutcome::Retry],
    'opt-out' => ['opt-out', CrashImportOutcome::Dropped],
]);
