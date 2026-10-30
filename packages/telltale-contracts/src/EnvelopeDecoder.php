<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

final class EnvelopeDecoder
{
    /** @param string|array<string, mixed> $payload */
    public static function decode(string|array $payload): EnvelopeV1|EnvelopeV2
    {
        $class = match (EnvelopeV1::versionFrom($payload)) {
            1 => EnvelopeV1::class,
            2 => EnvelopeV2::class,
            default => throw new ContractException('Envelope version is not supported.'),
        };

        return is_string($payload) ? $class::fromJson($payload) : $class::fromArray($payload);
    }
}
