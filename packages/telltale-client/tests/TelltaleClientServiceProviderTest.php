<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Facades\Telltale;
use ArtisanBuild\TelltaleClient\Jobs\DrainOutbox;
use ArtisanBuild\TelltaleClient\TelltaleClientServiceProvider;
use ArtisanBuild\TelltaleClient\Testing\TelltaleFake;

it('loads config, facade, and client bindings under Testbench', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(TelltaleClientServiceProvider::class, true)
        ->and(config('telltale.database'))->toBe($this->telltaleDatabasePath)
        ->and(app(TelltaleClient::class))->toBeInstanceOf(TelltaleClient::class);
});

it('uses environment names that survive NativePHP cleanup matching', function (): void {
    $cleanupPattern = '/_(?:KEY|TOKEN|SECRET|PASSWORD)$/i';

    expect(preg_match($cleanupPattern, 'TELLTALE_URL'))->toBe(0)
        ->and(preg_match($cleanupPattern, 'TELLTALE_INGEST'))->toBe(0);
});

it('provides a facade fake with consent and assertion semantics', function (): void {
    $fake = Telltale::fake();

    expect($fake)->toBeInstanceOf(TelltaleFake::class);

    Telltale::event('opened', ['step' => 1]);
    Telltale::identify('opaque-user');
    $fake->assertEventSent('opened', fn (array $props): bool => $props['step'] === 1);
    $fake->assertIdentified('opaque-user');

    Telltale::optOut();
    Telltale::event('ignored');
    $fake->assertNothingSent();

    Telltale::optIn();
    Telltale::event('resumed');
    $fake->assertEventSent('resumed');
});

it('provides a database queue job and an explicit drain entry point', function (): void {
    $fake = Telltale::fake();
    $job = new DrainOutbox;

    expect($job->connection)->toBe('database')
        ->and($job->tries())->toBe(10)
        ->and(Telltale::drain()->successful)->toBeTrue();

    $job->handle($fake);
});
