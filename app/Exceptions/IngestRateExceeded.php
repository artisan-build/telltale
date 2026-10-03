<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class IngestRateExceeded extends RuntimeException implements ShouldntReport
{
    public function __construct(private readonly int $retryAfter)
    {
        parent::__construct('Too many requests.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 429, [
            'Retry-After' => (string) $this->retryAfter,
        ]);
    }
}
