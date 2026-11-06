<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes\Listeners;

use ArtisanBuild\TelltaleCrashes\Events\ReportsReady;
use ArtisanBuild\TelltaleCrashes\ImportScheduler;

final class ScheduleImport
{
    public function __construct(private readonly ImportScheduler $scheduler) {}

    public function handle(ReportsReady $event): void
    {
        // Empty startup/ack/suppression notifications cannot sustain a queue loop.
        if ($event->schema_version === 1 && $event->pending_count > 0) {
            $this->scheduler->schedule();
        }
    }
}
