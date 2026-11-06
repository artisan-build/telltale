<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes;

use Closure;
use Throwable;

final class NativeBridge
{
    /** @param (Closure(string, string): mixed)|null $call */
    public function __construct(private readonly ?Closure $call = null) {}

    public function available(): bool
    {
        return $this->call !== null || function_exists('nativephp_call');
    }

    /** @return array<string, mixed>|null */
    public function pending(): ?array
    {
        $data = $this->invoke('Pending', []);

        return ($data['schema_version'] ?? null) === 1
            && is_array($data['reports'] ?? null) && array_is_list($data['reports'])
            && count($data['reports']) <= 32 ? $data : null;
    }

    /** @param list<string> $ids */
    public function acknowledge(array $ids): bool
    {
        return ($this->invoke('Acknowledge', ['report_ids' => $ids])['scheduled'] ?? false) === true;
    }

    public function suppress(bool $suppressed): bool
    {
        return ($this->invoke('SetSuppressed', ['suppressed' => $suppressed])['scheduled'] ?? false) === true;
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function invoke(string $method, array $parameters): ?array
    {
        try {
            if (! $this->available()) {
                return null;
            }
            $json = json_encode((object) $parameters, JSON_THROW_ON_ERROR);
            $response = $this->call !== null
                ? ($this->call)('TelltaleCrashes.'.$method, $json)
                : \nativephp_call('TelltaleCrashes.'.$method, $json);
            if (is_string($response)) {
                if (strlen($response) > 8_388_608) {
                    return null;
                }
                $response = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            }
            if (! is_array($response) || ($response['status'] ?? null) === 'error') {
                return null;
            }
            // Mobile v4 returns data directly; tolerate adapters that wrap it in data.
            $data = $response['data'] ?? $response;

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }
}
