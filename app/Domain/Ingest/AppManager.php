<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Models\TrackedApp;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AppManager
{
    public const int MAX_NAME_LENGTH = 255;

    /**
     * @param  array{rate_per_minute?: int, install_rate_per_minute?: int, daily_event_cap?: int, install_daily_event_cap?: int}  $limits
     */
    public function create(string $name, array $limits = []): CreatedApp
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('The app name must not be empty.');
        }

        if (preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new InvalidArgumentException('The app name must be valid text.');
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('The app name must not exceed 255 bytes.');
        }

        $credential = Credential::issue('tti');

        $app = DB::transaction(fn (): TrackedApp => TrackedApp::query()->create([
            'name' => $name,
            'ingest_key_hash' => $credential->hash(),
            'rate_per_minute' => $this->limit($limits, 'rate_per_minute', 'telltale.ingest.app_rate_per_minute'),
            'install_rate_per_minute' => $this->limit($limits, 'install_rate_per_minute', 'telltale.ingest.install_rate_per_minute'),
            'daily_event_cap' => $this->limit($limits, 'daily_event_cap', 'telltale.ingest.app_daily_event_cap'),
            'install_daily_event_cap' => $this->limit($limits, 'install_daily_event_cap', 'telltale.ingest.install_daily_event_cap'),
        ]));

        return new CreatedApp($app, $credential->value());
    }

    public function rotateIngestKey(TrackedApp $app): string
    {
        $credential = Credential::issue('tti');

        DB::transaction(function () use ($app, $credential): void {
            TrackedApp::query()
                ->whereKey($app->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->forceFill(['ingest_key_hash' => $credential->hash()])
                ->save();
        });

        $app->refresh();

        return $credential->value();
    }

    /** @param array<string, int> $limits */
    private function limit(array $limits, string $name, string $configuration): int
    {
        $value = $limits[$name] ?? config($configuration);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return $value;
    }
}
