<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes;

use ArtisanBuild\TelltaleClient\Events\ConsentChanged;
use ArtisanBuild\TelltaleCrashes\Console\InstallPrivacyResources;
use ArtisanBuild\TelltaleCrashes\Events\ReportsReady;
use ArtisanBuild\TelltaleCrashes\Listeners\ScheduleImport;
use ArtisanBuild\TelltaleCrashes\Listeners\SyncConsent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

final class TelltaleCrashesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telltale-crashes.php', 'telltale-crashes');
        $this->app->singleton(NativeBridge::class);
        $this->app->singleton(ImportScheduler::class);
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(ReportsReady::class, ScheduleImport::class);
        $events->listen(ConsentChanged::class, SyncConsent::class);
        $this->publishes([__DIR__.'/../config/telltale-crashes.php' => config_path('telltale-crashes.php')], 'telltale-crashes-config');
        if ($this->app->runningInConsole()) {
            $this->commands([InstallPrivacyResources::class]);
        } else {
            // Queue workers boot the app too. They must never schedule another boot job.
            $this->app->booted(fn () => $this->app->make(ImportScheduler::class)->schedule());
        }
    }
}
