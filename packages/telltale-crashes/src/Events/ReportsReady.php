<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes\Events;

final readonly class ReportsReady
{
    public function __construct(public int $schema_version = 1, public int $pending_count = 0) {}
}
