<?php

declare(strict_types=1);

use App\Authorization\TelltaleAbility;
use App\Domain\Analytics\CrashQuery;
use App\Domain\Analytics\McpAnalytics;
use App\Domain\Storage\InstallDataDeleter;
use App\Domain\Storage\RawEventPruner;
use App\Models\DailyActiveInstall;
use App\Models\DailyAggregate;
use App\Models\DailyNewInstall;
use App\Models\StoredEvent;
use App\Models\TrackedSession;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-10-30T12:00:00Z'));
});

afterEach(function (): void {
    Date::setTestNow();
});

/** @return array<string, mixed> */
function crashReport(array $overrides = []): array
{
    return array_replace([
        'schema_version' => 1,
        'report_id' => hash('sha256', (string) Str::uuid()),
        'platform' => 'ios',
        'kind' => 'crash',
        'source' => 'metrickit_diagnostic',
        'reason' => 'signal_11',
        'fatal' => true,
        'occurred_at' => '2026-10-29T10:00:00Z',
        'timestamp_precision' => 'exact',
        'occurrence_count' => 1,
        'app_version' => '1.0.0',
        'build' => '17',
        'frames' => [[
            'binary_name' => 'CrashFixture',
            'binary_uuid' => '01234567-89ab-cdef-0123-456789abcdef',
            'address' => '0x1020',
            'offset' => '0x20',
            'load_address' => '0x1000',
        ]],
    ], $overrides);
}

/** @return array<string, mixed> */
function crashEvent(array $report): array
{
    return storageEvent('native.crash', 'crash', $report['occurred_at'], 'native-'.$report['report_id'], ['crash' => $report]);
}

/** @return array<string, mixed> */
function crashEnvelope(array $events): array
{
    return array_replace(storageEnvelope($events), ['envelope_version' => 2]);
}

it('persists typed native details once per install report while preserving ordinary v1 and v2 ingest', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    [$app, $key] = storageApp();
    [$install, $token] = storageInstall($app, $key);
    [, $second] = storageInstall($app, $key);
    $install->forceFill(['current_context' => ['app_version' => '9.0.0', 'platform' => 'new-platform']])->save();
    $report = crashReport();
    $event = crashEvent($report);
    $changed = array_replace($event, ['event_id' => (string) Str::ulid()]);

    $this->withToken($token)->postJson('/api/ingest', crashEnvelope([$event, $changed]))
        ->assertAccepted()->assertJson(['accepted' => 1, 'duplicates' => 1]);
    foreach ([$event, $changed] as $replay) {
        $this->withToken($token)->postJson('/api/ingest', crashEnvelope([$replay]))
            ->assertAccepted()->assertJson(['accepted' => 0, 'duplicates' => 1]);
    }

    $stored = StoredEvent::query()->sole();
    expect($stored->crash)->toEqual(CrashDetails::fromArray($report)->toArray())
        ->and($stored->app_version)->toBe('1.0.0')
        ->and($stored->platform)->toBe('ios')
        ->and($stored->server_session_id)->toBeNull()
        ->and($stored->error_group_id)->toBeNull()
        ->and($stored->crash_fingerprint)->toBe(CrashDetails::fromArray($report)->fingerprint())
        ->and(TrackedSession::query()->count())->toBe(0)
        ->and(DailyActiveInstall::query()->count())->toBe(0)
        ->and(DailyNewInstall::query()->count())->toBe(0)
        ->and(DailyAggregate::query()->count())->toBe(0)
        ->and($install->refresh()->current_context['app_version'])->toBe('9.0.0')
        ->and($install->first_seen_at)->toBeNull();

    // Report identity is scoped per install, not globally. A distinct event ID remains required.
    $this->withToken($second)->postJson('/api/ingest', crashEnvelope([$changed]))
        ->assertAccepted()->assertJson(['accepted' => 1]);
    $unknown = crashReport();
    unset($unknown['app_version'], $unknown['build']);
    $this->withToken($token)->postJson('/api/ingest', crashEnvelope([crashEvent($unknown)]))->assertAccepted();
    expect(StoredEvent::query()->where('crash_report_id', $unknown['report_id'])->sole()->app_version)->toBeNull();

    foreach ([1, 2] as $version) {
        $this->withToken($token)->postJson('/api/ingest', array_replace(storageEnvelope([
            storageEvent('ordinary', 'event', '2026-10-29T11:00:00Z', 'ordinary'),
        ]), ['envelope_version' => $version]))->assertAccepted()->assertJson(['accepted' => 1]);
    }
    expect((int) DailyAggregate::query()->where('dimension', 'event_name')->sum('event_count'))->toBe(2);
    $this->withToken($token)->postJson('/api/ingest', storageEnvelope([crashEvent(crashReport())]))->assertUnprocessable();
    $this->withToken($token)->postJson('/api/ingest', array_replace(crashEnvelope([$event]), ['envelope_version' => 3]))
        ->assertUnprocessable()->assertJsonPath('supported_envelope_version', 2);
});

