<?php

declare(strict_types=1);

use App\Support\SetupInstructions;
use Illuminate\Support\Facades\File;

it('keeps setup and Scalpels handoff artifacts generated from the canonical source', function (): void {
    $instructions = resolve(SetupInstructions::class);

    expect($instructions->steps())->toHaveCount(5)
        ->and($instructions->steps()[0]['title'])->toBe('Configure the permanent domain first')
        ->and($instructions->steps()[1]['title'])->toBe('Create the app')
        ->and($instructions->steps()[2]['code'])->toBe('composer require artisan-build/telltale-client')
        ->and($instructions->steps()[3]['code'])->toContain('TELLTALE_URL=', 'TELLTALE_INGEST=')
        ->and($instructions->steps()[4]['body'])->toContain('PrivacyInfo.xcprivacy');

    foreach ($instructions->artifacts() as $path => $expected) {
        expect(File::get(base_path($path)))->toBe($expected);
    }

    $this->artisan('telltale:generate-docs', ['--local' => true, '--check' => true])
        ->expectsOutput('Generated documentation is current.')
        ->assertSuccessful();
});

it('ships complete privacy and platform reference sections', function (): void {
    $privacy = File::get(base_path('docs/privacy.md'));
    $reference = File::get(base_path('docs/data-reference.md'));

    expect($privacy)
        ->toContain(
            'NSPrivacyCollectedDataTypeProductInteraction',
            'NSPrivacyCollectedDataTypeOtherDiagnosticData',
            'NSPrivacyCollectedDataTypeDeviceID',
            'App Store Privacy Label',
            'Other Identifiers (the install id)',
            'Google Play Data Safety',
            'use it for tracking',
        )
        ->and($reference)
        ->toContain(
            '## Mobile v4',
            '## Desktop v2',
            'X-Telltale-Session: v1;session=<raw-session-uuid>;install=<sha256-install-hash>',
            'Native crashes, OOM failures, ANRs, or Electron Crashpad reports.',
            'Exceptions rendered inside SuperNative screens',
            'AsyncTask failures',
            'New foreground, background, launch, quit, connectivity, or other lifecycle signals.',
            'Mobile background upload or background flush.',
            'Nightwatch sensor telemetry',
            'crash-free metrics',
        );
});
