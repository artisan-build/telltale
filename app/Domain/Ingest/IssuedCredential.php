<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

final readonly class IssuedCredential
{
    public function __construct(
        private string $value,
        private string $hash,
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    public function hash(): string
    {
        return $this->hash;
    }
}
