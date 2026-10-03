<?php

declare(strict_types=1);

use App\Domain\Ingest\AppManager;
use App\Domain\Ingest\Credential;
use App\Domain\Ingest\IngestRequestLimiter;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedApp;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * @param  array{rate_per_minute?: int, install_rate_per_minute?: int, daily_event_cap?: int, install_daily_event_cap?: int}  $limits
 * @return array{TrackedApp, string}
 */
function createIngestApp(array $limits = []): array
{
    $created = resolve(AppManager::class)->create('Test app '.Str::random(8), $limits);

    return [$created->app(), $created->ingestValue()];
}

function registerInstall(string $ingestValue, ?string $installUuid = null): array
{
    $response = test()->postJson('/api/register', [
        'install_id' => $installUuid ?? (string) Str::uuid(),
    ], ['X-Telltale-Ingest' => $ingestValue]);

    $response->assertSuccessful();

    return $response->json();
}

/** @return array<string, mixed> */
function ingestEvent(string $eventId = '01ARZ3NDEKTSV4RRFFQ69G5FAV', array $overrides = []): array
{
    return array_replace([
        'event_id' => $eventId,
        'name' => 'checkout.completed',
        'type' => 'event',
        'ts' => '2026-10-03T12:34:56.123Z',
        'session_id' => 'session-1',
        'props' => ['plan' => 'pro'],
    ], $overrides);
}

/** @return array<string, mixed> */
function ingestEnvelope(array $events = [], int $dropped = 0, array $overrides = []): array
{
    return array_replace([
        'envelope_version' => 1,
        'client_version' => '1.2.3',
        'dropped_events_total' => $dropped,
        'events' => $events === [] ? [ingestEvent()] : $events,
    ], $overrides);
}

function useExplodingDefaultGuard(): void
{
    Auth::extend('exploding', fn (): never => throw new RuntimeException('A public route resolved the auth guard.'));
    config([
        'auth.defaults.guard' => 'exploding',
        'auth.guards.exploding' => ['driver' => 'exploding'],
    ]);
}

it('registers without resolving a user and retries by rotating the install token', function (): void {
    useExplodingDefaultGuard();
    [$app, $ingestValue] = createIngestApp();
    $uuid = (string) Str::uuid();

    $first = $this->postJson('/api/register', [
        'install_id' => $uuid,
        'app_id' => 999999,
    ], ['X-Telltale-Ingest' => $ingestValue])->assertCreated();
    $firstToken = $first->json('install_token');

    $second = $this->postJson('/api/register', [
        'install_id' => $uuid,
    ], ['X-Telltale-Ingest' => $ingestValue])->assertOk();
    $secondToken = $second->json('install_token');

    expect($firstToken)->toBeString()->toMatch('/^ttx_[a-f0-9]{64}$/')
        ->and($secondToken)->toBeString()->not->toBe($firstToken)
        ->and(Install::query()->where('app_id', $app->id)->sole()->token_hash)->toBe(Credential::hash($secondToken));

    $this->withToken($firstToken)->postJson('/api/ingest', ingestEnvelope())
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid credentials.']);

    $this->withToken($secondToken)->postJson('/api/ingest', ingestEnvelope())
        ->assertAccepted();
});

it('returns generic failures for missing malformed and revoked credentials', function (): void {
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];

    $this->postJson('/api/register', ['install_id' => (string) Str::uuid()])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid credentials.']);
    $this->withHeader('X-Telltale-Ingest', 'not-a-key')
        ->postJson('/api/register', ['install_id' => (string) Str::uuid()])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid credentials.']);
    $this->withHeader('X-Telltale-Ingest', $ingestValue)
        ->postJson('/api/register', ['install_id' => 'not-a-uuid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('install_id');

    Install::query()->sole()->update(['revoked_at' => now()]);

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope())
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid credentials.']);

    $this->withHeader('X-Telltale-Ingest', $ingestValue)
        ->postJson('/api/register', ['install_id' => Install::query()->sole()->install_uuid])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid credentials.']);
});

