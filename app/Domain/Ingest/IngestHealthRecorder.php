<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Models\TrackedApp;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class IngestHealthRecorder
{
    public function rejection(TrackedApp $app): void
    {
        $this->increment($app, 'rejection_count');
    }

    public function rateLimit(TrackedApp $app): void
    {
        $this->increment($app, 'rate_limit_count');
    }

    private function increment(TrackedApp $app, string $column): void
    {
        DB::transaction(function () use ($app, $column): void {
            $date = Date::now('UTC')->toDateString();
            $now = Date::now('UTC');
            DB::table('ingest_health_daily')->insertOrIgnore([
                'app_id' => $app->id,
                'health_date' => $date,
                'rejection_count' => 0,
                'rate_limit_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('ingest_health_daily')
                ->where('app_id', $app->id)
                ->where('health_date', $date)
                ->increment($column, 1, ['updated_at' => $now]);
        });
    }
}
