<?php

declare(strict_types=1);

use App\Authorization\TelltaleAbility;
use App\Domain\Ingest\AppManager;
use App\Domain\Ingest\Credential as IngestCredential;
use App\Http\Middleware\RejectIngestCredentialsFromMcp;
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
use App\Models\DailyAggregate;
use App\Models\ErrorGroup;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedApp;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAbilityRegistry;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;
use Laravel\Mcp\Server\Middleware\ValidateMcpHeaders;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
    config([
        'built-for-cloud.console.issuer' => 'https://console.test',
        'built-for-cloud.console.audience' => 'https://telltale.test',
        'built-for-cloud.mcp.two_phase.cache_store' => 'database',
    ]);
});

afterEach(function (): void {
    Date::setTestNow();
});

it('registers exactly two effect-scoped MCP doors and publishes delegated metadata', function (): void {
    $read = Mcp::getWebServer('mcp');
    $destructive = Mcp::getWebServer('mcp/destructive');

    expect($read)->not->toBeNull()
        ->and($read?->gatherMiddleware())->toBe([
            ReorderJsonAccept::class,
            ValidateMcpHeaders::class,
            AddWwwAuthenticateHeader::class,
            RejectIngestCredentialsFromMcp::class,
            'bfc.mcp:product,read',
        ])
        ->and($destructive)->not->toBeNull()
        ->and($destructive?->gatherMiddleware())->toBe([
            ReorderJsonAccept::class,
            ValidateMcpHeaders::class,
            AddWwwAuthenticateHeader::class,
            RejectIngestCredentialsFromMcp::class,
            'bfc.mcp:product,destructive',
        ])
        ->and(config('built-for-cloud.mcp.path'))->toBe('/mcp')
        ->and(config('built-for-cloud.mcp.write_path'))->toBeNull()
        ->and(config('built-for-cloud.mcp.destructive_path'))->toBe('/mcp/destructive')
        ->and(config('built-for-cloud.mcp.delegated'))->toBeTrue();

    $this->getJson('/bfc/meta')->assertOk()
        ->assertJsonPath('endpoints.mcp', '/mcp')
        ->assertJsonPath('endpoints.mcp_destructive', '/mcp/destructive')
        ->assertJsonFragment(['mcp-delegated'])
        ->assertJsonFragment(['mcp-effect-scoped']);
});

it('conforms all tools and snapshots the exact destructive-door inventory', function (): void {
    McpDelegatedTools::assertConforms(TelltaleMcpServer::class);

    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    $response = telltaleMcpRequest('/mcp/destructive', mcpListPayload(), $token)->assertOk();
    $tools = $response->json('result.tools');

    expect($tools)->toMatchSnapshot();

    $names = $response->json('result.tools.*.name');
    sort($names);

    expect($names)->toBe([
        'active_users',
        'apps',
        'delete_install',
        'error_detail',
        'errors',
        'event_counts',
        'funnel',
        'ingest_health',
        'install_timeline',
        'release_adoption',
        'retention',
        'revoke_install',
        'rotate_ingest_key',
        'screen_flow',
        'sessions',
    ]);
});

it('passes the framework product-admission conformance helper', function (): void {
    McpProductAdmission::assert();
});

it('admits only exact read ability and current active user-bound grants before application queries', function (): void {
    TrackedAppFactoryProbe::seed();
    resolve(CredentialAbilityRegistry::class)->register('other.read');
    $activeUser = telltaleMcpUser('active');
    $inactiveUser = telltaleMcpUser('inactive');
    $nobodyQueries = [];
    DB::listen(function ($query) use (&$nobodyQueries): void {
        $nobodyQueries[] = strtolower($query->sql);
    });
    $this->postJson('/mcp', mcpListPayload())->assertUnauthorized();
    expect(applicationQueries($nobodyQueries))->toBe([]);

    $cases = [
        'missing ability' => [telltaleMcpCredential([]), 400],
        'wrong ability' => [telltaleMcpCredential(['other.read']), 400],
        'inactive account cap' => [telltaleMcpCredential([TelltaleAbility::Read->value], $inactiveUser), 400],
        'wrong purpose' => [telltaleMcpCredential([TelltaleAbility::Read->value], null, CredentialPurpose::Consumption), 401],
        'unknown credential' => ['not-a-stored-credential', 401],
    ];

    foreach ($cases as $case => [$token, $status]) {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        telltaleMcpRequest('/mcp', mcpToolPayload('apps'), $token)->assertStatus($status);

        expect(applicationQueries($queries))->toBe([], $case.' reached application data.');
    }

    $active = telltaleMcpCredential([TelltaleAbility::Read->value], $activeUser);
    expect(telltaleMcpTool('apps', [], $active))->toHaveCount(1);
});

