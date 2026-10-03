<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Storage\RawEventPruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class PruneRawEvents extends Command
{
    protected $signature = 'telltale:prune-events {--days= : Override the configured retention period}';

    protected $description = 'Delete expired raw Telltale events while preserving projections and aggregates.';

    public function handle(RawEventPruner $pruner): int
    {
        $configured = $this->option('days') ?? config('telltale.storage.raw_event_retention_days');

        if (filter_var($configured, FILTER_VALIDATE_INT) === false || (int) $configured < 1) {
            $this->error('Retention days must be a positive integer.');

            return self::FAILURE;
        }

        $deleted = $pruner->prune(Date::now('UTC')->toImmutable()->subDays((int) $configured));
        $this->info("Pruned {$deleted} expired raw event(s); projections and aggregates were preserved.");

        return self::SUCCESS;
    }
}