it('stores only credential-derived app and install identity', function (): void {
    [$realApp, $ingestValue] = createIngestApp();
    [$otherApp] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];
    $install = Install::query()->where('app_id', $realApp->id)->sole();

    $payload = ingestEnvelope(overrides: [
        'app_id' => $otherApp->id,
        'install_id' => 999999,
        'events' => [ingestEvent(overrides: [
            'app_id' => $otherApp->id,
            'install_id' => 999999,
        ])],
    ]);

    $this->withToken($token)->postJson('/api/ingest', $payload)->assertAccepted();

    $stored = StoredEvent::query()->sole();

    expect($stored->app_id)->toBe($realApp->id)
        ->and($stored->install_id)->toBe($install->id)
        ->and($stored->app_id)->not->toBe($otherApp->id);
});

it('accepts replay without duplicate rows and keeps dropped totals monotonic', function (): void {
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope(dropped: 8))
        ->assertAccepted()
        ->assertJson(['accepted' => 1, 'duplicates' => 0, 'dropped_events_total' => 8]);
    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope(dropped: 3))
        ->assertAccepted()
        ->assertJson(['accepted' => 0, 'duplicates' => 1, 'dropped_events_total' => 8]);

    expect(StoredEvent::query()->count())->toBe(1)
        ->and(Install::query()->sole()->dropped_events_total)->toBe(8);
});

it('accepts client-format gzip envelopes and preserves plain JSON ingest', function (): void {
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];
    $gzipEnvelope = ingestEnvelope();
    $json = json_encode($gzipEnvelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $compressed = gzencode($json);
    expect($compressed)->toBeString();

    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => strlen($compressed),
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_CONTENT_ENCODING' => 'gzip',
    ], content: $compressed)->assertAccepted()->assertJson([
        'accepted' => 1,
        'duplicates' => 0,
    ]);

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope([
        ingestEvent('01ARZ3NDEKTSV4RRFFQ69G5FAW'),
    ]))->assertAccepted()->assertJson([
        'accepted' => 1,
        'duplicates' => 0,
    ]);

    expect(StoredEvent::query()->orderBy('id')->pluck('event_id')->all())->toBe([
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        '01ARZ3NDEKTSV4RRFFQ69G5FAW',
    ]);
});

it('rejects malformed gzip and unsupported encodings generically without resolving a user', function (): void {
    useExplodingDefaultGuard();

    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
    ], content: 'not-gzip')->assertBadRequest()->assertExactJson([
        'message' => 'The compressed request body is invalid.',
    ]);

    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'br',
    ], content: '{}')->assertStatus(415)->assertExactJson([
        'message' => 'Content encoding is not supported.',
    ]);
});

it('bounds both compressed and decoded gzip bodies before credential lookup', function (): void {
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];
    $json = json_encode(ingestEnvelope([
        ingestEvent(overrides: ['props' => ['payload' => str_repeat('x', 4_000)]]),
    ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $compressed = gzencode($json);
    expect($compressed)->toBeString()
        ->and(strlen($compressed))->toBeLessThan(512)
        ->and(strlen($json))->toBeGreaterThan(512);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    config(['telltale.ingest.max_body_bytes' => strlen($compressed) - 1]);
    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => strlen($compressed),
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_CONTENT_ENCODING' => 'gzip',
    ], content: $compressed)->assertStatus(413)->assertExactJson([
        'message' => 'Request body is too large.',
    ]);

    config(['telltale.ingest.max_body_bytes' => 512]);
    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => strlen($compressed),
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_CONTENT_ENCODING' => 'gzip',
    ], content: $compressed)->assertStatus(413)->assertExactJson([
        'message' => 'Request body is too large.',
    ]);

    expect(collect($queries)->contains(fn (string $query): bool => str_contains($query, 'installs')))->toBeFalse();
});

it('rejects malformed and unsupported envelopes with actionable validation errors', function (): void {
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];

    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], content: '{')->assertUnprocessable()->assertJson(['message' => 'The envelope is not valid JSON.']);

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope(overrides: ['envelope_version' => 2]))
        ->assertUnprocessable()
        ->assertJson([
            'supported_envelope_version' => 1,
        ])->assertJsonPath('message', 'Envelope version 2 is newer than this server supports. Upgrade the Telltale server before retrying.');

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope(overrides: ['events' => 'invalid']))
        ->assertUnprocessable()
        ->assertJson(['message' => 'events must be a list.']);
});