it('returns 403 for both ingest credential classes on both MCP doors without querying application data', function (): void {
    $created = resolve(AppManager::class)->create('Credential refusal');
    $ingest = $created->ingestValue();
    [, $installToken] = storageInstall($created->app(), $ingest);

    foreach ([$ingest, $installToken] as $credential) {
        foreach (['/mcp', '/mcp/destructive'] as $path) {
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = strtolower($query->sql);
            });

            telltaleMcpRequest($path, mcpListPayload(), $credential)
                ->assertForbidden()
                ->assertExactJson(['message' => 'Forbidden.']);

            expect(applicationQueries($queries))->toBe([]);
        }
    }
});

it('authorizes inside every handler and destructive preview before application queries', function (): void {
    $calls = [
        [Apps::class, []],
        [IngestHealth::class, ['app_id' => 1]],
        [EventCounts::class, ['app_id' => 1]],
        [ActiveUsers::class, ['app_id' => 1]],
        [ReleaseAdoption::class, ['app_id' => 1]],
        [ScreenFlow::class, ['app_id' => 1]],
        [Funnel::class, ['app_id' => 1, 'steps' => ['one']]],
        [Retention::class, ['app_id' => 1]],
        [Sessions::class, ['app_id' => 1]],
        [Errors::class, ['app_id' => 1]],
        [ErrorDetail::class, ['app_id' => 1, 'fingerprint' => str_repeat('a', 64)]],
        [InstallTimeline::class, ['app_id' => 1, 'install_id' => '10000000-0000-4000-8000-000000000001']],
        [DeleteInstall::class, ['app_id' => 1, 'install_id' => '10000000-0000-4000-8000-000000000001']],
        [RevokeInstall::class, ['app_id' => 1, 'install_id' => '10000000-0000-4000-8000-000000000001']],
        [RotateIngestKey::class, ['app_id' => 1]],
    ];
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    foreach ($calls as [$class, $arguments]) {
        try {
            resolve($class)->handle(new McpRequest($arguments));
            test()->fail($class.' did not authorize its handler.');
        } catch (AuthorizationException) {
            // Expected before argument validation or application access.
        }

        if (method_exists($class, 'preview')) {
            try {
                resolve($class)->preview(new McpRequest($arguments));
                test()->fail($class.' did not authorize its preview.');
            } catch (AuthorizationException) {
                // Expected before argument validation or application access.
            }
        }
    }

    expect(applicationQueries($queries))->toBe([]);
});

it('admits active delegated members and admins and refuses malformed assertions', function (): void {
    $key = telltaleDelegatedKey();
    telltaleTrustDelegatedKey($key);

    foreach (['member', 'admin'] as $role) {
        $response = telltaleMcpRequest('/mcp', mcpListPayload(), telltaleAssertion($key, $role, $role))
            ->assertOk();

        expect($response->json('result.tools'))->toHaveCount(12);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    telltaleMcpRequest('/mcp', mcpListPayload(), 'v4.public.malformed')->assertUnauthorized();
    expect(applicationQueries($queries))->toBe([]);
});

it('keeps destructive tools absent and distinguishably refused through the read ceiling', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    $list = telltaleMcpRequest('/mcp', mcpListPayload(), $token)->assertOk();

    expect($list->json('result.tools.*.name'))->not->toContain(
        'delete_install',
        'revoke_install',
        'rotate_ingest_key',
    );

    telltaleMcpRequest('/mcp', mcpToolPayload('delete_install', [
        'app_id' => 1,
        'install_id' => '10000000-0000-4000-8000-000000000001',
    ]), $token)->assertStatus(400)->assertJsonPath('error.message', 'effect_above_ceiling');
});

