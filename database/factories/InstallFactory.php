<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Install;
use App\Models\TrackedApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Install> */
final class InstallFactory extends Factory
{
    protected $model = Install::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'app_id' => TrackedApp::factory(),
            'install_uuid' => fake()->unique()->uuid(),
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'dropped_events_total' => 0,
            'revoked_at' => null,
        ];
    }
}
