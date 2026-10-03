<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleContracts;

use JsonSerializable;
use stdClass;

final readonly class Event implements JsonSerializable
{
    public const MAX_PROPERTY_KEY_LENGTH = 128;

    public const MAX_PROPERTY_STRING_LENGTH = 4096;

    /**
     * @var array<string, mixed>
     */
    public array $props;

    /**
     * @param  array<mixed>  $props
     */
    public function __construct(
        public string $eventId,
        public string $name,
        public EventType $type,
        public string $timestamp,
        public string $sessionId,
        array $props,
        public ?ErrorDetails $error = null,
    ) {
        if (! self::isCanonicalUlid($eventId)) {
            throw new ContractException('event_id must be a canonical ULID.');
        }

        self::assertNonEmpty($name, 'name');
        self::assertTimestamp($timestamp);
        self::assertNonEmpty($sessionId, 'session_id');

        if ($props !== [] && array_is_list($props)) {
            throw new ContractException('props must be a JSON object.');
        }

        $this->props = self::validatedJsonObject($props);

        if ($type === EventType::Error && $error === null) {
            throw new ContractException('Error events require error details.');
        }

        if ($type !== EventType::Error && $error !== null) {
            throw new ContractException('Only error events may carry error details.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $typeValue = self::requiredString($data, 'type');
        $type = EventType::tryFrom($typeValue);

        if ($type === null) {
            throw new ContractException('type is not supported.');
        }

        $props = $data['props'] ?? null;

        if ($props instanceof stdClass) {
            $props = get_object_vars($props);
        }

        if (! is_array($props)) {
            throw new ContractException('props must be a JSON object.');
        }

        $hasError = array_key_exists('error', $data);
        $error = null;

        if ($hasError) {
            $errorData = $data['error'];

            if ($errorData instanceof stdClass) {
                $errorData = get_object_vars($errorData);
            }

            if (! is_array($errorData)) {
                throw new ContractException('error must be an object.');
            }

            $error = ErrorDetails::fromArray($errorData);
        }

        if ($type !== EventType::Error && $hasError) {
            throw new ContractException('Only error events may carry error details.');
        }

        return new self(
            eventId: self::requiredString($data, 'event_id'),
            name: self::requiredString($data, 'name'),
            type: $type,
            timestamp: self::requiredString($data, 'ts'),
            sessionId: self::requiredString($data, 'session_id'),
            props: $props,
            error: $error,
        );
    }

    /**
     * @return array{event_id: string, name: string, type: string, ts: string, session_id: string, props: array<string, mixed>, error?: array{class: string, message: string, file: string, line: int, stack: list<string>, fingerprint?: string}}
     */
    public function toArray(): array
    {
        $data = [
            'event_id' => $this->eventId,
            'name' => $this->name,
            'type' => $this->type->value,
            'ts' => $this->timestamp,
            'session_id' => $this->sessionId,
            'props' => self::toArrayObject($this->props),
        ];

        if ($this->error !== null) {
            $data['error'] = $this->error->toArray();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'event_id' => $this->eventId,
            'name' => $this->name,
            'type' => $this->type->value,
            'ts' => $this->timestamp,
            'session_id' => $this->sessionId,
            'props' => $this->props === [] ? new stdClass : $this->props,
        ];

        if ($this->error !== null) {
            $data['error'] = $this->error->toArray();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new ContractException("{$key} must be a string.");
        }

        return $value;
    }

    private static function assertNonEmpty(string $value, string $field): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new ContractException("{$field} must be valid UTF-8.");
        }

        if ($value === '') {
            throw new ContractException("{$field} must not be empty.");
        }
    }

    private static function isCanonicalUlid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $value) === 1;
    }

    private static function assertTimestamp(string $value): void
    {
        $matches = [];
        $matched = preg_match(
            '/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])-(?<day>0[1-9]|[12]\d|3[01])T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',
            $value,
            $matches,
        );

        if ($matched !== 1 || ! checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year'])) {
            throw new ContractException('ts must be a valid RFC3339 timestamp.');
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private static function validatedJsonObject(array $value): array
    {
        $validated = [];

        foreach ($value as $key => $child) {
            if (! is_string($key)) {
                throw new ContractException('JSON object keys must be strings.');
            }

            self::assertPropertyKey($key);
            self::assertJsonValue($child);
            $validated[$key] = $child;
        }

        return $validated;
    }

    private static function assertJsonValue(mixed $value): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new ContractException('props may not contain non-finite numbers.');
            }

            return;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new ContractException('props strings must be valid UTF-8.');
            }

            if (strlen($value) > self::MAX_PROPERTY_STRING_LENGTH) {
                throw new ContractException('A props string exceeds the maximum length.');
            }

            return;
        }

        if ($value instanceof stdClass) {
            self::validatedJsonObject(get_object_vars($value));

            return;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $child) {
                    self::assertJsonValue($child);
                }

                return;
            }

            self::validatedJsonObject($value);

            return;
        }

        throw new ContractException('props may contain only JSON values.');
    }

    private static function assertPropertyKey(string $key): void
    {
        if (preg_match('//u', $key) !== 1) {
            throw new ContractException('props keys must be valid UTF-8.');
        }

        if (strlen($key) > self::MAX_PROPERTY_KEY_LENGTH) {
            throw new ContractException('A props key exceeds the maximum length.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function toArrayObject(array $value): array
    {
        foreach ($value as $key => $child) {
            $value[$key] = self::toArrayValue($child);
        }

        return $value;
    }

    private static function toArrayValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::toArrayObject(get_object_vars($value));
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::toArrayValue($child);
        }

        return $value;
    }
}
