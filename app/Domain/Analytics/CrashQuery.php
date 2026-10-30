<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\StoredEvent;
use App\Models\TrackedApp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrashQuery
{
    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function window(?string $from, ?string $to): array
    {
        $now = CarbonImmutable::now('UTC');
        $configured = config('telltale.storage.raw_event_retention_days', 90);
        $retention = is_int($configured) && $configured > 0 ? $configured : 90;
        $end = $to === null ? $now : CarbonImmutable::parse($to)->utc();
        $start = $from === null ? $end->subDays(min(30, $retention)) : CarbonImmutable::parse($from)->utc();

        if ($start->lessThan($this->horizon($retention, $now))) {
            throw ValidationException::withMessages(['from' => 'Retention limit: the requested window begins before retained raw history.']);
        }

        if (! $start->lessThan($end) || $end->greaterThan($now)
            || $end->greaterThan($start->addDays(min(366, $retention)))) {
            throw ValidationException::withMessages(['to' => 'Use an ordered, nonfuture window within the raw retention limit (maximum 366 days).']);
        }

        return [$start, $end];
    }

    /** @return array<string, mixed> */
    public function groups(TrackedApp $app, CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        $rows = $this->events($app, $from, $to)->where('type', 'crash')
            ->select('crash_fingerprint')
            ->selectRaw('SUM(crash_occurrence_count) AS occurrences, COUNT(*) AS reports, COUNT(DISTINCT install_id) AS installs_affected')
            ->selectRaw('MIN(occurred_at) AS first_seen_at, MAX(occurred_at) AS last_seen_at')
            ->selectRaw('(array_agg(app_version ORDER BY occurred_at, id))[1] AS first_version')
            ->selectRaw('(array_agg(app_version ORDER BY occurred_at DESC, id DESC))[1] AS last_version')
            ->selectRaw("(array_agg(crash->>'build' ORDER BY occurred_at, id))[1] AS first_build")
            ->selectRaw("(array_agg(crash->>'build' ORDER BY occurred_at DESC, id DESC))[1] AS last_build")
            ->groupBy('crash_fingerprint')->orderByDesc('last_seen_at')->orderBy('crash_fingerprint')
            ->limit($limit + 1)->get();

        return [
            ...$this->bounds($app, $from, $to),
            'truncated' => $rows->count() > $limit,
            'groups' => $rows->take($limit)->map(fn (StoredEvent $row): array => [
                'fingerprint' => $row->crash_fingerprint,
                'count' => (int) $row->getAttribute('occurrences'),
                'reports' => (int) $row->getAttribute('reports'),
                'installs_affected' => (int) $row->getAttribute('installs_affected'),
                'first_seen_at' => CarbonImmutable::parse($row->getAttribute('first_seen_at'))->utc()->toAtomString(),
                'last_seen_at' => CarbonImmutable::parse($row->getAttribute('last_seen_at'))->utc()->toAtomString(),
                'first_version' => $row->getAttribute('first_version'),
                'last_version' => $row->getAttribute('last_version'),
                'first_build' => $row->getAttribute('first_build'),
                'last_build' => $row->getAttribute('last_build'),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(TrackedApp $app, string $eventId): array
    {
        $configured = config('telltale.storage.raw_event_retention_days', 90);
        $retention = is_int($configured) && $configured > 0 ? $configured : 90;
        $event = StoredEvent::query()->where('app_id', $app->id)->where('type', 'crash')
            ->where('event_id', $eventId)
            ->where('occurred_at', '>=', $this->horizon($retention, CarbonImmutable::now('UTC')))->firstOrFail();

        return [
            'app_id' => $app->id,
            'event_id' => $event->event_id,
            'fingerprint' => $event->crash_fingerprint,
            'occurred_at' => $event->occurred_at->toAtomString(),
            'app_version' => $event->app_version,
            'platform' => $event->platform,
            'crash' => $event->crash,
        ];
    }

    /** @return array<string, mixed> */
    public function freeUsers(TrackedApp $app, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->events($app, $from, $to)
            ->selectRaw('COUNT(DISTINCT install_id) AS active_users')
            ->selectRaw("COUNT(DISTINCT CASE WHEN type = 'crash' AND crash_fatal THEN install_id END) AS affected_users")
            ->firstOrFail();
        $active = (int) $row->getAttribute('active_users');
        $affected = (int) $row->getAttribute('affected_users');

        return [
            ...$this->bounds($app, $from, $to),
            'active_users' => $active,
            'affected_users' => $affected,
            'crash_free_users' => $active - $affected,
            'crash_free_percentage' => $active === 0 ? null : round(100 * ($active - $affected) / $active, 2),
            'identity_basis' => 'install',
            'timestamp_attribution' => 'Exact occurrence time when available; otherwise reporting-window end. UTC half-open [from,to).',
            'coverage_caveats' => [
                'Observed retained-event install proxy, not a complete population crash rate.',
                'OS reports may arrive late and change historical results.',
                'OS coverage is partial; missing reports do not establish crash-free operation.',
            ],
        ];
    }

    /** @return Builder<StoredEvent> */
    private function events(TrackedApp $app, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return StoredEvent::query()->where('app_id', $app->id)
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);
    }

    /** @return array<string, mixed> */
    private function bounds(TrackedApp $app, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['app_id' => $app->id, 'from' => $from->toAtomString(), 'to' => $to->toAtomString()];
    }

    private function horizon(int $retention, CarbonImmutable $now): CarbonImmutable
    {
        $configuredHorizon = $now->subDays($retention);
        $prunedBefore = DB::table('raw_event_prune_watermarks')->where('id', 1)->value('pruned_before');

        return is_string($prunedBefore)
            ? $configuredHorizon->max(CarbonImmutable::parse($prunedBefore)->utc())
            : $configuredHorizon;
    }
}
