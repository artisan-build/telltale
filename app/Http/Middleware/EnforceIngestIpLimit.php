<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Ingest\IngestRequestLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceIngestIpLimit
{
    public function __construct(private IngestRequestLimiter $limiter) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->limiter->consumeIp($request);

        return $next($request);
    }
}
