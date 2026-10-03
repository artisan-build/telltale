<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class InvalidIngestConfiguration extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Ingest is unavailable due to invalid server configuration.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 503);
    }
}
