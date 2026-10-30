<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes;

use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleCrashes\Jobs\ImportNativeReports;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\SyncQueue;
use Throwable;

final class ImportScheduler
{
    public function __construct(private readonly NativeBridge $bridge, private readonly Repository $config, private readonly Factory $queues, private readonly ClientDatabase $database) {}

    public function schedule(): void
    {
        try {
            if (! $this->bridge->available()) {
                return;
            }
            $connection = $this->config->get('telltale.queue.connection', 'database');
            if (! is_string($connection) || $connection === '' || $connection === 'sync'
                || $this->config->get('queue.connections.'.$connection.'.driver') === 'sync') {
                return;
            }
            $queue = $this->queues->connection($connection);
            if ($queue instanceof SyncQueue) {
                return;
            }
            if (! $this->database->claimCrashImportSchedule(time())) {
                return;
            }
            $name = $this->config->get('telltale.queue.name');
            $queue->later(0, (new ImportNativeReports)->onConnection($connection), '', is_string($name) ? $name : null);
        } catch (Throwable) {
            // Unconfigured hosts and unavailable queues must remain inert.
            try {
                $this->database->clearCrashImportSchedule();
            } catch (Throwable) {
                // The lease expires even when device storage is unavailable.
            }
        }
    }
}
