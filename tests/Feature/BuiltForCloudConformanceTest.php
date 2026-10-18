<?php

declare(strict_types=1);

use App\Console\Commands\PruneRawEvents;
use App\Mcp\TelltaleMcpServer;
use App\Mcp\Tools\ActiveUsers;
use App\Mcp\Tools\Apps;
use App\Mcp\Tools\DeleteInstall;
use App\Mcp\Tools\ErrorDetail;
use App\Mcp\Tools\Errors;
use App\Mcp\Tools\EventCounts;
use App\Mcp\Tools\Funnel;
use App\Mcp\Tools\IngestHealth;
use App\Mcp\Tools\InstallTimeline;
use App\Mcp\Tools\ReleaseAdoption;
use App\Mcp\Tools\Retention;
use App\Mcp\Tools\RevokeInstall;
use App\Mcp\Tools\RotateIngestKey;
use App\Mcp\Tools\ScreenFlow;
use App\Mcp\Tools\Sessions;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\FreshCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\PruneCredentialAuthorizationsCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootEnsureCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedEnforcement;
use Composer\InstalledVersions;

uses(ContractAssertions::class);

it('passes the version 1 reference-consumer conformance spec', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $packageRoot = InstalledVersions::getInstallPath('artisan-build/built-for-cloud');
    expect($packageRoot)->toBeString();

    $purposeMappings = [];
    foreach ((array) config('built-for-cloud.credentials.app_purposes', []) as $appPurpose => $purpose) {
        $purposeMappings[$appPurpose] = CredentialPurpose::from($purpose);
    }
    ksort($purposeMappings);

    $sourceRoots = [app_path(), base_path('bootstrap'), config_path(), base_path('routes')];
    sort($sourceRoots);
    $providerFiles = [app_path('Providers/AppServiceProvider.php'), $packageRoot.'/src/BuiltForCloudServiceProvider.php'];
    sort($providerFiles);

    $sorted = static function (array $members): array {
        sort($members);

        return $members;
    };

    $delegatedTools = [
        Apps::class,
        IngestHealth::class,
        EventCounts::class,
        ActiveUsers::class,
        ReleaseAdoption::class,
        ScreenFlow::class,
        Funnel::class,
        Retention::class,
        Sessions::class,
        Errors::class,
        ErrorDetail::class,
        InstallTimeline::class,
        DeleteInstall::class,
        RevokeInstall::class,
        RotateIngestKey::class,
    ];
    $delegatedArguments = [
        DeleteInstall::class => [
            'app_id' => 1,
            'install_id' => '10000000-0000-4000-8000-000000000001',
        ],
        ErrorDetail::class => [
            'app_id' => 1,
            'fingerprint' => str_repeat('a', 64),
        ],
        InstallTimeline::class => [
            'app_id' => 1,
            'session_id' => 'delegated-probe',
        ],
        RevokeInstall::class => [
            'app_id' => 1,
            'install_id' => '10000000-0000-4000-8000-000000000001',
        ],
    ];

    McpDelegatedEnforcement::assertHonors(
        TelltaleMcpServer::class,
        $delegatedTools,
        arguments: $delegatedArguments,
    );
    $delegatedProbe = McpDelegatedEnforcement::probe(
        TelltaleMcpServer::class,
        arguments: $delegatedArguments,
    );

    expect($delegatedProbe['tools'])->toBe($sorted($delegatedTools))
        ->and($delegatedProbe['invoked'])->toBe($sorted($delegatedTools))
        ->and($delegatedProbe['refusals'])->toBe([])
        ->and($delegatedProbe['principal_mismatches'])->toBe([])
        ->and($delegatedProbe['errors'])->toBe([])
        ->and($delegatedProbe['unreached'])->toBe([])
        ->and($delegatedProbe['negative_authorization'])->toBe('absent')
        ->and($delegatedProbe['violations'])->toBe([]);

    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sorted([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment+CompleteAsymmetricEnrollment+AsymmetricVerificationKeys',
            'path:device|Http\Controllers\DeviceAuthorizations+Actions\StartDeviceAuthorization/DecideDeviceAuthorization/PollDeviceAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:loopback|Http\Controllers\LoopbackAuthorizations+Actions\StartLoopbackAuthorization/DecideLoopbackAuthorization/ExchangeLoopbackAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sorted([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sorted([
            ConsoleReKeyCommand::class,
            ConsoleRetireKeyCommand::class,
            CreateAdminCommand::class,
            CredentialActivateCommand::class,
            CredentialListCommand::class,
            CredentialMintCommand::class,
            CredentialRevokeCommand::class,
            CredentialRotateCommand::class,
            FreshCommand::class,
            HmacRewrapCommand::class,
            InstallOperatorCredentialCommand::class,
            OutboxDrainCommand::class,
            OwnershipMintClaimCommand::class,
            OwnershipRemintOwnerTokenCommand::class,
            PruneCredentialAuthorizationsCommand::class,
            SigningRootEnsureCommand::class,
            SigningRootProvisionCommand::class,
            SubjectOffboardCommand::class,
            WarnExpiringCredentialsCommand::class,
            DeliverOwnershipWebhook::class,
            PruneRawEvents::class,
            'Closure@package/src/SystemAuthoritySchedule.php:27',
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sorted([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationStore|built-for-cloud.manifest.slug|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => $sorted($delegatedTools),
    ];

    $report = (new FleetConformance($this))->assert(new ConsumerConformance(
        consumer: 'telltale',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'mcp-delegated', 'tokens'],
        purposeMappings: $purposeMappings,
        mcpServer: TelltaleMcpServer::class,
        expected: $expected,
    ));

    expect($report->passed)->toBeTrue()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES);
});
