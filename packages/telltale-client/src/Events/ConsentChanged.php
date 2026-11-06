<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Events;

final readonly class ConsentChanged
{
    public function __construct(public bool $optedOut) {}
}