it('groups normalized frames with summed aggregate exits and retained first and last historical builds', function (): void {
    [$app, $key] = storageApp();
    [, $token] = storageInstall($app, $key);
    [, $second] = storageInstall($app, $key);
    $one = crashReport();
    $two = crashReport([
        'occurred_at' => '2026-10-29T11:00:00Z', 'app_version' => '1.1.0', 'build' => '18',
        'frames' => [array_replace($one['frames'][0], ['address' => '0x9020', 'load_address' => '0x9000'])],
    ]);
    $split = crashReport(['frames' => [array_replace($one['frames'][0], ['offset' => '0x21', 'address' => '0x1021'])]]);
    $exits = crashReport([
        'kind' => 'memory_exit', 'source' => 'metrickit_exit', 'reason' => 'memory_limit', 'frames' => [],
        'timestamp_precision' => 'window_end', 'occurrence_count' => 7,
        'reporting_window_start' => '2026-10-28T10:00:00Z', 'reporting_window_end' => '2026-10-29T10:00:00Z',
    ]);
    $moreExits = array_replace($exits, ['report_id' => hash('sha256', 'another exit window'), 'occurrence_count' => 3]);
    foreach ([[$token, [$two, $one, $split, $exits]], [$second, [$moreExits]]] as [$device, $reports]) {
        $this->withToken($device)->postJson('/api/ingest', crashEnvelope(array_map(crashEvent(...), $reports)))->assertAccepted();
    }
    $query = resolve(CrashQuery::class);
    [$from, $to] = $query->window(null, null);
    $result = $query->groups($app, $from, $to, 25);
    $groups = collect($result['groups'])->keyBy('fingerprint');
    expect($groups)->toHaveCount(3)
        ->and($groups[CrashDetails::fromArray($one)->fingerprint()])->toMatchArray([
            'count' => 2, 'reports' => 2, 'installs_affected' => 1,
            'first_version' => '1.0.0', 'last_version' => '1.1.0', 'first_build' => '17', 'last_build' => '18',
        ])
        ->and($groups[CrashDetails::fromArray($exits)->fingerprint()])->toMatchArray([
            'count' => 10, 'reports' => 2, 'installs_affected' => 2,
        ])
        ->and($query->groups($app, $from, $to, 1)['groups'])->toHaveCount(1)
        ->and($query->groups($app, $from, $to, 1)['truncated'])->toBeTrue();
});

