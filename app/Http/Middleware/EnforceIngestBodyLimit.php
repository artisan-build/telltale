<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\InvalidIngestConfiguration;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceIngestBodyLimit
{
    public const string BODY_ATTRIBUTE = 'telltale.bounded_body';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $maximum = config('telltale.ingest.max_body_bytes');

        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidIngestConfiguration;
        }

        $declaredLength = $request->server('CONTENT_LENGTH');

        $body = $request->getContent();

        if ((is_numeric($declaredLength) && (int) $declaredLength > $maximum) || strlen($body) > $maximum) {
            return $this->tooLarge();
        }

        $encoding = strtolower(trim((string) $request->header('Content-Encoding', '')));

        if ($encoding === '' || $encoding === 'identity') {
            $request->attributes->set(self::BODY_ATTRIBUTE, $body);

            return $next($request);
        }

        if ($encoding !== 'gzip') {
            return new JsonResponse(['message' => 'Content encoding is not supported.'], 415);
        }

        $declaredDecodedSize = $this->gzipDecodedSize($body);

        if ($declaredDecodedSize !== null && $declaredDecodedSize > $maximum) {
            return $this->tooLarge();
        }

        $decoded = @gzdecode($body, $maximum);

        if (! is_string($decoded)) {
            return new JsonResponse(['message' => 'The compressed request body is invalid.'], 400);
        }

        if (strlen($decoded) > $maximum) {
            return $this->tooLarge();
        }

        $request->attributes->set(self::BODY_ATTRIBUTE, $decoded);

        return $next($request);
    }

    private function tooLarge(): JsonResponse
    {
        return new JsonResponse(['message' => 'Request body is too large.'], 413);
    }

    private function gzipDecodedSize(string $body): ?int
    {
        if (strlen($body) < 18 || ! str_starts_with($body, "\x1f\x8b\x08")) {
            return null;
        }

        $trailer = unpack('Vsize', substr($body, -4));
        $size = $trailer['size'] ?? null;

        return is_int($size) ? $size : null;
    }
}
