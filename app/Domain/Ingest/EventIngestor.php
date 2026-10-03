<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Exceptions\IngestVolumeExceeded;
use App\Exceptions\InvalidIngestCredential;
use App\Models\Install;
use App\Models\StoredEvent;
use App\Models\TrackedApp;
use ArtisanBuild\TelltaleContracts\EnvelopeV1;
use ArtisanBuild\TelltaleContracts\Event;
use ArtisanBuild\TelltaleContracts\EventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class EventIngestor
{
    public function __construct(private EventProjector $projector) {}

    public function ingest(Install $install, string $token, EnvelopeV1 $envelope): IngestResult
    {
        return DB::transaction(function () use ($install, $token, $envelope): IngestResult {
            $app = TrackedApp::query()->whereKey($install->app_id)->lockForUpdate()->firstOrFail();
            $lockedInstall = Install::query()->whereKey($install->id)->lockForUpdate()->firstOrFail();

            if ($lockedInstall->revoked_at !== null || ! Credential::matches($token, 'ttx', $lockedInstall->token_hash)) {
                throw new InvalidIngestCredential;
            }

            /** @var array<string, Event> $uniqueEvents */
            $uniqueEvents = [];

            foreach ($envelope->events as $event) {
                $uniqueEvents[$event->eventId] ??= $event;
            }

            $existingIds = StoredEvent::query()
                ->where('app_id', $app->id)
                ->whereIn('event_id', array_keys($uniqueEvents))
                ->pluck('event_id')
                ->all();

            foreach ($existingIds as $eventId) {
                unset($uniqueEvents[$eventId]);
            }

            /** @var array<string, array<string, mixed>> $sessionContexts */
            $sessionContexts = [];

            foreach ($uniqueEvents as $event) {
                if ($event->type === EventType::Context) {
                    $sessionContexts[$event->sessionId] = $event->props;
                }
            }

            $newCount = count($uniqueEvents);
            $now = Date::now('UTC');
            $dayStart = $now->startOfDay();
            $dayEnd = $dayStart->addDay();

            $appCount = StoredEvent::query()
                ->where('app_id', $app->id)
                ->where('created_at', '>=', $dayStart)
                ->where('created_at', '<', $dayEnd)
                ->count();
            $installCount = StoredEvent::query()
                ->where('install_id', $lockedInstall->id)
                ->where('created_at', '>=', $dayStart)
                ->where('created_at', '<', $dayEnd)
                ->count();

            if ($appCount + $newCount > $app->daily_event_cap
                || $installCount + $newCount > $app->install_daily_event_cap) {
                throw new IngestVolumeExceeded(max(1, $dayEnd->getTimestamp() - $now->getTimestamp()));
            }

            foreach ($uniqueEvents as $event) {
                $stored = StoredEvent::query()->create([
                    'app_id' => $app->id,
                    'install_id' => $lockedInstall->id,
                    'event_id' => $event->eventId,
                    'name' => $event->name,
                    'type' => $event->type->value,
                    'occurred_at' => CarbonImmutable::parse($event->timestamp)->utc(),
                    'session_id' => $event->sessionId,
                    'props' => $event->props,
                    'error' => $event->error?->toArray(),
                    'client_version' => $envelope->clientVersion,
                ]);
                $this->projector->project($stored, $event, $lockedInstall, $sessionContexts[$event->sessionId] ?? null);
            }

            $droppedEventsTotal = max($lockedInstall->dropped_events_total, $envelope->droppedEventsTotal);
            $lockedInstall->forceFill(['dropped_events_total' => $droppedEventsTotal])->save();

            return new IngestResult(
                accepted: $newCount,
                duplicates: count($envelope->events) - $newCount,
                droppedEventsTotal: $droppedEventsTotal,
            );
        });
    }
}
