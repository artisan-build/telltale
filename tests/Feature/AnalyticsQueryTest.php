<?php

declare(strict_types=1);

use App\Domain\Analytics\FunnelQuery;
use App\Domain\Analytics\RetentionQuery;
use App\Models\DailyActiveInstall;
use App\Models\DailyNewInstall;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedApp;
use Carbon\CarbonImmutable;

it('returns known ordered funnel conversion and drop-off figures', function (): void {
    $app = TrackedApp::factory()->create();
    $base = CarbonImmutable::parse('2026-09-01T10:00:00Z');
    $sequences = [
        ['opened', 'signed-up', 'subscribed'],
        ['opened', 'signed-up', 'subscribed'],
        ['opened', 'signed-up'],
        ['opened'],
    ];

    foreach ($sequences as $installIndex => $sequence) {
        $install = Install::factory()->for($app, 'app')->create();

        foreach ($sequence as $eventIndex => $name) {
            StoredEvent::factory()->for($app, 'app')->for($install, 'install')->create([
                'name' => $name,
                'occurred_at' => $base->addHours($installIndex)->addMinutes($eventIndex * 5),
            ]);
        }
    }

    $result = resolve(FunnelQuery::class)->run(
        $app,
        ['opened', 'signed-up', 'subscribed'],
        $base->subMinute(),
        $base->addDay(),
        30,
    );

    expect($result)->toBe([
        ['step' => 'opened', 'reached' => 4, 'conversion_rate' => 100.0, 'drop_off' => 0],
        ['step' => 'signed-up', 'reached' => 3, 'conversion_rate' => 75.0, 'drop_off' => 1],
        ['step' => 'subscribed', 'reached' => 2, 'conversion_rate' => 50.0, 'drop_off' => 1],
    ]);
});

it('returns known first-seen-week retention cohorts from durable active facts', function (): void {
    $app = TrackedApp::factory()->create();
    $first = Install::factory()->for($app, 'app')->create();
    $second = Install::factory()->for($app, 'app')->create();
    $third = Install::factory()->for($app, 'app')->create();

    foreach ([
        [$first->id, '2026-01-05'],
        [$second->id, '2026-01-06'],
        [$third->id, '2026-01-12'],
    ] as [$installId, $date]) {
        DailyNewInstall::query()->create([
            'app_id' => $app->id,
            'install_id' => $installId,
            'first_seen_on' => $date,
            'app_version' => '1.0.0',
        ]);
    }

    foreach ([
        [$first->id, '2026-01-05'], [$first->id, '2026-01-12'], [$first->id, '2026-01-19'],
        [$second->id, '2026-01-06'], [$second->id, '2026-01-20'],
        [$third->id, '2026-01-12'], [$third->id, '2026-01-19'],
    ] as [$installId, $date]) {
        DailyActiveInstall::query()->create([
            'app_id' => $app->id,
            'install_id' => $installId,
            'active_date' => $date,
            'app_version' => '1.0.0',
            'platform' => 'mobile',
            'os' => 'iOS',
        ]);
    }

    $result = resolve(RetentionQuery::class)->cohorts(
        $app,
        CarbonImmutable::parse('2026-01-05'),
        CarbonImmutable::parse('2026-01-12'),
        2,
    );

    expect($result)->toBe([
        [
            'cohort_week' => '2026-01-05',
            'cohort_size' => 2,
            'weeks' => [
                ['week' => 0, 'returned' => 2, 'retention_rate' => 100.0],
                ['week' => 1, 'returned' => 1, 'retention_rate' => 50.0],
                ['week' => 2, 'returned' => 2, 'retention_rate' => 100.0],
            ],
        ],
        [
            'cohort_week' => '2026-01-12',
            'cohort_size' => 1,
            'weeks' => [
                ['week' => 0, 'returned' => 1, 'retention_rate' => 100.0],
                ['week' => 1, 'returned' => 1, 'retention_rate' => 100.0],
                ['week' => 2, 'returned' => 0, 'retention_rate' => 0.0],
            ],
        ],
    ]);
});
