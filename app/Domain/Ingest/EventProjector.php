<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Enums\AggregateDimension;
use App\Models\DailyActiveInstall;
use App\Models\DailyAggregate;
use App\Models\DailyNewInstall;
use App\Models\ErrorGroup;
use App\Models\ErrorGroupInstall;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedSession;
use ArtisanBuild\TelltaleContracts\Event;
use ArtisanBuild\TelltaleContracts\EventType;

final class EventProjector
{
    private const string UNKNOWN = '<unknown>';

    /**
     * @param  array<string, mixed>|null  $sessionContext
     */
    public function project(StoredEvent $stored, Event $event, Install $install, ?array $sessionContext): void
    {
        $occurredAt = $stored->occurred_at;
        $dimensions = $this->dimensions($sessionContext ?? $install->current_context ?? []);

        if ($event->type === EventType::Context) {
            $dimensions = $this->dimensions($event->props);

            if ($install->context_observed_at === null || $occurredAt->greaterThanOrEqualTo($install->context_observed_at)) {
                $install->forceFill([
                    'context_observed_at' => $occurredAt,
                    'current_context' => $dimensions,
                ]);
            }
        }

        $sessionId = $this->projectSession($stored, $event);
        $errorGroupId = $this->projectError($stored, $event, $dimensions);

        $stored->forceFill([
            'server_session_id' => $sessionId,
            'error_group_id' => $errorGroupId,
            'app_version' => $dimensions['app_version'],
            'platform' => $dimensions['platform'],
            'os' => $dimensions['os'],
        ])->save();

        $this->projectDailyAggregates($stored, $event, $dimensions);
        $this->projectInstallActivity($stored, $install, $dimensions);
        $install->save();
    }

    private function projectSession(StoredEvent $event, Event $contract): int
    {
        $occurredAt = $event->occurred_at;
        $previous = TrackedSession::query()
            ->where('app_id', $event->app_id)
            ->where('install_id', $event->install_id)
            ->where('started_at', '<=', $occurredAt)
            ->latest('ended_at')
            ->first();
        $next = TrackedSession::query()
            ->where('app_id', $event->app_id)
            ->where('install_id', $event->install_id)
            ->where('started_at', '>', $occurredAt)
            ->oldest('started_at')
            ->first();

        $inactivityMinutes = config('telltale.storage.session_inactivity_minutes', 30);
        $inactivityMinutes = is_int($inactivityMinutes) && $inactivityMinutes > 0 ? $inactivityMinutes : 30;
        $previousGap = $previous === null || $previous->ended_at->greaterThanOrEqualTo($occurredAt)
            ? 0
            : $previous->ended_at->diffInSeconds($occurredAt);
        $nextGap = $next === null ? 0 : $occurredAt->diffInSeconds($next->started_at);
        $previousConnected = $previous !== null
            && $previousGap <= $inactivityMinutes * 60;
        $nextConnected = $next !== null
            && $nextGap <= $inactivityMinutes * 60;
        $screen = $contract->type === EventType::Screen ? 1 : 0;

        if (! $previousConnected && ! $nextConnected) {
            return TrackedSession::query()->create([
                'app_id' => $event->app_id,
                'install_id' => $event->install_id,
                'started_at' => $occurredAt,
                'ended_at' => $occurredAt,
                'event_count' => 1,
                'screen_count' => $screen,
            ])->id;
        }

        /** @var TrackedSession $target */
        $target = $previousConnected ? $previous : $next;
        $target->forceFill([
            'started_at' => $occurredAt->lessThan($target->started_at) ? $occurredAt : $target->started_at,
            'ended_at' => $occurredAt->greaterThan($target->ended_at) ? $occurredAt : $target->ended_at,
            'event_count' => $target->event_count + 1,
            'screen_count' => $target->screen_count + $screen,
        ]);

        if ($previousConnected && $nextConnected && $previous !== null && $next !== null && $previous->id !== $next->id) {
            $target->forceFill([
                'started_at' => $previous->started_at->lessThan($next->started_at) ? $previous->started_at : $next->started_at,
                'ended_at' => $previous->ended_at->greaterThan($next->ended_at) ? $previous->ended_at : $next->ended_at,
                'event_count' => $target->event_count + $next->event_count,
                'screen_count' => $target->screen_count + $next->screen_count,
            ]);
            $target->save();
            StoredEvent::query()->where('server_session_id', $next->id)->update(['server_session_id' => $target->id]);
            $next->delete();

            return $target->id;
        }

        $target->save();

        return $target->id;
    }

