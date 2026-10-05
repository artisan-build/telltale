<?php

use App\Console\Commands\DeleteInstallData;
use App\Console\Commands\GenerateDocumentation;
use App\Console\Commands\InstallFluxPro;
use App\Console\Commands\OptimizeTailwind;
use App\Http\Middleware\RejectIngestCredentialsFromReadSurface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        DeleteInstallData::class,
        GenerateDocumentation::class,
        InstallFluxPro::class,
        OptimizeTailwind::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RejectIngestCredentialsFromReadSurface::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
