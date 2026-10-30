<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes\Jobs;

use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleCrashes\ImportReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

final class ImportNativeReports implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public function handle(ImportReports $importer, ClientDatabase $database): void
    {
        if ($this->job instanceof SyncJob || $this->job?->getConnectionName() === 'sync') {
            return;
        }
        try {
            // Hold the schedule lease while native operations may emit readiness events.
            $database->deferCrashImportSchedule(time() + 30);
        } catch (Throwable) {
            $this->job?->release(15);

            return;
        }
        if (! $importer->run()) {
            $delay = min(3600, 15 * (2 ** min(8, max(0, $this->attempts() - 1))));
            try {
                $database->deferCrashImportSchedule(time() + $delay + 15);
            } catch (Throwable) {
                // The active lease still prevents an immediate readiness loop.
            }
            $this->job?->release($delay);

            return;
        }
        try {
            // An early empty poll must not suppress a later same-launch readiness event.
            $database->clearCrashImportSchedule();
        } catch (Throwable) {
            // The short lease expires if local storage becomes unavailable.
        }
    }
}
