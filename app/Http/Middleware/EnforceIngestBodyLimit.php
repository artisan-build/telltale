<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceIngestBodyLimit
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $maximum = config('telltale.ingest.max_body_bytes');

        if (! is_int($maximum) || $maximum < 1) {
            return $next($request);
        }

        $declaredLength = $request->server('CONTENT_LENGTH');

        if ((is_numeric($declaredLength) && (int) $declaredLength > $maximum)
            || strlen($request->getContent()) > $maximum) {
            return new JsonResponse(['message' => 'Request body is too large.'], 413);
        }

        return $next($request);
    }
}
