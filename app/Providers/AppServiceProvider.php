<?php

namespace App\Providers;

use App\Authorization\TelltaleAbility;
use App\Console\Commands\PruneRawEvents;
use ArtisanBuild\BuiltForCloud\CredentialAbilityRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        // Scheduled commands are registered here so the Built for Cloud system-authority
        // conformance inventory can attribute each schedule entry to inspectable source.
        $this->commands([
            PruneRawEvents::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(CredentialAbilityRegistry $credentialAbilities): void
    {
        $credentialAbilities->register(TelltaleAbility::Read->value);
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
