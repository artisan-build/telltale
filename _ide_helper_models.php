<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $app_id
 * @property int $install_id
 * @property string $active_date
 * @property string $app_version
 * @property string $platform
 * @property string $os
 * @property int $id
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereActiveDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereAppVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereInstallId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereOs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall wherePlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyActiveInstall whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperDailyActiveInstall {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $app_id
 * @property string $aggregate_date
 * @property AggregateDimension $dimension
 * @property string $dimension_key
 * @property string $dimension_value
 * @property int $event_count
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereAggregateDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereDimension($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereDimensionKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereDimensionValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereEventCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyAggregate whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperDailyAggregate {}
}

namespace App\Models{
/**
 * @property int $app_id
 * @property int $install_id
 * @property string $first_seen_on
 * @property string $app_version
 * @property int $id
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall whereAppVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall whereFirstSeenOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall whereInstallId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DailyNewInstall whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperDailyNewInstall {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $app_id
 * @property string $fingerprint
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property string $first_version
 * @property string $last_version
 * @property int $total_occurrences
 * @property int $installs_affected
 * @property array<string, mixed> $sample_error
 * @property array<string, string> $sample_context
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\TrackedApp $app
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereFingerprint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereFirstSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereFirstVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereInstallsAffected($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereLastVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereSampleContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereSampleError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereTotalOccurrences($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroup whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperErrorGroup {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $error_group_id
 * @property int $install_id
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property string $first_version
 * @property string $last_version
 * @property int $occurrence_count
 * @property array<string, mixed> $sample_error
 * @property array<string, string> $sample_context
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\ErrorGroup $errorGroup
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereErrorGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereFirstSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereFirstVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereInstallId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereLastVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereOccurrenceCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereSampleContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereSampleError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErrorGroupInstall whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperErrorGroupInstall {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $app_id
 * @property string $install_uuid
 * @property string $token_hash
 * @property int $dropped_events_total
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $context_observed_at
 * @property array<string, string>|null $current_context
 * @property-read TrackedApp $app
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StoredEvent> $events
 * @property-read int|null $events_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TrackedSession> $sessions
 * @property-read int|null $sessions_count
 * @method static \Database\Factories\InstallFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereContextObservedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereCurrentContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereDroppedEventsTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereFirstSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereInstallUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperInstall {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $app_id
 * @property int $install_id
 * @property string $event_id
 * @property string $name
 * @property string $type
 * @property CarbonImmutable $occurred_at
 * @property string $session_id
 * @property array<string, mixed> $props
 * @property array<string, mixed>|null $error
 * @property string $client_version
 * @property int|null $server_session_id
 * @property int|null $error_group_id
 * @property string|null $app_version
 * @property string|null $platform
 * @property string|null $os
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\TrackedApp $app
 * @property-read \App\Models\ErrorGroup|null $errorGroup
 * @property-read \App\Models\Install $install
 * @property-read \App\Models\TrackedSession|null $session
 * @method static \Database\Factories\StoredEventFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereAppVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereClientVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereErrorGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereInstallId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereOs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent wherePlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereProps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereServerSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperStoredEvent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $ingest_key_hash
 * @property int $rate_per_minute
 * @property int $install_rate_per_minute
 * @property int $daily_event_cap
 * @property int $install_daily_event_cap
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ErrorGroup> $errorGroups
 * @property-read int|null $error_groups_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StoredEvent> $events
 * @property-read int|null $events_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Install> $installs
 * @property-read int|null $installs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TrackedSession> $sessions
 * @property-read int|null $sessions_count
 * @method static \Database\Factories\TrackedAppFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereDailyEventCap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereIngestKeyHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereInstallDailyEventCap($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereInstallRatePerMinute($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereRatePerMinute($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedApp whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperTrackedApp {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $app_id
 * @property int $install_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $ended_at
 * @property int $event_count
 * @property int $screen_count
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\TrackedApp $app
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StoredEvent> $events
 * @property-read int|null $events_count
 * @property-read \App\Models\Install $install
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereEventCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereInstallId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereScreenCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrackedSession whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	final class IdeHelperTrackedSession {}
}
