<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleContracts\ContractException;
use ArtisanBuild\TelltaleContracts\CrashDetails;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class ImportReports
{
    public function __construct(
        private readonly NativeBridge $bridge,
        private readonly ClientDatabase $database,
        private readonly TelltaleClient $client,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    /** False means retry without acknowledging unaccepted reports. */
    public function run(): bool
    {
        try {
            if (! $this->bridge->available()) {
                return true;
            }
            if ($this->database->isOptedOut()) {
                return $this->bridge->suppress(true);
            }
            $pending = $this->bridge->pending();
            if ($pending === null) {
                return false;
            }
            if (($pending['suppressed'] ?? false) === true) {
                $this->bridge->suppress(false);

                return false;
            }

            // A class in config is resolved in THIS worker runtime, never serialized as a closure.
            $callback = $this->config->get('telltale-crashes.before_send');
            if ($callback !== null) {
                if (! is_string($callback) || ! is_callable($handler = $this->container->make($callback))) {
                    return false;
                }
                $this->client->beforeSend($handler);
            }
            $accepted = [];
            $retry = false;
            foreach ($pending['reports'] as $report) {
                $id = is_array($report) ? ($report['report_id'] ?? null) : null;
                if (! is_string($id) || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
                    // No safe receipt identity: native corruption/TTL isolation owns this entry.
                    // The snapshot cap bounds this accounting to at most 32 rows per import.
                    $this->database->recordDrop();

                    continue;
                }
                try {
                    $crash = CrashDetails::fromArray($report);
                } catch (ContractException) {
                    $this->database->recordDrop();
                    $accepted[] = $id;

                    continue;
                }
                if ($this->client->importCrash($crash)->mayAcknowledge()) {
                    $accepted[] = $id;
                } else {
                    $retry = true;
                }
            }
            if ($accepted !== []) {
                $this->bridge->acknowledge(array_values(array_unique($accepted)));

                // A scheduled native ack is asynchronous. Verify removal on this job's bounded
                // retry, not a new job for every ack/readiness event (including disk failures).
                return false;
            }

            return ! $retry;
        } catch (Throwable) {
            return false;
        }
    }
}