it('computes a half-open partial-day retained install proxy including crash-only installs and fatal ANRs', function (): void {
    [$app, $key] = storageApp();
    [, $ordinary] = storageInstall($app, $key);
    [, $fatal] = storageInstall($app, $key);
    [, $hang] = storageInstall($app, $key);
    [, $anr] = storageInstall($app, $key);
    [, $endOnly] = storageInstall($app, $key);
    $this->withToken($ordinary)->postJson('/api/ingest', storageEnvelope([
        storageEvent('active', 'event', '2026-10-29T10:30:00Z', 'active'),
    ]))->assertAccepted();
    $windowEnd = crashReport([
        'kind' => 'memory_exit', 'source' => 'metrickit_exit', 'reason' => 'memory_limit', 'frames' => [],
        'timestamp_precision' => 'window_end', 'occurrence_count' => 20,
        'reporting_window_start' => '2026-10-28T10:00:00Z', 'reporting_window_end' => '2026-10-29T10:00:00Z',
    ]);
    foreach ([
        [$fatal, $windowEnd], [$fatal, crashReport()],
        [$hang, crashReport(['kind' => 'hang', 'fatal' => false])],
        [$anr, crashReport([
            'platform' => 'android', 'kind' => 'hang', 'source' => 'application_exit_info', 'reason' => 'anr',
            'frames' => [['binary_name' => 'libfixture.so', 'binary_uuid' => 'abcdef0123456789', 'offset' => '0x20']],
        ])],
        [$endOnly, crashReport(['occurred_at' => '2026-10-29T11:00:00Z'])],
    ] as [$device, $report]) {
        $this->withToken($device)->postJson('/api/ingest', crashEnvelope([crashEvent($report)]))->assertAccepted();
    }
    $query = resolve(CrashQuery::class);
    [$from, $to] = $query->window('2026-10-29T12:00:00+02:00', '2026-10-29T13:00:00+02:00');
    $metric = $query->freeUsers($app, $from, $to);
    expect(StoredEvent::query()->where('platform', 'android')->sole()->crash['frames'][0]['binary_uuid'])->toBe('abcdef0123456789');
    expect($metric)->toMatchArray([
        'active_users' => 4, 'affected_users' => 2, 'crash_free_users' => 2, 'crash_free_percentage' => 50.0,
        'identity_basis' => 'install', 'from' => '2026-10-29T10:00:00+00:00', 'to' => '2026-10-29T11:00:00+00:00',
    ])->and($metric['timestamp_attribution'])->toBeString()
        ->and($metric['coverage_caveats'])->toHaveCount(3);
    [$empty] = storageApp();
    expect($query->freeUsers($empty, $from, $to))->toMatchArray([
        'active_users' => 0, 'affected_users' => 0, 'crash_free_users' => 0, 'crash_free_percentage' => null,
    ]);
    // The nonfatal-only install belongs to the denominator, never the affected set.
    $hangEvent = StoredEvent::query()->where('crash_fatal', false)->sole();
    $hangEvent->update(['occurred_at' => '2026-10-29T09:59:59Z']);
    expect($query->freeUsers($app, $from, $to))->toMatchArray(['active_users' => 3, 'affected_users' => 2]);
});