it('serves every read tool family from test-created PostgreSQL facts', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    [$app, $ingest] = storageApp();
    [$first, $firstToken] = storageInstall($app, $ingest);
    [, $secondToken] = storageInstall($app, $ingest);
    $error = [
        'class' => 'RuntimeException',
        'message' => 'Checkout failed',
        'file' => '/app/Checkout.php',
        'line' => 42,
        'stack' => ['Checkout::pay'],
        'fingerprint' => 'checkout-v2',
    ];

    foreach ([[$firstToken, 'session-a'], [$secondToken, 'session-b']] as [$deviceToken, $session]) {
        $this->withToken($deviceToken)->postJson('/api/ingest', storageEnvelope([
            storageEvent('session.context', 'context', '2026-10-03T10:00:00Z', $session, [
                'props' => [
                    'app_version' => '2.0.0',
                    'platform' => 'mobile',
                    'os' => 'iOS 19',
                    'locale' => 'en-US',
                ],
            ]),
            storageEvent('home', 'screen', '2026-10-03T10:00:01Z', $session),
            storageEvent('checkout', 'screen', '2026-10-03T10:01:00Z', $session),
            storageEvent('checkout.started', 'event', '2026-10-03T10:02:00Z', $session),
            storageEvent('checkout.completed', 'event', '2026-10-03T10:03:00Z', $session),
            storageEvent('error.reported', 'error', '2026-10-03T10:04:00Z', $session, ['error' => $error]),
        ]))->assertAccepted();
    }

    $first->update(['dropped_events_total' => 7]);
    $this->withToken($firstToken)->postJson('/api/ingest', ['invalid' => true])->assertUnprocessable();
    $fingerprint = ErrorGroup::query()->sole()->fingerprint;

    expect(telltaleMcpTool('apps', [], $token)[0])->toMatchArray([
        'id' => $app->id,
        'live_versions' => ['2.0.0'],
        'dropped_events_total' => 7,
    ])->and(telltaleMcpTool('ingest_health', ['app_id' => $app->id], $token))->toMatchArray([
        'dropped_events_total' => 7,
        'rejections' => 1,
    ])->and(telltaleMcpTool('event_counts', [
        'app_id' => $app->id,
        'metric' => 'screen',
        'breakdown' => 'locale',
    ], $token))->toMatchArray([
        'counts' => [
            ['value' => 'checkout', 'count' => 2],
            ['value' => 'home', 'count' => 2],
        ],
        'breakdown' => [['value' => 'en-US', 'count' => 12]],
    ])->and(telltaleMcpTool('active_users', ['app_id' => $app->id], $token))->toMatchArray([
        'dau' => 2,
        'wau' => 2,
        'mau' => 2,
    ])->and(telltaleMcpTool('release_adoption', ['app_id' => $app->id], $token)['adoption'][0])->toMatchArray([
        'version' => '2.0.0',
        'active_installs' => 2,
        'share' => 100.0,
    ])->and(telltaleMcpTool('release_adoption', ['app_id' => $app->id], $token)['update_speed'][0])->toBe([
        'version' => '2.0.0',
        'first_seen_on' => '2026-10-03',
        'median_days_from_first_seen' => 0,
    ])->and(telltaleMcpTool('screen_flow', ['app_id' => $app->id], $token))->toMatchArray([
        'transitions' => [['from' => 'home', 'to' => 'checkout', 'count' => 2]],
        'entries' => [['screen' => 'home', 'count' => 2]],
        'exits' => [['screen' => 'checkout', 'count' => 2]],
    ])->and(telltaleMcpTool('funnel', [
        'app_id' => $app->id,
        'steps' => ['checkout.started', 'checkout.completed'],
    ], $token)['steps'])->toBe([
        ['step' => 'checkout.started', 'reached' => 2, 'conversion_rate' => 100, 'drop_off' => 0],
        ['step' => 'checkout.completed', 'reached' => 2, 'conversion_rate' => 100, 'drop_off' => 0],
    ]);

    expect(telltaleMcpTool('retention', ['app_id' => $app->id, 'weeks' => 0], $token)['cohorts'][0])
        ->toMatchArray(['cohort_size' => 2])
        ->and(telltaleMcpTool('sessions', ['app_id' => $app->id], $token))->toMatchArray([
            'count' => 2,
            'average_screens' => 2.0,
        ])
        ->and(telltaleMcpTool('errors', [
            'app_id' => $app->id,
            'new_since_version' => '1.0.0',
        ], $token)['groups'][0])->toMatchArray([
            'fingerprint' => $fingerprint,
            'count' => 2,
            'installs_affected' => 2,
            'new_since' => true,
        ])
        ->and(telltaleMcpTool('error_detail', [
            'app_id' => $app->id,
            'fingerprint' => $fingerprint,
        ], $token))->toMatchArray([
            'fingerprint' => $fingerprint,
            'installs_affected' => 2,
        ])
        ->and(telltaleMcpTool('install_timeline', [
            'app_id' => $app->id,
            'install_id' => $first->install_uuid,
        ], $token)['events'])->toHaveCount(6);
});

