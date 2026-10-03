<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Ingest\Credential;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectIngestCredentialsFromReadSurface
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/register', 'api/ingest')) {
            return $next($request);
        }

        $bearer = $request->bearerToken();

        if (is_string($bearer) && (
            Credential::hasFormat($bearer, 'tti')
            || Credential::hasFormat($bearer, 'ttx')
        )) {
            return new JsonResponse(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