it('scopes crash MCP reads to the requested app and writes to the credential app without team ownership', function (): void {
    [$a, $keyA] = storageApp();
    [$installA, $tokenA] = storageInstall($a, $keyA);
    [$b, $keyB] = storageApp();
    [, $tokenB] = storageInstall($b, $keyB);
    $event = crashEvent(crashReport());
    $payload = array_replace(crashEnvelope([$event]), ['app_id' => $b->id, 'install_id' => 99999]);
    $this->withToken($tokenA)->postJson('/api/ingest', $payload)->assertAccepted();
    expect(StoredEvent::query()->sole()->app_id)->toBe($a->id)
        ->and(StoredEvent::query()->sole()->install_id)->toBe($installA->id);
    $forged = crashReport(['app_id' => $b->id]);
    $this->withToken($tokenA)->postJson('/api/ingest', crashEnvelope([crashEvent($forged)]))->assertUnprocessable();
    $reader = telltaleMcpCredential([TelltaleAbility::Read->value]);
    expect(telltaleMcpTool('crash_groups', ['app_id' => $b->id], $reader)['groups'])->toBe([])
        ->and(telltaleMcpTool('crash_free_users', ['app_id' => $b->id], $reader)['active_users'])->toBe(0)
        ->and(telltaleMcpTool('crash_detail', ['app_id' => $a->id, 'event_id' => $event['event_id']], $reader)['crash'])
        ->toEqual(CrashDetails::fromArray($event['crash'])->toArray());
    telltaleMcpRequest('/mcp', mcpToolPayload('crash_detail', ['app_id' => $b->id, 'event_id' => $event['event_id']]), $reader)
        ->assertOk()->assertJsonPath('result.isError', true);
    $this->withToken($tokenB)->postJson('/api/ingest', crashEnvelope([crashEvent(crashReport(['reason' => 'other_app']))]))->assertAccepted();
    // One admitted server-wide reader may read both apps, with each query independently scoped.
    expect(telltaleMcpTool('crash_groups', ['app_id' => $a->id], $reader)['groups'])->toHaveCount(1)
        ->and(telltaleMcpTool('crash_groups', ['app_id' => $b->id], $reader)['groups'])->toHaveCount(1)
        ->and(telltaleMcpTool('crash_groups', ['app_id' => $b->id], $reader)['groups'][0]['fingerprint'])
        ->not->toBe(CrashDetails::fromArray($event['crash'])->fingerprint())
        ->and(telltaleMcpTool('crash_free_users', ['app_id' => $a->id], $reader)['active_users'])->toBe(1);
    foreach (['crash_groups', 'crash_detail', 'crash_free_users'] as $name) {
        $args = ['app_id' => $a->id];
        if ($name === 'crash_detail') {
            $args['event_id'] = $event['event_id'];
        }
        foreach ([$tokenA, $keyA] as $unadmitted) {
            telltaleMcpRequest('/mcp', mcpToolPayload($name, $args), $unadmitted)->assertForbidden();
        }
        telltaleMcpRequest('/mcp', mcpToolPayload($name, $args), telltaleMcpCredential([]))->assertStatus(400);
    }
    expect(resolve(McpAnalytics::class)->installTimeline($a, $installA->install_uuid, null, 25)['events'])->toBe([])
        ->and(resolve(McpAnalytics::class)->funnel($a, ['native.crash'], 30, 30)['steps'][0]['reached'])->toBe(0);
});

it('uses configured retention defaults and refuses old reversed oversized and future MCP windows', function (): void {
    [$app] = storageApp();
    $reader = telltaleMcpCredential([TelltaleAbility::Read->value]);
    config(['telltale.storage.raw_event_retention_days' => 7]);
    expect(telltaleMcpTool('crash_free_users', ['app_id' => $app->id], $reader)['from'])->toBe('2026-10-23T12:00:00+00:00');
    foreach (['crash_groups', 'crash_free_users'] as $name) {
        foreach ([
            ['from' => '2026-10-23T11:59:59Z'],
            ['from' => '2026-10-29T12:00:00Z', 'to' => '2026-10-29T12:00:00Z'],
            ['from' => '2026-10-29T12:00:00Z', 'to' => '2026-10-29T11:00:00Z'],
            ['to' => '2026-10-31T12:00:00Z'],
            ['from' => '2026-10-29'],
        ] as $window) {
            telltaleMcpRequest('/mcp', mcpToolPayload($name, ['app_id' => $app->id, ...$window]), $reader)
                ->assertOk()->assertJsonPath('result.isError', true);
        }
        $old = telltaleMcpRequest('/mcp', mcpToolPayload($name, ['app_id' => $app->id, 'from' => '2026-10-22T12:00:00Z']), $reader)->assertOk();
        expect(strtolower($old->json('result.content.0.text')))->toContain('retention limit');
    }
    foreach ([0, 51] as $limit) {
        telltaleMcpRequest('/mcp', mcpToolPayload('crash_groups', ['app_id' => $app->id, 'limit' => $limit]), $reader)
            ->assertOk()->assertJsonPath('result.isError', true);
    }
    config(['telltale.storage.raw_event_retention_days' => 500]);
    telltaleMcpRequest('/mcp', mcpToolPayload('crash_groups', ['app_id' => $app->id, 'from' => '2025-10-28T12:00:00Z']), $reader)
        ->assertOk()->assertJsonPath('result.isError', true);
});