it('shows fresh accepted ingest in event_counts in one cycle and never double-counts replay', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    [$app, $ingest] = storageApp();
    [, $installToken] = storageInstall($app, $ingest);
    $envelope = storageEnvelope([
        storageEvent('purchase', 'event', '2026-10-03T11:00:00Z', 'fresh-session'),
    ]);

    $this->withToken($installToken)->postJson('/api/ingest', $envelope)
        ->assertAccepted()->assertJson(['accepted' => 1, 'duplicates' => 0]);

    expect(telltaleMcpTool('event_counts', ['app_id' => $app->id], $token)['counts'])
        ->toContain(['value' => 'purchase', 'count' => 1]);

    $this->withToken($installToken)->postJson('/api/ingest', $envelope)
        ->assertAccepted()->assertJson(['accepted' => 0, 'duplicates' => 1]);

    expect(telltaleMcpTool('event_counts', ['app_id' => $app->id], $token)['counts'])
        ->toContain(['value' => 'purchase', 'count' => 1]);
});

it('reports app-scoped ingest rate-limit hits durably', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    $created = resolve(AppManager::class)->create('Rate limited', [
        'rate_per_minute' => 1,
        'install_rate_per_minute' => 10,
        'daily_event_cap' => 100,
        'install_daily_event_cap' => 100,
    ]);
    storageInstall($created->app(), $created->ingestValue());

    $this->postJson('/api/register', ['install_id' => fake()->uuid()], [
        'X-Telltale-Ingest' => $created->ingestValue(),
    ])->assertTooManyRequests();

    expect(telltaleMcpTool('ingest_health', ['app_id' => $created->app()->id], $token))
        ->toMatchArray(['rate_limit_hits' => 1]);
});

it('binds destructive confirmation to arguments and spends it once', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    [$app, $ingest] = storageApp();
    [$first] = storageInstall($app, $ingest);
    [$second] = storageInstall($app, $ingest);
    $preview = telltaleMcpToolResponse('delete_install', [
        'app_id' => $app->id,
        'install_id' => $first->install_uuid,
    ], $token);
    $confirmation = mcpConfirmationValue($preview);

    telltaleMcpRequest('/mcp/destructive', mcpToolPayload('delete_install', [
        'app_id' => $app->id,
        'install_id' => $second->install_uuid,
        'confirm' => $confirmation,
    ]), $token)->assertStatus(400)->assertJsonPath('error.message', 'confirmation_mismatched');

    expect(Install::query()->count())->toBe(2);

    telltaleMcpRequest('/mcp/destructive', mcpToolPayload('delete_install', [
        'app_id' => $app->id,
        'install_id' => $first->install_uuid,
        'confirm' => $confirmation,
    ]), $token)->assertOk()->assertJsonPath('result._meta.two_phase.phase', 'executed');

    telltaleMcpRequest('/mcp/destructive', mcpToolPayload('delete_install', [
        'app_id' => $app->id,
        'install_id' => $first->install_uuid,
        'confirm' => $confirmation,
    ]), $token)->assertStatus(400)->assertJsonPath('error.message', 'confirmation_spent');
});

