<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient;

enum CrashImportOutcome
{
    case Enqueued;
    case Dropped;
    case Retry;

    public function mayAcknowledge(): bool
    {
        return $this !== self::Retry;
    }
}
