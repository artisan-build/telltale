<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Facades\Telltale;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use Carbon\CarbonImmutable;
use Native\Desktop\Facades\App;
use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Network;

it('persists sessions across restarts and rolls only after a gap strictly over the threshold', function (): void {
    require_once __DIR__.'/Fixtures/NativePhp.php';
    config()->set('telltale.capture.platform', 'mobile');
    config()->set('nativephp.version', '1.2.3');
    config()->set('nativephp.version_code', 9);
    CarbonImmutable::setTestNow('2026-10-03 00:00:00 UTC');

    Telltale::event('first');
    $initial = array_map(static fn ($item): array => $item->event, app(ClientDatabase::class)->batch(100));
    $sessionId = $initial[0]['session_id'];

    Telltale::clearResolvedInstance(TelltaleClient::class);
    app()->forgetInstance(TelltaleClient::class);
    CarbonImmutable::setTestNow('2026-10-03 00:30:00 UTC');
    Telltale::event('boundary');
    CarbonImmutable::setTestNow('2026-10-03 01:00:00.001 UTC');
    Telltale::event('after-gap');

    $events = array_map(static fn ($item): array => $item->event, app(ClientDatabase::class)->batch(100));
    $boundary = collect($events)->firstWhere('name', 'boundary');
    $afterGap = collect($events)->firstWhere('name', 'after-gap');
    $contexts = collect($events)->where('type', 'context')->values();
    $ends = collect($events)->where('type', 'session_end')->values();

    expect($initial)->toHaveCount(3)
        ->and(array_column($initial, 'type'))->toBe(['session_start', 'context', 'event'])
        ->and($boundary['session_id'])->toBe($sessionId)
        ->and($afterGap['session_id'])->not->toBe($sessionId)
        ->and($contexts)->toHaveCount(2)
        ->and($ends)->toHaveCount(1)
        ->and($ends[0]['session_id'])->toBe($sessionId)
        ->and($ends[0]['props'])->toBe(['reason' => 'inactivity'])
        ->and($ends[0]['ts'])->toBe('2026-10-03T01:00:00.000Z');

    foreach ($events as $event) {
        expect($event['session_id'])->toBeString()->not->toBe('');
    }
});

it('captures allowlisted mobile context once per session and degrades when optional facades fail', function (): void {
    require_once __DIR__.'/Fixtures/NativePhp.php';
    config()->set('telltale.capture.platform', 'mobile');
    config()->set('nativephp.version', '3.2.1');
    config()->set('nativephp.version_code', 321);
    Device::$throws = false;
    Device::$getInfoCalls = 0;
    Device::$getIdCalls = 0;
    Device::$info = json_encode([
        'name' => 'Private Phone Name',
        'model' => 'Phone Pro',
        'operatingSystem' => 'iOS',
        'osVersion' => '18.4',
        'language' => 'de-CH',
        'androidId' => 'forbidden-id',
        'identifierForVendor' => 'forbidden-idfv',
    ], JSON_THROW_ON_ERROR);
    Network::$throws = false;
    Network::$status = (object) [
        'connected' => true,
        'type' => 'wifi',
        'isExpensive' => false,
        'isConstrained' => true,
        'ip' => '192.0.2.2',
    ];

    Telltale::event('one');
    Telltale::event('two');
    $events = array_map(static fn ($item): array => $item->event, app(ClientDatabase::class)->batch(100));
    $contexts = collect($events)->where('type', 'context')->values();
    $props = $contexts[0]['props'];

    expect($contexts)->toHaveCount(1)
        ->and(Device::$getInfoCalls)->toBe(1)
        ->and(Device::$getIdCalls)->toBe(0)
        ->and($props)->toMatchArray([
            'platform' => 'mobile',
            'app_version' => '3.2.1',
            'app_build' => 321,
            'device_model' => 'Phone Pro',
            'os' => 'iOS',
            'os_version' => '18.4',
            'locale' => 'de-CH',
            'network_connected' => true,
            'network_type' => 'wifi',
        ])
        ->and($props)->not->toHaveKeys(['name', 'androidId', 'identifierForVendor', 'ip']);

    Telltale::endSession('test');
    Device::$throws = true;
    Network::$throws = true;
    Telltale::event('after-failure');
    $events = array_map(static fn ($item): array => $item->event, app(ClientDatabase::class)->batch(100));
    $latestContext = collect($events)->where('type', 'context')->last();

    expect($latestContext['props'])->toBe([
        'platform' => 'mobile',
        'app_version' => '3.2.1',
        'app_build' => 321,
    ]);
});

it('captures allowlisted desktop context through the documented facade methods', function (): void {
    require_once __DIR__.'/Fixtures/NativePhp.php';
    config()->set('telltale.capture.platform', 'desktop');
    App::$throws = false;

    Telltale::event('desktop');
    $events = array_map(static fn ($item): array => $item->event, app(ClientDatabase::class)->batch(10));
    $context = collect($events)->firstWhere('type', 'context');

    expect($context['props'])->toBe([
        'platform' => 'desktop',
        'app_version' => '2.4.0',
        'platform_name' => 'darwin',
        'architecture' => 'arm64',
        'locale' => 'en-GB',
        'timezone' => 'Europe/London',
    ]);
});
