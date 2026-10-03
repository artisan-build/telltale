<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Exceptions\IngestRateExceeded;
use App\Exceptions\InvalidIngestConfiguration;
use App\Models\TrackedApp;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

final readonly class IngestRequestLimiter
{
    public function __construct(
        private RateLimiter $limiter,
        private IngestHealthRecorder $health,
    ) {}

    public function consumeIp(Request $request): void
    {
        $maximum = config('telltale.ingest.ip_rate_per_minute');

        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidIngestConfiguration;
        }

        $this->consume('telltale:ip:'.$this->opaque((string) $request->ip()), $maximum);
    }

    public function consumeApp(TrackedApp $app): void
    {
        $this->consume('telltale:app:'.$app->id, $app->rate_per_minute, $app);
    }

    public function consumeInstall(TrackedApp $app, string $identifier): void
    {
        $this->consume(
            'telltale:install:'.$app->id.':'.$this->opaque($identifier),
            $app->install_rate_per_minute,
            $app,
        );
    }

    private function consume(string $key, int $maximum, ?TrackedApp $app = null): void
    {
        $attempts = $this->limiter->hit($key, 60);

        if ($attempts > $maximum) {
            if ($app instanceof TrackedApp) {
                $this->health->rateLimit($app);
            }

            throw new IngestRateExceeded(max(1, $this->limiter->availableIn($key)));
        }
    }

    private function opaque(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
