<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Models\TrackedApp;

final readonly class CreatedApp
{
    public function __construct(
        private TrackedApp $app,
        private string $ingestValue,
    ) {}

    public function app(): TrackedApp
    {
        return $this->app;
    }

    public function ingestValue(): string
    {
        return $this->ingestValue;
    }
}
