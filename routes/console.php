<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The only enforcement of telltale.storage.raw_event_retention_days. Without this entry the
// retention window is a default nobody applies and raw events accumulate forever.
Schedule::command('telltale:prune-events')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->onOneServer();
