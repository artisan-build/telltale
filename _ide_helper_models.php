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
 * @property int $id
 * @property int $app_id
 * @property string $install_uuid
 * @property string $token_hash
 * @property int $dropped_events_total
 * @property CarbonImmutable|null $revoked_at
 * @property-read TrackedApp $app
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StoredEvent> $events
 * @property-read int|null $events_count
 * @method static \Database\Factories\InstallFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Install whereDroppedEventsTotal($value)
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
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property string $session_id
 * @property array<array-key, mixed> $props
 * @property array<array-key, mixed>|null $error
 * @property string $client_version
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\TrackedApp $app
 * @property-read \App\Models\Install $install
 * @method static \Database\Factories\StoredEventFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereClientVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereEventId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereInstallId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StoredEvent whereProps($value)
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StoredEvent> $events
 * @property-read int|null $events_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Install> $installs
 * @property-read int|null $installs_count
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