it('deletes and prunes all retained native samples and derives groups only from surviving raw events', function (): void {
    [$app, $key] = storageApp();
    [$first, $token] = storageInstall($app, $key);
    [, $other] = storageInstall($app, $key);
    $old = crashEvent(crashReport(['occurred_at' => '2026-07-01T00:00:00Z']));
    $deleted = crashEvent(crashReport(['trace' => 'deleted-install-canary']));
    $kept = crashEvent(crashReport(['trace' => 'retained-install-canary']));
    $this->withToken($token)->postJson('/api/ingest', crashEnvelope([$deleted]))->assertAccepted();
    $this->withToken($other)->postJson('/api/ingest', crashEnvelope([$old, $kept]))->assertAccepted();
    $query = resolve(CrashQuery::class);
    [$from, $to] = $query->window(null, null);
    resolve(InstallDataDeleter::class)->delete($app, $first->install_uuid);
    expect(StoredEvent::query()->where('install_id', $first->id)->count())->toBe(0)
        ->and(fn () => $query->detail($app, $deleted['event_id']))->toThrow(ModelNotFoundException::class)
        ->and($query->groups($app, $from, $to, 25)['groups'][0]['count'])->toBe(1)
        ->and(json_encode($query->groups($app, $from, $to, 25)))->not->toContain('deleted-install-canary');
    expect(resolve(RawEventPruner::class)->prune(CarbonImmutable::now('UTC')->subDays(90)))->toBe(1)
        ->and(StoredEvent::query()->where('event_id', $old['event_id'])->exists())->toBeFalse()
        ->and($query->detail($app, $kept['event_id'])['crash']['trace'])->toBe('retained-install-canary');
    expect(fn () => $query->detail($app, $old['event_id']))->toThrow(ModelNotFoundException::class);
    resolve(RawEventPruner::class)->prune(CarbonImmutable::now('UTC'));
    expect(StoredEvent::query()->count())->toBe(0)
        ->and($query->groups($app, $from, $to, 25)['groups'])->toBe([])
        ->and($query->freeUsers($app, $from, $to)['crash_free_percentage'])->toBeNull();
});

it('rejects windows already pruned even after retention increases and keeps a monotonic prune watermark', function (): void {
    $query = resolve(CrashQuery::class);
    $cutoff = CarbonImmutable::parse('2026-10-28T12:00:00Z');
    resolve(RawEventPruner::class)->prune($cutoff);
    resolve(RawEventPruner::class)->prune($cutoff->subDays(10));
    config(['telltale.storage.raw_event_retention_days' => 365]);

    expect(fn () => $query->window('2026-10-28T11:59:59Z', null))->toThrow(ValidationException::class)
        ->and($query->window('2026-10-28T12:00:00Z', null)[0]->equalTo($cutoff))->toBeTrue();
});

it('serves a known two-install crash-free MCP window without counting repeated fatal reports twice', function (): void {
    [$app, $key] = storageApp();
    [, $first] = storageInstall($app, $key);
    [, $second] = storageInstall($app, $key);
    $this->withToken($first)->postJson('/api/ingest', crashEnvelope([
        crashEvent(crashReport()), crashEvent(crashReport()),
    ]))->assertAccepted();
    $this->withToken($second)->postJson('/api/ingest', storageEnvelope([
        storageEvent('active', 'event', '2026-10-29T10:00:00Z', 'active'),
    ]))->assertAccepted();
    $reader = telltaleMcpCredential([TelltaleAbility::Read->value]);
    expect(telltaleMcpTool('crash_free_users', ['app_id' => $app->id], $reader))->toMatchArray([
        'active_users' => 2, 'affected_users' => 1, 'crash_free_users' => 1, 'crash_free_percentage' => 50.0,
    ]);
});
