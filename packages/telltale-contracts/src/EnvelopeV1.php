<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

use JsonException;
use JsonSerializable;
use stdClass;

final readonly class EnvelopeV1 implements JsonSerializable
{
    public const VERSION = 1;

    public const MAX_CLIENT_VERSION_LENGTH = 255;

    /**
     * @var list<Event>
     */
    public array $events;

    /**
     * @param  array<mixed>  $events
     */
    public function __construct(
        public string $clientVersion,
        public int $droppedEventsTotal,
        array $events,
    ) {
        if (preg_match('//u', $clientVersion) !== 1) {
            throw new ContractException('client_version must be valid UTF-8.');
        }

        if ($clientVersion === '') {
            throw new ContractException('client_version must not be empty.');
        }

        if (str_contains($clientVersion, "\0")) {
            throw new ContractException('client_version must not contain NUL bytes.');
        }

        if (strlen($clientVersion) > self::MAX_CLIENT_VERSION_LENGTH) {
            throw new ContractException('client_version exceeds the maximum length of 255 bytes.');
        }

        if ($droppedEventsTotal < 0) {
            throw new ContractException('dropped_events_total must be non-negative.');
        }

        if (! array_is_list($events)) {
            throw new ContractException('events must be a list.');
        }

        $validatedEvents = [];

        foreach ($events as $event) {
            if (! $event instanceof Event) {
                throw new ContractException('events must contain only Event values.');
            }

            $validatedEvents[] = $event;
        }

        $this->events = $validatedEvents;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = self::versionFrom($data);

        if ($version !== self::VERSION) {
            throw new ContractException("Envelope version {$version} is not supported.");
        }

        $clientVersion = $data['client_version'] ?? null;

        if (! is_string($clientVersion)) {
            throw new ContractException('client_version must be a string.');
        }

        $droppedEventsTotal = $data['dropped_events_total'] ?? null;

        if (! is_int($droppedEventsTotal)) {
            throw new ContractException('dropped_events_total must be an integer.');
        }

        $eventData = $data['events'] ?? null;

        if (! is_array($eventData) || ! array_is_list($eventData)) {
            throw new ContractException('events must be a list.');
        }

        $events = [];

        foreach ($eventData as $item) {
            if ($item instanceof stdClass) {
                $item = get_object_vars($item);
            }

            if (! is_array($item)) {
                throw new ContractException('Each event must be an object.');
            }

            $events[] = Event::fromArray($item);
        }

        return new self($clientVersion, $droppedEventsTotal, $events);
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(self::decodeObject($json));
    }

    /**
     * @param  string|array<string, mixed>  $payload
     */
    public static function versionFrom(string|array $payload): int
    {
        $data = is_string($payload) ? self::decodeObject($payload) : $payload;
        $version = $data['envelope_version'] ?? null;

        if (! is_int($version) || $version < 1) {
            throw new ContractException('envelope_version must be a positive integer.');
        }

        return $version;
    }

    /**
     * @return array{envelope_version: int, client_version: string, dropped_events_total: int, events: list<array{event_id: string, name: string, type: string, ts: string, session_id: string, props: array<string, mixed>, error?: array{class: string, message: string, file: string, line: int, stack: list<string>, fingerprint?: string}}>}
     */
    public function toArray(): array
    {
        return [
            'envelope_version' => self::VERSION,
            'client_version' => $this->clientVersion,
            'dropped_events_total' => $this->droppedEventsTotal,
            'events' => array_map(
                static fn (Event $event): array => $event->toArray(),
                $this->events,
            ),
        ];
    }

    public function toJson(): string
    {
        try {
            return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ContractException('The envelope could not be encoded as JSON.', previous: $exception);
        }
    }

    /**
     * @return array{envelope_version: int, client_version: string, dropped_events_total: int, events: list<Event>}
     */
    public function jsonSerialize(): array
    {
        return [
            'envelope_version' => self::VERSION,
            'client_version' => $this->clientVersion,
            'dropped_events_total' => $this->droppedEventsTotal,
            'events' => $this->events,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContractException('The envelope is not valid JSON.', previous: $exception);
        }

        if (! $decoded instanceof stdClass) {
            throw new ContractException('The envelope must be a JSON object.');
        }

        return get_object_vars($decoded);
    }
}
