<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\CrashImportOutcome;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use ArtisanBuild\TelltaleContracts\EnvelopeDecoder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('imports actual native parser output through the outbox and wire', function (string $platform): void {
    $path = __DIR__.'/../../telltale-crashes/.native-build/'.$platform.'-projected.json';
    if (! is_file($path)) {
        $this->markTestSkipped('Run the '.$platform.' native harness first; generated output is required.');
    }
    $reports = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($reports)->not->toBeEmpty();
    $client = app(TelltaleClient::class);
    $database = app(ClientDatabase::class);
    $database->storeToken('native-harness-token');
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        $sent[] = gzdecode($request->body());

        return Http::response([], 202);
    });
    $crashes = [];
    foreach ($reports as $report) {
        $crash = CrashDetails::fromArray($report);
        $crashes[] = $crash;
        expect($client->importCrash($crash))->toBe(CrashImportOutcome::Enqueued)
            ->and($client->importCrash($crash))->toBe(CrashImportOutcome::Enqueued);
    }
    Http::assertNothingSent();
    expect($database->outboxCount())->toBe(count($reports))
        ->and($database->sessionState()['id'])->toBeNull();
    $events = $database->batch(32);
    foreach ($crashes as $index => $crash) {
        expect($events[$index]->event['crash']['report_id'])->toBe($crash->reportId)
            ->and($events[$index]->event['crash']['frames'])->toBe(array_map(fn ($frame) => $frame->toArray(), $crash->frames));
    }
    if ($platform === 'ios') {
        expect($crashes[0]->fingerprint())->toBe($crashes[2]->fingerprint())
            ->and($crashes[0]->build)->toBe('210')
            ->and($crashes[1]->fatal)->toBeFalse();
    }
    expect($client->drain()->successful)->toBeTrue()
        ->and($database->outboxCount())->toBe(0)
        ->and($sent)->not->toBeEmpty();
    foreach ($sent as $json) {
        $envelope = EnvelopeDecoder::decode($json);
        expect($envelope->toArray()['envelope_version'])->toBe(2);

        foreach (['WRONG_THREAD_CANARY', 'REGISTER_CANARY', 'THREAD_MEMORY_CANARY', 'MAPPING_CANARY', 'LOG_CANARY', 'FD_PATH_CANARY', 'CRASH_DETAIL_CANARY', 'private-path', 'SENSITIVE_MESSAGE_CANARY'] as $canary) {
            expect($json)->not->toContain($canary);
        }

    }
})->with(['ios', 'android']);
