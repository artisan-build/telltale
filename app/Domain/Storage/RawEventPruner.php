<?php

declare(strict_types=1);

namespace App\Domain\Storage;

use App\Models\StoredEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RawEventPruner
{
    public function prune(CarbonImmutable $cutoff): int
    {
        return DB::transaction(function () use ($cutoff): int {
            DB::table('raw_event_prune_watermarks')->upsert(
                [['id' => 1, 'pruned_before' => $cutoff]],
                ['id'],
                ['pruned_before' => DB::raw('GREATEST(raw_event_prune_watermarks.pruned_before, EXCLUDED.pruned_before)')],
            );

            return StoredEvent::query()->where('occurred_at', '<', $cutoff)->delete();
        });
    }
}
