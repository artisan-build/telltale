<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleContracts\Package;
use Composer\InstalledVersions;

it('loads the Telltale contracts path package and application identity', function (): void {
    $contractsPath = InstalledVersions::getInstallPath('artisan-build/telltale-contracts');

    expect(config('app.name'))->toBe('Telltale')
        ->and(config('built-for-cloud.manifest.slug'))->toBe('telltale')
        ->and($contractsPath)->toBeString()
        ->and(realpath($contractsPath))->toBe(realpath(base_path('packages/telltale-contracts')))
        ->and(class_exists(Package::class))->toBeTrue();
});