it('deletes raw install history while preserving aggregates', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    [$app, $ingest] = storageApp();
    [$install, $installToken] = storageInstall($app, $ingest);
    $this->withToken($installToken)->postJson('/api/ingest', storageEnvelope([
        storageEvent('durable', 'event', '2026-10-03T11:00:00Z', 'delete-session'),
    ]))->assertAccepted();
    $aggregateCount = DailyAggregate::query()->sum('event_count');
    $preview = telltaleMcpToolResponse('delete_install', [
        'app_id' => $app->id,
        'install_id' => $install->install_uuid,
    ], $token);

    telltaleMcpRequest('/mcp/destructive', mcpToolPayload('delete_install', [
        'app_id' => $app->id,
        'install_id' => $install->install_uuid,
        'confirm' => mcpConfirmationValue($preview),
    ]), $token)->assertOk();

    expect(Install::query()->count())->toBe(0)
        ->and(StoredEvent::query()->count())->toBe(0)
        ->and(DailyAggregate::query()->sum('event_count'))->toBe($aggregateCount);
});

it('revokes only the selected install token and preserves history', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    [$app, $ingest] = storageApp();
    [$install, $installToken] = storageInstall($app, $ingest);
    $this->withToken($installToken)->postJson('/api/ingest', storageEnvelope([
        storageEvent('before-revoke', 'event', '2026-10-03T11:00:00Z', 'revoke-session'),
    ]))->assertAccepted();
    $preview = telltaleMcpToolResponse('revoke_install', [
        'app_id' => $app->id,
        'install_id' => $install->install_uuid,
    ], $token);

    telltaleMcpRequest('/mcp/destructive', mcpToolPayload('revoke_install', [
        'app_id' => $app->id,
        'install_id' => $install->install_uuid,
        'confirm' => mcpConfirmationValue($preview),
    ]), $token)->assertOk();

    expect($install->refresh()->revoked_at)->not->toBeNull()
        ->and(StoredEvent::query()->count())->toBe(1);
    $this->withToken($installToken)->postJson('/api/ingest', storageEnvelope([
        storageEvent('after-revoke', 'event', '2026-10-03T11:01:00Z', 'revoke-session'),
    ]))->assertUnauthorized();
});

it('rotates an ingest key, stores only its hash, and returns the replacement once', function (): void {
    $token = telltaleMcpCredential([TelltaleAbility::Read->value]);
    $created = resolve(AppManager::class)->create('Rotate through MCP');
    $app = $created->app();
    $oldKey = $created->ingestValue();
    $preview = telltaleMcpToolResponse('rotate_ingest_key', ['app_id' => $app->id], $token);
    $executed = telltaleMcpRequest('/mcp/destructive', mcpToolPayload('rotate_ingest_key', [
        'app_id' => $app->id,
        'confirm' => mcpConfirmationValue($preview),
    ]), $token)->assertOk();
    $newKey = mcpContent($executed)['ingest_key'];

    expect($newKey)->toBeString()->not->toBe($oldKey)
        ->and($app->refresh()->ingest_key_hash)->toBe(IngestCredential::hash($newKey))
        ->and($app->ingest_key_hash)->not->toBe($newKey);

    $this->postJson('/api/register', ['install_id' => fake()->uuid()], [
        'X-Telltale-Ingest' => $oldKey,
    ])->assertUnauthorized();
    $this->postJson('/api/register', ['install_id' => fake()->uuid()], [
        'X-Telltale-Ingest' => $newKey,
    ])->assertCreated();

    telltaleMcpRequest('/mcp/destructive', mcpToolPayload('rotate_ingest_key', [
        'app_id' => $app->id,
        'confirm' => mcpConfirmationValue($preview),
    ]), $token)->assertStatus(400)->assertJsonPath('error.message', 'confirmation_spent');
});

