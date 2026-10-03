<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Enums\AggregateDimension;
use App\Models\DailyActiveInstall;
use App\Models\DailyAggregate;
use App\Models\ErrorGroup;
use App\Models\ErrorGroupInstall;
use App\Models\IngestHealthDaily;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedApp;
use App\Models\TrackedSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class McpAnalytics
{
    public function __construct(
        private FunnelQuery $funnels,
        private RetentionQuery $retention,
        private ErrorGroupQuery $errors,
    ) {}

    /** @return list<array<string, mixed>> */
    public function apps(): array
    {
        return TrackedApp::query()
            ->withMax('events', 'occurred_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(function (TrackedApp $app): array {
                $versions = DailyActiveInstall::query()
                    ->where('app_id', $app->id)
                    ->where('active_date', '>=', CarbonImmutable::now('UTC')->subDays(29)->toDateString())
                    ->where('app_version', '!=', '<unknown>')
                    ->distinct()
                    ->orderBy('app_version')
                    ->limit(25)
                    ->pluck('app_version')
                    ->all();

                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'last_seen_at' => $app->getAttribute('events_max_occurred_at'),
                    'live_versions' => $versions,
                    'dropped_events_total' => (int) $app->installs()->sum('dropped_events_total'),
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    public function ingestHealth(TrackedApp $app, int $days): array
    {
        $from = CarbonImmutable::now('UTC')->subDays($days - 1)->startOfDay();
        $counters = IngestHealthDaily::query()
            ->where('app_id', $app->id)
            ->where('health_date', '>=', $from->toDateString())
            ->selectRaw('COALESCE(SUM(rejection_count), 0) AS rejections')
            ->selectRaw('COALESCE(SUM(rate_limit_count), 0) AS rate_limits')
            ->first();
        $versions = StoredEvent::query()
            ->where('app_id', $app->id)
            ->where('occurred_at', '>=', $from)
            ->selectRaw('client_version, MAX(occurred_at) AS last_seen_at')
            ->groupBy('client_version')
            ->latest('last_seen_at')
            ->limit(25)
            ->get()
            ->map(fn (StoredEvent $event): array => [
                'version' => $event->client_version,
                'last_seen_at' => $event->getAttribute('last_seen_at'),
            ])
            ->all();

        return [
            'app_id' => $app->id,
            'window_days' => $days,
            'dropped_events_total' => (int) $app->installs()->sum('dropped_events_total'),
            'rejections' => (int) ($counters?->getAttribute('rejections') ?? 0),
            'rate_limit_hits' => (int) ($counters?->getAttribute('rate_limits') ?? 0),
            'newest_client_versions' => $versions,
        ];
    }

    /** @return array<string, mixed> */
    public function eventCounts(TrackedApp $app, int $days, string $metric, string $breakdown): array
    {
        $from = CarbonImmutable::now('UTC')->subDays($days - 1)->toDateString();
        $metricDimension = $metric === 'screen'
            ? AggregateDimension::Screen
            : AggregateDimension::EventName;
        $breakdownDimension = $metric === 'screen'
            ? match ($breakdown) {
                'version' => AggregateDimension::ScreenVersion,
                'platform' => AggregateDimension::ScreenPlatform,
                'os' => AggregateDimension::ScreenOperatingSystem,
                'locale' => AggregateDimension::ScreenLocale,
                default => null,
            }
        : ($breakdown === 'none' ? null : AggregateDimension::from($breakdown));

        return [
            'app_id' => $app->id,
            'window_days' => $days,
            'metric' => $metric,
            'counts' => $this->aggregateRows($app, $from, $metricDimension),
            'breakdown' => $breakdownDimension === null
                ? []
                : $this->aggregateRows($app, $from, $breakdownDimension),
        ];
    }

    /** @return array<string, mixed> */
    public function activeUsers(TrackedApp $app, CarbonImmutable $asOf): array
    {
        $periods = ['dau' => 1, 'wau' => 7, 'mau' => 30];
        $counts = [];

        foreach ($periods as $name => $days) {
            $counts[$name] = DailyActiveInstall::query()
                ->where('app_id', $app->id)
                ->whereBetween('active_date', [
                    $asOf->subDays($days - 1)->toDateString(),
                    $asOf->toDateString(),
                ])
                ->distinct('install_id')
                ->count('install_id');
        }

        return [
            'app_id' => $app->id,
            'as_of' => $asOf->toDateString(),
            ...$counts,
            'by_version' => $this->activeBreakdown($app, $asOf, 'app_version'),
            'by_platform' => $this->activeBreakdown($app, $asOf, 'platform'),
        ];
    }

    /** @return array<string, mixed> */
    public function releaseAdoption(TrackedApp $app, int $days): array
    {
        $from = CarbonImmutable::now('UTC')->subDays($days - 1)->toDateString();
        $versions = DailyActiveInstall::query()
            ->where('app_id', $app->id)
            ->where('active_date', '>=', $from)
            ->distinct()
            ->orderBy('app_version')
            ->limit(101)
            ->pluck('app_version');
        $versionsTruncated = $versions->count() > 100;
        $versions = $versions->take(100);
        $rows = DailyActiveInstall::query()
            ->where('app_id', $app->id)
            ->where('active_date', '>=', $from)
            ->whereIn('app_version', $versions)
            ->selectRaw('active_date, app_version, COUNT(DISTINCT install_id) AS active_installs')
            ->groupBy('active_date', 'app_version')
            ->oldest('active_date')
            ->orderBy('app_version')
            ->limit(2501)
            ->get();
        $truncated = $rows->count() > 2500;
        $rows = $rows->take(2500);
        $totals = DailyActiveInstall::query()
            ->where('app_id', $app->id)
            ->where('active_date', '>=', $from)
            ->selectRaw('active_date, COUNT(DISTINCT install_id) AS active_installs')
            ->groupBy('active_date')
            ->pluck('active_installs', 'active_date');
        $firstAdoptions = DailyActiveInstall::query()
            ->where('app_id', $app->id)
            ->whereIn('app_version', $versions)
            ->selectRaw('app_version, install_id, MIN(active_date) AS first_adopted_on')
            ->groupBy('app_version', 'install_id');
        $speed = DB::query()->fromSub($firstAdoptions, 'first_adoptions')
            ->selectRaw('app_version, MIN(first_adopted_on) AS first_seen_on')
            ->selectRaw('PERCENTILE_DISC(0.5) WITHIN GROUP (ORDER BY first_adopted_on) AS median_adopted_on')
            ->groupBy('app_version')
            ->orderBy('app_version')
            ->limit(100)
            ->get()
            ->map(function (object $row): array {
                $first = CarbonImmutable::parse((string) $row->first_seen_on);
                $median = CarbonImmutable::parse((string) $row->median_adopted_on);

                return [
                    'version' => (string) $row->app_version,
                    'first_seen_on' => $first->toDateString(),
                    'median_days_from_first_seen' => (int) $first->diffInDays($median),
                ];
            })->all();
        $adoption = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse((string) $row->active_date)->toDateString();
            $total = (int) $totals->get($date, 0);
            $active = (int) $row->getAttribute('active_installs');
            $adoption[] = [
                'date' => $date,
                'version' => $row->app_version,
                'active_installs' => $active,
                'share' => $total === 0 ? 0.0 : round(($active / $total) * 100, 2),
            ];
        }

        return [
            'app_id' => $app->id,
            'window_days' => $days,
            'truncated' => $versionsTruncated || $truncated,
            'adoption' => $adoption,
            'update_speed' => $speed,
        ];
    }

    /** @return array<string, mixed> */
    public function screenFlow(TrackedApp $app, int $days, int $limit): array
    {
        $from = CarbonImmutable::now('UTC')->subDays($days - 1)->startOfDay();
        $ordered = StoredEvent::query()
            ->where('app_id', $app->id)
            ->where('type', 'screen')
            ->where('occurred_at', '>=', $from)
            ->select(['name', 'install_id', 'server_session_id', 'occurred_at', 'id'])
            ->selectRaw('LAG(name) OVER (PARTITION BY install_id, server_session_id ORDER BY occurred_at, id) AS previous_screen')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY install_id, server_session_id ORDER BY occurred_at, id) AS entry_position')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY install_id, server_session_id ORDER BY occurred_at DESC, id DESC) AS exit_position');

        $transitions = DB::query()->fromSub($ordered, 'ordered_screens')
            ->whereNotNull('previous_screen')
            ->selectRaw('previous_screen AS from_screen, name AS to_screen, COUNT(*) AS transition_count')
            ->groupBy('previous_screen', 'name')
            ->orderByDesc('transition_count')
            ->orderBy('previous_screen')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'from' => (string) $row->from_screen,
                'to' => (string) $row->to_screen,
                'count' => (int) $row->transition_count,
            ])->all();

        return [
            'app_id' => $app->id,
            'window_days' => $days,
            'transitions' => $transitions,
            'entries' => $this->screenBoundary($ordered, 'entry_position', $limit),
            'exits' => $this->screenBoundary($ordered, 'exit_position', $limit),
        ];
    }

    /**
     * @param  non-empty-list<string>  $steps
     * @return array<string, mixed>
     */
    public function funnel(TrackedApp $app, array $steps, int $days, int $windowMinutes): array
    {
        $to = CarbonImmutable::now('UTC');

        return [
            'app_id' => $app->id,
            'window_days' => $days,
            'window_minutes' => $windowMinutes,
            'steps' => $this->funnels->run($app, $steps, $to->subDays($days), $to, $windowMinutes),
        ];
    }

    /** @return array<string, mixed> */
    public function retention(TrackedApp $app, int $weeks): array
    {
        $through = CarbonImmutable::now('UTC')->startOfWeek();

        return [
            'app_id' => $app->id,
            'weeks' => $weeks,
            'cohorts' => $this->retention->cohorts($app, $through->subWeeks($weeks), $through, $weeks),
        ];
    }

    /** @return array<string, mixed> */
    public function sessions(TrackedApp $app, int $days): array
    {
        $from = CarbonImmutable::now('UTC')->subDays($days - 1)->startOfDay();
        $row = TrackedSession::query()
            ->where('app_id', $app->id)
            ->where('started_at', '>=', $from)
            ->selectRaw('COUNT(*) AS session_count')
            ->selectRaw('COALESCE(AVG(EXTRACT(EPOCH FROM (ended_at - started_at))), 0) AS average_length_seconds')
            ->selectRaw('COALESCE(AVG(screen_count), 0) AS average_screens')
            ->selectRaw('COALESCE(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (ended_at - started_at))), 0) AS p50_length_seconds')
            ->selectRaw('COALESCE(PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (ended_at - started_at))), 0) AS p95_length_seconds')
            ->firstOrFail();

        return [
            'app_id' => $app->id,
            'window_days' => $days,
            'count' => (int) $row->getAttribute('session_count'),
            'average_length_seconds' => round((float) $row->getAttribute('average_length_seconds'), 2),
            'p50_length_seconds' => round((float) $row->getAttribute('p50_length_seconds'), 2),
            'p95_length_seconds' => round((float) $row->getAttribute('p95_length_seconds'), 2),
            'average_screens' => round((float) $row->getAttribute('average_screens'), 2),
        ];
    }

    /** @return array<string, mixed> */
    public function errors(TrackedApp $app, int $days, ?string $newSince, int $limit): array
    {
        $from = CarbonImmutable::now('UTC')->subDays($days - 1)->startOfDay();
        $newIds = $newSince === null
            ? []
            : collect($this->errors->newSinceVersion($app, $newSince))->pluck('id')->all();
        $windowedErrors = StoredEvent::query()
            ->where('app_id', $app->id)
            ->whereNotNull('error_group_id')
            ->where('occurred_at', '>=', $from)
            ->selectRaw('error_group_id, COUNT(*) AS window_occurrences')
            ->selectRaw('COUNT(DISTINCT install_id) AS window_installs_affected')
            ->selectRaw('MAX(occurred_at) AS window_last_seen_at')
            ->groupBy('error_group_id');
        $groups = ErrorGroup::query()
            ->joinSub($windowedErrors, 'windowed_errors', 'windowed_errors.error_group_id', '=', 'error_groups.id')
            ->where('error_groups.app_id', $app->id)
            ->select('error_groups.*')
            ->addSelect(['windowed_errors.window_occurrences', 'windowed_errors.window_installs_affected'])
            ->latest('windowed_errors.window_last_seen_at')
            ->orderBy('error_groups.id')
            ->limit($limit)
            ->get();

        return [
            'app_id' => $app->id,
            'window_days' => $days,
            'groups' => $groups->map(fn (ErrorGroup $group): array => [
                'fingerprint' => $group->fingerprint,
                'count' => (int) $group->getAttribute('window_occurrences'),
                'installs_affected' => (int) $group->getAttribute('window_installs_affected'),
                'first_seen_at' => $group->first_seen_at->toAtomString(),
                'last_seen_at' => $group->last_seen_at->toAtomString(),
                'first_version' => $group->first_version,
                'last_version' => $group->last_version,
                'new_since' => in_array($group->id, $newIds, true),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function errorDetail(TrackedApp $app, string $fingerprint): array
    {
        $group = ErrorGroup::query()
            ->where('app_id', $app->id)
            ->where('fingerprint', $fingerprint)
            ->firstOrFail();

        return [
            'app_id' => $app->id,
            'fingerprint' => $group->fingerprint,
            'count' => $group->total_occurrences,
            'installs_affected' => $group->installs_affected,
            'first_seen_at' => $group->first_seen_at->toAtomString(),
            'last_seen_at' => $group->last_seen_at->toAtomString(),
            'first_version' => $group->first_version,
            'last_version' => $group->last_version,
            'sample_error' => $group->sample_error,
            'context_distribution' => $this->errorContextDistribution($app, $group),
        ];
    }

    /** @return array<string, mixed> */
    public function installTimeline(
        TrackedApp $app,
        ?string $installUuid,
        ?string $sessionId,
        int $limit,
    ): array {
        $query = StoredEvent::query()
            ->where('app_id', $app->id)
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays(90));

        if ($installUuid !== null) {
            $install = Install::query()
                ->where('app_id', $app->id)
                ->where('install_uuid', $installUuid)
                ->firstOrFail();
            $query->where('install_id', $install->id);
        } else {
            $query->where('session_id', $sessionId);
        }

        return [
            'app_id' => $app->id,
            'install_id' => $installUuid,
            'session_id' => $sessionId,
            'events' => $query->oldest('occurred_at')->orderBy('id')->limit($limit)->get()
                ->map(fn (StoredEvent $event): array => [
                    'event_id' => $event->event_id,
                    'name' => $event->name,
                    'type' => $event->type,
                    'occurred_at' => $event->occurred_at->toAtomString(),
                    'session_id' => $event->session_id,
                    'properties' => $event->props,
                    'error' => $event->error,
                    'app_version' => $event->app_version,
                    'platform' => $event->platform,
                    'os' => $event->os,
                ])->all(),
        ];
    }

    /** @return list<array{value: string, count: int}> */
    private function aggregateRows(TrackedApp $app, string $from, AggregateDimension $dimension): array
    {
        return DailyAggregate::query()
            ->where('app_id', $app->id)
            ->where('aggregate_date', '>=', $from)
            ->where('dimension', $dimension->value)
            ->selectRaw('dimension_value, SUM(event_count) AS total')
            ->groupBy('dimension_value')
            ->orderByDesc('total')
            ->orderBy('dimension_value')
            ->limit(100)
            ->get()
            ->map(fn (DailyAggregate $row): array => [
                'value' => $row->dimension_value,
                'count' => (int) $row->getAttribute('total'),
            ])->all();
    }

    /** @return array<string, array{truncated: bool, values: list<array{value: string, installs: int}>}> */
    private function errorContextDistribution(TrackedApp $app, ErrorGroup $group): array
    {
        $distribution = [];
        $dimensions = [
            'app_version' => "error_group_installs.sample_context->>'app_version'",
            'platform' => "error_group_installs.sample_context->>'platform'",
            'os' => "error_group_installs.sample_context->>'os'",
            'locale' => "error_group_installs.sample_context->>'locale'",
        ];

        foreach ($dimensions as $dimension => $expression) {
            $rows = ErrorGroupInstall::query()
                ->join('error_groups', 'error_groups.id', '=', 'error_group_installs.error_group_id')
                ->where('error_groups.app_id', $app->id)
                ->where('error_group_installs.error_group_id', $group->id)
                ->whereRaw("{$expression} IS NOT NULL")
                ->selectRaw("{$expression} AS value, COUNT(*) AS installs")
                ->groupByRaw($expression)
                ->orderByDesc('installs')
                ->orderBy('value')
                ->limit(26)
                ->get();

            $distribution[$dimension] = [
                'truncated' => $rows->count() > 25,
                'values' => $rows->take(25)->map(fn (ErrorGroupInstall $row): array => [
                    'value' => (string) $row->getAttribute('value'),
                    'installs' => (int) $row->getAttribute('installs'),
                ])->all(),
            ];
        }

        return $distribution;
    }

    /** @return list<array{value: string, users: int}> */
    private function activeBreakdown(TrackedApp $app, CarbonImmutable $asOf, string $column): array
    {
        return DailyActiveInstall::query()
            ->where('app_id', $app->id)
            ->whereBetween('active_date', [$asOf->subDays(29)->toDateString(), $asOf->toDateString()])
            ->selectRaw("{$column} AS value, COUNT(DISTINCT install_id) AS users")
            ->groupBy($column)
            ->orderByDesc('users')
            ->orderBy($column)
            ->limit(25)
            ->get()
            ->map(fn (DailyActiveInstall $row): array => [
                'value' => (string) $row->getAttribute('value'),
                'users' => (int) $row->getAttribute('users'),
            ])->all();
    }

    /**
     * @param  Builder<StoredEvent>  $ordered
     * @return list<array{screen: string, count: int}>
     */
    private function screenBoundary(Builder $ordered, string $position, int $limit): array
    {
        return DB::query()->fromSub(clone $ordered, 'ordered_screens')
            ->where($position, 1)
            ->selectRaw('name AS screen, COUNT(*) AS screen_count')
            ->groupBy('name')
            ->orderByDesc('screen_count')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'screen' => (string) $row->screen,
                'count' => (int) $row->screen_count,
            ])->all();
    }
}