    /**
     * @param  array{app_version: string, platform: string, os: string, locale: string}  $dimensions
     */
    private function projectError(StoredEvent $stored, Event $event, array $dimensions): ?int
    {
        if ($event->type !== EventType::Error || $event->error === null) {
            return null;
        }

        $clientFingerprint = $event->error->fingerprint;
        $fingerprintSource = $clientFingerprint !== null && strlen($clientFingerprint) <= 255
            ? 'client:'.$clientFingerprint
            : 'fallback:'.json_encode([
                $event->error->class,
                $event->error->file,
                $event->error->line,
                $event->error->stack[0] ?? '',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $fingerprint = hash('sha256', $fingerprintSource);
        $sample = $event->error->toArray();
        unset($sample['fingerprint']);
        $group = ErrorGroup::query()->firstOrNew([
            'app_id' => $stored->app_id,
            'fingerprint' => $fingerprint,
        ]);

        if (! $group->exists) {
            $group->fill([
                'first_seen_at' => $stored->occurred_at,
                'last_seen_at' => $stored->occurred_at,
                'first_version' => $dimensions['app_version'],
                'last_version' => $dimensions['app_version'],
                'total_occurrences' => 1,
                'installs_affected' => 0,
                'sample_error' => $sample,
                'sample_context' => $dimensions,
            ])->save();
        } else {
            $updates = ['total_occurrences' => $group->total_occurrences + 1];

            if ($stored->occurred_at->lessThan($group->first_seen_at)) {
                $updates['first_seen_at'] = $stored->occurred_at;
                $updates['first_version'] = $dimensions['app_version'];
            }

            if ($stored->occurred_at->greaterThan($group->last_seen_at)) {
                $updates['last_seen_at'] = $stored->occurred_at;
                $updates['last_version'] = $dimensions['app_version'];
            }

            $group->forceFill($updates)->save();
        }

        $membership = ErrorGroupInstall::query()->firstOrNew([
            'error_group_id' => $group->id,
            'install_id' => $stored->install_id,
        ]);

        if (! $membership->exists) {
            $membership->fill([
                'first_seen_at' => $stored->occurred_at,
                'last_seen_at' => $stored->occurred_at,
                'first_version' => $dimensions['app_version'],
                'last_version' => $dimensions['app_version'],
                'occurrence_count' => 1,
                'sample_error' => $sample,
                'sample_context' => $dimensions,
            ])->save();
            $group->increment('installs_affected');
        } else {
            $membershipUpdates = ['occurrence_count' => $membership->occurrence_count + 1];

            if ($stored->occurred_at->lessThan($membership->first_seen_at)) {
                $membershipUpdates['first_seen_at'] = $stored->occurred_at;
                $membershipUpdates['first_version'] = $dimensions['app_version'];
            }

            if ($stored->occurred_at->greaterThan($membership->last_seen_at)) {
                $membershipUpdates['last_seen_at'] = $stored->occurred_at;
                $membershipUpdates['last_version'] = $dimensions['app_version'];
            }

            $membership->forceFill($membershipUpdates)->save();
        }

        return $group->id;
    }

    /**
     * @param  array{app_version: string, platform: string, os: string, locale: string}  $dimensions
     */
    private function projectDailyAggregates(StoredEvent $stored, Event $event, array $dimensions): void
    {
        $values = [
            AggregateDimension::EventName->value => $event->name,
            AggregateDimension::Version->value => $dimensions['app_version'],
            AggregateDimension::Platform->value => $dimensions['platform'],
            AggregateDimension::OperatingSystem->value => $dimensions['os'],
            AggregateDimension::Locale->value => $dimensions['locale'],
        ];

        if ($event->type === EventType::Screen) {
            $values[AggregateDimension::Screen->value] = $event->name;
        }

        foreach ($values as $dimension => $value) {
            $aggregate = DailyAggregate::query()->firstOrNew([
                'app_id' => $stored->app_id,
                'aggregate_date' => $stored->occurred_at->toDateString(),
                'dimension' => $dimension,
                'dimension_key' => hash('sha256', $value),
            ]);

            if (! $aggregate->exists) {
                $aggregate->fill([
                    'dimension_value' => $value,
                    'event_count' => 1,
                ])->save();
            } else {
                $aggregate->increment('event_count');
            }
        }
    }

    /**
     * @param  array{app_version: string, platform: string, os: string, locale: string}  $dimensions
     */
    private function projectInstallActivity(StoredEvent $stored, Install $install, array $dimensions): void
    {
        $active = DailyActiveInstall::query()->firstOrNew([
            'app_id' => $stored->app_id,
            'active_date' => $stored->occurred_at->toDateString(),
            'install_id' => $stored->install_id,
        ]);
        $active->fill([
            'app_version' => $this->preferKnown($active->app_version ?? null, $dimensions['app_version']),
            'platform' => $this->preferKnown($active->platform ?? null, $dimensions['platform']),
            'os' => $this->preferKnown($active->os ?? null, $dimensions['os']),
        ])->save();

        $isEarlier = $install->first_seen_at === null || $stored->occurred_at->lessThan($install->first_seen_at);

        if ($isEarlier) {
            $install->first_seen_at = $stored->occurred_at;
        }

        $firstSeen = DailyNewInstall::query()->firstOrNew([
            'app_id' => $stored->app_id,
            'install_id' => $stored->install_id,
        ]);

        if (! $firstSeen->exists || $isEarlier) {
            $firstSeen->fill([
                'first_seen_on' => $stored->occurred_at->toDateString(),
                'app_version' => $dimensions['app_version'],
            ]);
        } elseif ($firstSeen->app_version === self::UNKNOWN && $dimensions['app_version'] !== self::UNKNOWN) {
            $firstSeen->app_version = $dimensions['app_version'];
        }

        $firstSeen->save();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{app_version: string, platform: string, os: string, locale: string}
     */
    private function dimensions(array $context): array
    {
        return [
            'app_version' => $this->dimensionValue($context['app_version'] ?? null),
            'platform' => $this->dimensionValue($context['platform'] ?? null),
            'os' => $this->dimensionValue($context['os'] ?? $context['platform_name'] ?? null),
            'locale' => $this->dimensionValue($context['locale'] ?? $context['language'] ?? null),
        ];
    }

    private function dimensionValue(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : self::UNKNOWN;
    }

    private function preferKnown(?string $existing, string $candidate): string
    {
        return $existing === null || $existing === self::UNKNOWN ? $candidate : $existing;
    }
}
