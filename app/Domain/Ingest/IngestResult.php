<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

final readonly class IngestResult
{
    public function __construct(
        public int $accepted,
        public int $duplicates,
        public int $droppedEventsTotal,
    ) {}
}
