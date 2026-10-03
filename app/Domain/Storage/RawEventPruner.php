<?php

declare(strict_types=1);

namespace App\Domain\Storage;

use App\Models\StoredEvent;
use Carbon\CarbonImmutable;

final class RawEventPruner
{
    public function prune(CarbonImmutable $cutoff): int
    {
        return StoredEvent::query()->where('occurred_at', '<', $cutoff)->delete();
    }
}
