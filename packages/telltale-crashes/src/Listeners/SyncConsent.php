<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes\Listeners;

use ArtisanBuild\TelltaleClient\Events\ConsentChanged;
use ArtisanBuild\TelltaleCrashes\ImportScheduler;
use ArtisanBuild\TelltaleCrashes\NativeBridge;

final class SyncConsent
{
    public function __construct(private readonly NativeBridge $bridge, private readonly ImportScheduler $scheduler) {}

    public function handle(ConsentChanged $event): void
    {
        // Cached native operation only; persistence runs on its native background executor.
        $this->bridge->suppress($event->optedOut);
        $this->scheduler->schedule();
    }
}
