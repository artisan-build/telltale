<?php

declare(strict_types=1);

use App\Domain\Ingest\AppManager;
use App\Domain\Ingest\Credential as IngestCredential;
use App\Models\IngestHealthDaily;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedApp;
use App\Support\SetupInstructions;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-03T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('serves an honest public landing without management state and protects package pages', function (): void {
    $app = TrackedApp::factory()->create(['name' => 'Private app name']);

    $this->get('/')
        ->assertOk()
        ->assertSeeHtml('data-testid="landing"')
        ->assertSee('Native crashes are not captured in v1.')
        ->assertSeeHtml('href="'.route('bfc.dashboard').'"')
        ->assertDontSee($app->name)
        ->assertDontSee('Create app')
        ->assertDontSee($app->ingest_key_hash);

    $this->get('/dashboard')->assertRedirect(route('bfc.login', ['intended' => '/dashboard']));
    $this->get('/setup')->assertRedirect(route('bfc.login'));
});

it('creates and rotates through the existing manager and reveals each plaintext value only in its response', function (): void {
    signIntoPackageUi($this);

    $createdResponse = $this->post('/dashboard/apps', ['name' => 'Dreiland'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertSeeHtml('data-testid="credential-reveal"');
    $createdValue = ingestValueFrom($createdResponse->getContent());
    $app = TrackedApp::query()->where('name', 'Dreiland')->sole();

    expect(substr_count((string) $createdResponse->getContent(), $createdValue))->toBe(1)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($createdValue)
        ->and($app->ingest_key_hash)->toBe(IngestCredential::hash($createdValue))
        ->and($app->ingest_key_hash)->not->toBe($createdValue);

    $this->get('/dashboard')->assertOk()->assertDontSee($createdValue);
    $this->get(route('telltale.apps.show', $app))->assertOk()->assertDontSee($createdValue);
    $this->get('/setup')->assertOk()->assertDontSee($createdValue);

    $rotatedResponse = $this->post(route('telltale.apps.rotate', $app))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Ingest value rotated');
    $rotatedValue = ingestValueFrom($rotatedResponse->getContent());

    expect(substr_count((string) $rotatedResponse->getContent(), $rotatedValue))->toBe(1)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($rotatedValue)
        ->and($rotatedValue)->not->toBe($createdValue)
        ->and($app->refresh()->ingest_key_hash)->toBe(IngestCredential::hash($rotatedValue));

    $this->get(route('telltale.apps.show', $app))
        ->assertOk()
        ->assertDontSee($createdValue)
        ->assertDontSee($rotatedValue);

    $refreshedPost = $this->post(route('telltale.apps.rotate', $app))->assertOk();
    expect(ingestValueFrom($refreshedPost->getContent()))->not->toBe($rotatedValue);
});

it('keeps app and recent health reads scoped and bounded with truthful empty states', function (): void {
    signIntoPackageUi($this);
    $manager = resolve(AppManager::class);
    $first = $manager->create('Scoped first')->app();
    $second = $manager->create('Scoped second')->app();

    IngestHealthDaily::query()->create([
        'app_id' => $first->id,
        'health_date' => now()->toDateString(),
        'rejection_count' => 4321,
        'rate_limit_count' => 2345,
    ]);
    IngestHealthDaily::query()->create([
        'app_id' => $second->id,
        'health_date' => now()->toDateString(),
        'rejection_count' => 8765,
        'rate_limit_count' => 5678,
    ]);
    Install::factory()->create(['app_id' => $first->id, 'dropped_events_total' => 3456]);
    Install::factory()->create(['app_id' => $second->id, 'dropped_events_total' => 7654]);

    $install = Install::factory()->create(['app_id' => $first->id]);
    foreach (range(0, 25) as $index) {
        StoredEvent::factory()->create([
            'app_id' => $first->id,
            'install_id' => $install->id,
            'client_version' => sprintf('client-%02d', $index),
            'occurred_at' => now()->subMinutes(25 - $index),
        ]);
    }

    $response = $this->get(route('telltale.apps.show', $first))->assertOk();
    $content = $response->getContent();

    expect($content)->toContain('4321', '2345', '3456', 'client-25')
        ->toContain(
            'Known app-attributed rejections',
            'Known app-attributed rate-limit hits',
            'Latest retained event',
            'No retained events',
        )
        ->not->toContain('8765', '5678', '7654', 'client-00', 'Last ingest', 'No ingest yet')
        ->and(substr_count($content, 'client-'))->toBe(25);

    TrackedApp::query()->delete();
    $this->get('/dashboard')
        ->assertOk()
        ->assertSeeHtml('data-testid="app-empty"')
        ->assertSee('No apps yet.');
});

it('returns validation errors for invalid UTF-8 app names', function (): void {
    signIntoPackageUi($this);

    $this->from('/dashboard')
        ->post('/dashboard/apps', ['name' => "Invalid\xFFname"])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors([
            'name' => 'The app name must be valid UTF-8 text no longer than 255 bytes.',
        ]);

    expect(TrackedApp::query()->count())->toBe(0);
});

it('caps the app list at one hundred rows', function (): void {
    signIntoPackageUi($this);
    TrackedApp::factory()->count(101)->sequence(
        fn ($sequence): array => ['name' => sprintf('Bounded app %03d', $sequence->index)],
    )->create();

    $content = $this->get('/dashboard')->assertOk()->getContent();

    expect(substr_count($content, 'href="'.url('/dashboard/apps').'/'))->toBe(100)
        ->and($content)->toContain('Bounded app 099')
        ->not->toContain('Bounded app 100');
});

it('refuses both ingest credential forms before any package UI database query', function (): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $paths = ['/', '/dashboard', '/dashboard/apps/1', '/setup', '/settings'];
    $credentials = [
        'tti_'.str_repeat('a', 64),
        'ttx_'.str_repeat('b', 64),
    ];

    foreach ($credentials as $credential) {
        foreach ($paths as $path) {
            $this->withToken($credential)->get($path)->assertForbidden()->assertExactJson([
                'message' => 'Forbidden.',
            ]);
        }
    }

    expect($queries)->toBe([]);
});

it('renders every canonical setup step without offering integration automation', function (): void {
    signIntoPackageUi($this);
    $instructions = resolve(SetupInstructions::class);
    $response = $this->get('/setup')
        ->assertOk()
        ->assertSeeHtml('data-testid="setup-steps"')
        ->assertSeeHtml('data-testid="automation-boundary"')
        ->assertSeeHtml('data-testid="v1-boundaries"');

    foreach ($instructions->steps() as $step) {
        $response->assertSee($step['title'])->assertSee($step['body']);

        if ($step['code'] !== null) {
            $response->assertSee($step['code']);
        }
    }

    $response->assertSee($instructions->automationBoundary())
        ->assertSee($instructions->v1LimitSummary())
        ->assertDontSee('Connect repository')
        ->assertDontSee('Inject credential')
        ->assertDontSeeHtml('<canvas');
});

function signIntoPackageUi(TestCase $test): User
{
    $user = User::query()->create([
        'name' => 'Test-created owner',
        'email' => fake()->unique()->safeEmail(),
        'password' => Hash::make('test-created-password'),
    ]);
    $user->forceFill([
        'role' => UserRole::Owner->value,
        'status' => 'active',
        'email_verified_at' => now(),
        'original_contact_email' => $user->email,
    ])->save();
    $user->refresh();

    $test->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    return $user;
}

function ingestValueFrom(string $content): string
{
    preg_match('/tti_[a-f0-9]{64}/', $content, $matches);

    expect($matches[0] ?? null)->toBeString();

    return $matches[0];
}
