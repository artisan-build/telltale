<?php

declare(strict_types=1);

use App\Http\Controllers\SetupController;
use App\Http\Controllers\TelltaleDashboard;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUiAuthority;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureUiAuthority::class, EnsureUserIsAuthenticated::class])->group(function (): void {
    Route::get('/dashboard/apps/{app}', [TelltaleDashboard::class, 'show'])
        ->whereNumber('app')
        ->name('telltale.apps.show');
    Route::post('/dashboard/apps', [TelltaleDashboard::class, 'store'])
        ->name('telltale.apps.store');
    Route::post('/dashboard/apps/{app}/rotate', [TelltaleDashboard::class, 'rotate'])
        ->whereNumber('app')
        ->name('telltale.apps.rotate');
    Route::get('/setup', SetupController::class)
        ->name('telltale.setup');
});
