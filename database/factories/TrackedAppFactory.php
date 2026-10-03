<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TrackedApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrackedApp> */
final class TrackedAppFactory extends Factory
{
    protected $model = TrackedApp::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'ingest_key_hash' => hash('sha256', fake()->unique()->uuid()),
            'rate_per_minute' => 120,
            'install_rate_per_minute' => 60,
            'daily_event_cap' => 100_000,
            'install_daily_event_cap' => 10_000,
        ];
    }
}