it('accepts storage string boundaries and rejects overlong or NUL values with 422', function (): void {
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope([
        ingestEvent(overrides: [
            'name' => str_repeat('n', 255),
            'session_id' => str_repeat('s', 255),
        ]),
    ], overrides: ['client_version' => str_repeat('v', 255)]))->assertAccepted();

    foreach ([
        ingestEnvelope([ingestEvent(overrides: ['name' => str_repeat('n', 256)])]),
        ingestEnvelope([ingestEvent(overrides: ['session_id' => str_repeat('s', 256)])]),
        ingestEnvelope(overrides: ['client_version' => str_repeat('v', 256)]),
        ingestEnvelope([ingestEvent(overrides: ['props' => ['invalid' => "before\0after"]])]),
    ] as $payload) {
        $this->withToken($token)->postJson('/api/ingest', $payload)->assertUnprocessable();
    }

    expect(StoredEvent::query()->count())->toBe(1);
});

it('counts oversized requests against the IP cap before enforcing body and credential bounds', function (): void {
    useExplodingDefaultGuard();
    config([
        'telltale.ingest.max_body_bytes' => 20,
        'telltale.ingest.max_events_per_batch' => 1,
        'telltale.ingest.ip_rate_per_minute' => 1,
    ]);

    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => 21,
    ], content: str_repeat('x', 21))->assertStatus(413);
    $this->call('POST', '/api/ingest', server: [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => 21,
    ], content: str_repeat('x', 21))->assertTooManyRequests();

    RateLimiter::clear('telltale:ip:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')));
    config([
        'telltale.ingest.max_body_bytes' => 100_000,
        'telltale.ingest.ip_rate_per_minute' => 240,
    ]);
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];
    $payload = ingestEnvelope([
        ingestEvent(),
        ['future' => 'schema is not parsed once count is over the cap'],
    ]);

    $this->withToken($token)->postJson('/api/ingest', $payload)
        ->assertUnprocessable()
        ->assertJson(['message' => 'A batch may contain at most 1 events.']);
});

it('enforces atomic IP app and install minute limits', function (): void {
    useExplodingDefaultGuard();
    config(['telltale.ingest.ip_rate_per_minute' => 1]);

    $this->postJson('/api/register', [])->assertUnauthorized();
    $this->postJson('/api/register', [])->assertTooManyRequests()->assertHeader('Retry-After');

    RateLimiter::clear('telltale:ip:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')));
    config(['telltale.ingest.ip_rate_per_minute' => 240]);
    [, $appLimitedValue] = createIngestApp(['rate_per_minute' => 1]);

    $this->postJson('/api/register', ['install_id' => (string) Str::uuid()], [
        'X-Telltale-Ingest' => $appLimitedValue,
    ])->assertCreated();
    $this->postJson('/api/register', ['install_id' => (string) Str::uuid()], [
        'X-Telltale-Ingest' => $appLimitedValue,
    ])->assertTooManyRequests();

    [, $installLimitedValue] = createIngestApp([
        'rate_per_minute' => 20,
        'install_rate_per_minute' => 1,
    ]);
    $token = registerInstall($installLimitedValue)['install_token'];

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope())->assertAccepted();
    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope([
        ingestEvent('01ARZ3NDEKTSV4RRFFQ69G5FAW'),
    ]))->assertTooManyRequests();
});

