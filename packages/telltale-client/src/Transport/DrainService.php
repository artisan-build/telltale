<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Transport;

use ArtisanBuild\TelltaleClient\Storage\ClientDatabase;
use ArtisanBuild\TelltaleClient\Storage\OutboxItem;
use ArtisanBuild\TelltaleContracts\EnvelopeV1;
use ArtisanBuild\TelltaleContracts\EnvelopeV2;
use ArtisanBuild\TelltaleContracts\Event;
use ArtisanBuild\TelltaleContracts\EventType;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

final readonly class DrainService
{
    public function __construct(
        private ClientDatabase $database,
        private RegistrationClient $registration,
        private Factory $http,
        private Repository $config,
    ) {}

    public function drain(): DrainResult
    {
        try {
            if ($this->database->isOptedOut()) {
                return DrainResult::succeeded(0);
            }

            $now = time();
            $this->database->prune(
                now: $now,
                maxRows: max(1, (int) $this->config->get('telltale.max_rows', 10_000)),
                maxAgeSeconds: max(1, (int) $this->config->get('telltale.max_age_seconds', 2_592_000)),
            );
            $nextAttempt = $this->database->nextAttemptAt();

            if ($nextAttempt > $now) {
                return DrainResult::deferred($nextAttempt - $now);
            }

            $items = $this->database->batch(
                $this->database->transportBatchLimit(max(1, (int) $this->config->get('telltale.batch_size', 100))),
            );

            $items = $this->boundedPrefix($items);

            if ($items === []) {
                $this->database->resetTransportBackoff();

                return DrainResult::succeeded(0);
            }

            if (! $this->registration->register()) {
                return $this->failed($now);
            }

            $response = $this->send($items);

            if ($response?->status() === 401) {
                $this->database->clearToken();

                if (! $this->registration->register(force: true)) {
                    return $this->failed($now);
                }

                $response = $this->send($items);
            }

            if ($response?->status() === 413) {
                if (count($items) === 1) {
                    $this->database->drop([$items[0]->sequence]);
                } else {
                    $this->database->reduceTransportBatchLimit(count($items));
                }

                return DrainResult::deferred(1);
            }

            if ($response?->status() !== 202) {
                return $this->failed($now);
            }

            $this->database->acknowledge(array_map(
                static fn (OutboxItem $item): int => $item->sequence,
                $items,
            ));
            $this->database->resetTransportBackoff();

            return DrainResult::succeeded(count($items));
        } catch (Throwable) {
            return $this->failed(time());
        }
    }

    /**
     * @param  list<OutboxItem>  $items
     * @return list<OutboxItem>
     */
    private function boundedPrefix(array $items): array
    {
        $selected = [];
        $limit = max(1, (int) $this->config->get('telltale.batch_max_bytes', 524_288));

        foreach ($items as $item) {
            $candidate = [...$selected, $item];
            if (strlen($this->envelope($candidate)) <= $limit) {
                $selected = $candidate;

                continue;
            }

            if ($selected !== []) {
                break;
            }

            // One oversized row must not poison everything behind it.
            $this->database->drop([$item->sequence]);
        }

        return $selected;
    }

    /** @param list<OutboxItem> $items */
    private function envelope(array $items): string
    {
        $events = array_map(
            static fn (OutboxItem $item): Event => Event::fromArray($item->event),
            $items,
        );
        $hasCrash = array_any($events, static fn (Event $event): bool => $event->type === EventType::Crash);
        $class = $hasCrash ? EnvelopeV2::class : EnvelopeV1::class;

        return (new $class(
            clientVersion: (string) $this->config->get('telltale.client_version', '1.0.0'),
            droppedEventsTotal: $this->database->droppedEventsTotal(),
            events: $events,
        ))->toJson();
    }

    /**
     * @param  list<OutboxItem>  $items
     */
    private function send(array $items): ?Response
    {
        $token = $this->database->token();
        $url = $this->baseUrl();

        if ($token === null || $url === null) {
            return null;
        }

        try {
            $body = gzencode($this->envelope($items));

            if ($body === false) {
                return null;
            }

            return $this->http
                ->timeout(max(1, (int) $this->config->get('telltale.http_timeout_seconds', 10)))
                ->acceptJson()
                ->withToken($token)
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->withBody($body, 'application/json')
                ->post($url.'/api/ingest');
        } catch (Throwable) {
            return null;
        }
    }

    private function failed(int $now): DrainResult
    {
        try {
            $delay = $this->database->markTransportFailure(
                now: $now,
                initialSeconds: max(1, (int) $this->config->get('telltale.backoff.initial_seconds', 15)),
                maximumSeconds: max(1, (int) $this->config->get('telltale.backoff.maximum_seconds', 3_600)),
            );

            return DrainResult::failed($delay);
        } catch (Throwable) {
            return DrainResult::failed();
        }
    }

    private function baseUrl(): ?string
    {
        $url = $this->config->get('telltale.url');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return rtrim($url, '/');
    }
}
