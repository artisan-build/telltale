<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Install;
use App\Models\StoredEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StoredEvent> */
final class StoredEventFactory extends Factory
{
    protected $model = StoredEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'install_id' => Install::factory(),
            'app_id' => fn (array $attributes): int => Install::query()->findOrFail($attributes['install_id'])->app_id,
            'event_id' => (string) Str::ulid(),
            'name' => 'example.event',
            'type' => 'event',
            'occurred_at' => now(),
            'session_id' => (string) Str::uuid(),
            'props' => [],
            'error' => null,
            'client_version' => '1.0.0',
        ];
    }
}