it('enforces app and install daily volume while duplicates consume no volume', function (): void {
    useExplodingDefaultGuard();
    [, $appLimitedValue] = createIngestApp([
        'daily_event_cap' => 1,
        'install_daily_event_cap' => 10,
    ]);
    $appToken = registerInstall($appLimitedValue)['install_token'];

    $volumeResponse = $this->withToken($appToken)->postJson('/api/ingest', ingestEnvelope([
        ingestEvent(),
        ingestEvent('01ARZ3NDEKTSV4RRFFQ69G5FAW'),
    ]))->assertTooManyRequests()->assertHeader('Retry-After');
    expect((int) $volumeResponse->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(86_400);
    expect(StoredEvent::query()->count())->toBe(0);

    [, $installLimitedValue] = createIngestApp([
        'daily_event_cap' => 10,
        'install_daily_event_cap' => 2,
    ]);
    $installToken = registerInstall($installLimitedValue)['install_token'];

    $this->withToken($installToken)->postJson('/api/ingest', ingestEnvelope())->assertAccepted();
    $this->withToken($installToken)->postJson('/api/ingest', ingestEnvelope([
        ingestEvent(),
        ingestEvent('01ARZ3NDEKTSV4RRFFQ69G5FAX'),
    ]))->assertAccepted()->assertJson(['accepted' => 1, 'duplicates' => 1]);
    $this->withToken($installToken)->postJson('/api/ingest', ingestEnvelope([
        ingestEvent('01ARZ3NDEKTSV4RRFFQ69G5FAY'),
    ]))->assertTooManyRequests();

    expect(StoredEvent::query()->count())->toBe(2);
});

it('fails closed without resolving a user when global protection configuration is invalid', function (): void {
    useExplodingDefaultGuard();

    foreach ([0, -1, 'invalid'] as $invalid) {
        config([
            'telltale.ingest.max_body_bytes' => $invalid,
            'telltale.ingest.ip_rate_per_minute' => 240,
        ]);

        $this->postJson('/api/register', [])->assertServiceUnavailable();
    }

    foreach ([0, -1, 'invalid'] as $invalid) {
        config([
            'telltale.ingest.max_body_bytes' => 1_048_576,
            'telltale.ingest.ip_rate_per_minute' => $invalid,
        ]);

        $this->postJson('/api/register', [])->assertServiceUnavailable();
    }
});

it('persists no plaintext credentials or IP addresses', function (): void {
    [$app, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];
    $install = Install::query()->sole();

    expect($app->ingest_key_hash)->toBe(Credential::hash($ingestValue))
        ->and($install->token_hash)->toBe(Credential::hash($token))
        ->and($app->getAttributes())->not->toContain($ingestValue)
        ->and($install->getAttributes())->not->toContain($token)
        ->and($install->toJson())->not->toContain($install->token_hash)
        ->and(DB::getSchemaBuilder()->getColumnListing('apps'))->not->toContain('ip')
        ->and(DB::getSchemaBuilder()->getColumnListing('installs'))->not->toContain('ip')
        ->and(DB::getSchemaBuilder()->getColumnListing('events'))->not->toContain('ip');
});

it('masks credential hash bindings in PostgreSQL query exceptions', function (): void {
    $credentialHash = str_repeat('a', 64);

    try {
        TrackedApp::query()
            ->where('ingest_key_hash', $credentialHash)
            ->whereRaw('missing_ingest_function()')
            ->first();
    } catch (QueryException $exception) {
        expect($exception->getMessage())->not->toContain($credentialHash);

        return;
    }

    $this->fail('The contained failing credential query did not throw.');
});

it('uses an irreversible IP cache key when the production database cache is used', function (): void {
    $limiter = new IngestRequestLimiter(new LaravelRateLimiter(Cache::store('database')));
    $request = Request::create('/api/register', 'POST', server: ['REMOTE_ADDR' => '203.0.113.41']);

    $limiter->consumeIp($request);

    $keys = DB::table('cache')->pluck('key');

    expect($keys)->not->toBeEmpty()
        ->and($keys->contains(fn (string $key): bool => str_contains($key, '203.0.113.41')))->toBeFalse();
});

it('uses row locks and database uniqueness as concurrent ingest safeguards', function (): void {
    [, $ingestValue] = createIngestApp();
    $token = registerInstall($ingestValue)['install_token'];
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->postJson('/api/ingest', ingestEnvelope())->assertAccepted();

    expect(collect($queries)->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'for update')))->toHaveCount(2);

    $stored = StoredEvent::query()->sole();

    expect(fn () => StoredEvent::query()->create([
        'app_id' => $stored->app_id,
        'install_id' => $stored->install_id,
        'event_id' => $stored->event_id,
        'name' => 'duplicate',
        'type' => 'event',
        'occurred_at' => now(),
        'session_id' => 'session-2',
        'props' => [],
        'client_version' => '1.2.3',
    ]))->toThrow(QueryException::class);
});

it('rejects an event whose install belongs to a different app', function (): void {
    $event = StoredEvent::factory()->make();
    $otherApp = TrackedApp::factory()->create();

    expect(fn () => $event->forceFill(['app_id' => $otherApp->id])->save())
        ->toThrow(QueryException::class);
});
