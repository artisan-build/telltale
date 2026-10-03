<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class IngestVolumeExceeded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Daily event volume exceeded.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 429);
    }
}
