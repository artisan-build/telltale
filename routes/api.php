<?php

declare(strict_types=1);

use App\Http\Controllers\IngestController;
use App\Http\Controllers\RegisterController;
use App\Http\Middleware\EnforceIngestBodyLimit;
use Illuminate\Support\Facades\Route;

Route::middleware(EnforceIngestBodyLimit::class)->group(function (): void {
    Route::post('/register', RegisterController::class);
    Route::post('/ingest', IngestController::class);
});