/** @return array{jsonrpc: string, id: string, method: string} */
function mcpListPayload(): array
{
    return ['jsonrpc' => '2.0', 'id' => 'tools-list', 'method' => 'tools/list'];
}

/** @param array<string, mixed> $arguments @return array<string, mixed> */
function mcpToolPayload(string $name, array $arguments = []): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $name.'-'.str()->random(8),
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ];
}

/** @param array<string, mixed> $payload */
function telltaleMcpRequest(string $path, array $payload, string $token): TestResponse
{
    return test()->postJson($path, $payload, ['Authorization' => 'Bearer '.$token]);
}

/** @param list<string> $abilities */
function telltaleMcpCredential(
    array $abilities,
    ?User $user = null,
    CredentialPurpose $purpose = CredentialPurpose::Mcp,
): string {
    $token = 'mcp-'.bin2hex(random_bytes(24));
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => $purpose,
        'subject_type' => $user instanceof User ? SubjectType::UserPrincipal : SubjectType::Installation,
        'subject_ref' => $user instanceof User ? 'user-'.$user->id : 'telltale-installation',
        'name' => 'Telltale MCP test',
        'abilities' => $abilities,
        'user_id' => $user instanceof User ? (string) $user->id : null,
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

function telltaleMcpUser(string $status): User
{
    $user = User::query()->create([
        'name' => 'MCP test user',
        'email' => str()->random(12).'@example.test',
    ]);
    $user->forceFill(['role' => UserRole::Member->value, 'status' => $status])->save();

    return $user;
}

function telltaleMcpToolResponse(
    string $name,
    array $arguments,
    string $token,
): TestResponse {
    return telltaleMcpRequest('/mcp/destructive', mcpToolPayload($name, $arguments), $token)
        ->assertOk();
}

/** @return array<string, mixed> */
function telltaleMcpTool(string $name, array $arguments, string $token): array
{
    return mcpContent(telltaleMcpRequest('/mcp', mcpToolPayload($name, $arguments), $token)->assertOk());
}

/** @return array<string, mixed> */
function mcpContent(TestResponse $response): array
{
    $text = $response->json('result.content.0.text');
    expect($text)->toBeString();
    expect($response->json('result.isError'))->not->toBeTrue($text);

    return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
}

function mcpConfirmationValue(TestResponse $response): string
{
    $confirmation = $response->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString()->not->toBeEmpty();

    return $confirmation;
}

/** @param list<string> $queries @return list<string> */
function applicationQueries(array $queries): array
{
    return array_values(array_filter($queries, static fn (string $sql): bool => preg_match(
        '/\b(apps|installs|events|telltale_sessions|error_groups|daily_aggregates|daily_active_installs|daily_new_installs|ingest_health_daily)\b/',
        $sql,
    ) === 1));
}

function telltaleDelegatedKey(): AsymmetricSecretKey
{
    return AsymmetricSecretKey::generate(new Version4);
}

function telltaleTrustDelegatedKey(AsymmetricSecretKey $secret): void
{
    $keyring = new ConsoleKeyring;
    $keyring->add('telltale-test-key', $secret->getPublicKey()->toHexString());
    $keyring->activate('telltale-test-key');
}

function telltaleAssertion(AsymmetricSecretKey $secret, string $role, string $subject): string
{
    $now = CarbonImmutable::now('UTC');

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($secret)
        ->setClaims([
            'iss' => 'https://console.test',
            'sub' => 'telltale-'.$subject,
            'aud' => 'https://telltale.test',
            'iat' => $now->toAtomString(),
            'nbf' => $now->toAtomString(),
            'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => 'telltale_'.bin2hex(random_bytes(8)),
            'display_name' => 'Telltale Test Operator',
            'role' => $role,
            'purpose' => 'mcp',
        ])
        ->setFooterArray(['kid' => 'telltale-test-key'])
        ->toString();
}

final class TrackedAppFactoryProbe
{
    public static function seed(): void
    {
        TrackedApp::factory()->create(['name' => 'Authorization probe']);
    }
}
