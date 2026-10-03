<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

final readonly class RegisteredInstall
{
    public function __construct(
        private string $token,
        private bool $created,
    ) {}

    public function token(): string
    {
        return $this->token;
    }

    public function wasCreated(): bool
    {
        return $this->created;
    }
}
