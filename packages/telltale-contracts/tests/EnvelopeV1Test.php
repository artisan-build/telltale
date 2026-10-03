<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleContracts\ContractException;
use ArtisanBuild\TelltaleContracts\EnvelopeV1;
use ArtisanBuild\TelltaleContracts\ErrorDetails;
use ArtisanBuild\TelltaleContracts\Event;
use ArtisanBuild\TelltaleContracts\EventType;

function validEvent(array $overrides = []): array
{
    return array_replace([
        'event_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'name' => 'checkout.completed',
        'type' => 'event',
        'ts' => '2026-10-03T12:34:56.123Z',
        'session_id' => 'session-1',
        'props' => [],
    ], $overrides);
}

function validEnvelope(array $overrides = []): array
{
    return array_replace([
        'envelope_version' => 1,
        'client_version' => '1.2.3',
        'dropped_events_total' => 0,
        'events' => [validEvent()],
    ], $overrides);
}

function validError(array $overrides = []): array
{
    return array_replace([
        'class' => 'RuntimeException',
        'message' => 'Something failed',
        'file' => '/app/Action.php',
        'line' => 42,
        'stack' => ['#0 /app/Action.php(42): run()'],
        'fingerprint' => 'runtime-action',
    ], $overrides);
}

it('round-trips the canonical envelope with exact keys and object props', function (): void {
    $payload = validEnvelope([
        'dropped_events_total' => 7,
        'events' => [
            validEvent(['props' => ['plan' => 'pro', 'nested' => ['enabled' => true]]]),
            validEvent([
                'event_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
                'name' => 'failure',
                'type' => 'error',
                'props' => [],
                'error' => validError(),
            ]),
        ],
    ]);

    $envelope = EnvelopeV1::fromArray($payload);
    $json = $envelope->toJson();

    expect($envelope->toArray())->toBe($payload)
        ->and(array_keys($envelope->toArray()))->toBe([
            'envelope_version',
            'client_version',
            'dropped_events_total',
            'events',
        ])
        ->and(array_keys($envelope->toArray()['events'][0]))->toBe([
            'event_id',
            'name',
            'type',
            'ts',
            'session_id',
            'props',
        ])
        ->and($json)->toContain('"props":{}')
        ->and(EnvelopeV1::fromJson($json)->toArray())->toBe($payload);
});

it('preserves nested JSON object and list shapes', function (): void {
    $json = json_encode(validEnvelope([
        'events' => [validEvent([
            'props' => [
                'empty_object' => new stdClass,
                'empty_list' => [],
                'nested' => ['values' => [1, 2.5, true, null]],
            ],
        ])],
    ]), JSON_THROW_ON_ERROR);

    expect(EnvelopeV1::fromJson($json)->toJson())->toBe($json);
});

it('serializes every event type exactly', function (EventType $type, string $value): void {
    $event = validEvent(['type' => $value]);

    if ($type === EventType::Error) {
        $event['error'] = validError();
    }

    expect(EnvelopeV1::fromArray(validEnvelope(['events' => [$event]]))->toArray()['events'][0]['type'])
        ->toBe($value);
})->with([
    'screen' => [EventType::Screen, 'screen'],
    'event' => [EventType::Event, 'event'],
    'error' => [EventType::Error, 'error'],
    'session start' => [EventType::SessionStart, 'session_start'],
    'session end' => [EventType::SessionEnd, 'session_end'],
    'context' => [EventType::Context, 'context'],
]);

it('rejects invalid event scalar values', function (array $event): void {
    EnvelopeV1::fromArray(validEnvelope(['events' => [$event]]));
})->throws(ContractException::class)->with([
    'lowercase ULID' => [validEvent(['event_id' => '01arz3ndektsv4rrffq69g5fav'])],
    'overflow ULID' => [validEvent(['event_id' => '81ARZ3NDEKTSV4RRFFQ69G5FAV'])],
    'short ULID' => [validEvent(['event_id' => '01ARZ3NDEKTSV4RRFFQ69G5FA'])],
    'unsupported type' => [validEvent(['type' => 'custom'])],
    'invalid date' => [validEvent(['ts' => '2026-02-30T12:34:56Z'])],
    'invalid timestamp syntax' => [validEvent(['ts' => '2026-10-03 12:34:56'])],
    'empty name' => [validEvent(['name' => ''])],
    'empty session' => [validEvent(['session_id' => ''])],
    'overlong name' => [validEvent(['name' => str_repeat('n', Event::MAX_NAME_LENGTH + 1)])],
    'overlong session' => [validEvent(['session_id' => str_repeat('s', Event::MAX_SESSION_ID_LENGTH + 1)])],
]);

it('accepts database-backed strings at their upper boundaries', function (): void {
    $payload = validEnvelope([
        'client_version' => str_repeat('v', EnvelopeV1::MAX_CLIENT_VERSION_LENGTH),
        'events' => [validEvent([
            'name' => str_repeat('n', Event::MAX_NAME_LENGTH),
            'session_id' => str_repeat('s', Event::MAX_SESSION_ID_LENGTH),
        ])],
    ]);

    expect(EnvelopeV1::fromArray($payload)->toArray())->toBe($payload);
});

it('rejects invalid envelope and event shapes', function (array $payload): void {
    EnvelopeV1::fromArray($payload);
})->throws(ContractException::class)->with([
    'events is not a list' => [validEnvelope(['events' => ['event' => validEvent()]])],
    'event is not an object' => [validEnvelope(['events' => ['event']])],
    'props is a list' => [validEnvelope(['events' => [validEvent(['props' => ['value']])]])],
    'props is scalar' => [validEnvelope(['events' => [validEvent(['props' => 'value'])]])],
]);

it('rejects malformed JSON and non-object roots with the package exception', function (string $json): void {
    EnvelopeV1::fromJson($json);
})->throws(ContractException::class)->with([
    'malformed' => ['{"envelope_version":'],
    'list' => ['[]'],
    'scalar' => ['1'],
]);

it('enforces error presence by event type', function (array $event): void {
    EnvelopeV1::fromArray(validEnvelope(['events' => [$event]]));
})->throws(ContractException::class)->with([
    'missing from error event' => [validEvent(['type' => 'error'])],
    'present on normal event' => [validEvent(['error' => validError()])],
    'null on normal event' => [validEvent(['error' => null])],
]);

it('accepts bounded error details at every upper limit', function (): void {
    $error = validError([
        'message' => str_repeat('m', ErrorDetails::MAX_MESSAGE_LENGTH),
        'file' => str_repeat('f', ErrorDetails::MAX_FILE_LENGTH),
        'stack' => array_fill(0, ErrorDetails::MAX_STACK_FRAMES, str_repeat('s', ErrorDetails::MAX_STACK_FRAME_LENGTH)),
    ]);
    $event = validEvent(['type' => 'error', 'error' => $error]);

    expect(EnvelopeV1::fromArray(validEnvelope(['events' => [$event]]))->toArray()['events'][0]['error'])
        ->toBe($error);
});

it('accepts bounded error details at their lower limits', function (array $overrides): void {
    $event = validEvent(['type' => 'error', 'error' => validError($overrides)]);

    expect(EnvelopeV1::fromArray(validEnvelope(['events' => [$event]]))->events)->toHaveCount(1);
})->with([
    'empty message' => [['message' => '']],
    'one-byte file' => [['file' => 'f']],
    'empty stack' => [['stack' => []]],
    'empty frame' => [['stack' => ['']]],
]);

it('rejects error details over every cap', function (array $error): void {
    $event = validEvent(['type' => 'error', 'error' => $error]);

    EnvelopeV1::fromArray(validEnvelope(['events' => [$event]]));
})->throws(ContractException::class)->with([
    'message length' => [validError(['message' => str_repeat('m', ErrorDetails::MAX_MESSAGE_LENGTH + 1)])],
    'file length' => [validError(['file' => str_repeat('f', ErrorDetails::MAX_FILE_LENGTH + 1)])],
    'stack count' => [validError(['stack' => array_fill(0, ErrorDetails::MAX_STACK_FRAMES + 1, 'frame')])],
    'frame length' => [validError(['stack' => [str_repeat('s', ErrorDetails::MAX_STACK_FRAME_LENGTH + 1)]])],
]);

it('rejects malformed error detail values', function (array $error): void {
    $event = validEvent(['type' => 'error', 'error' => $error]);

    EnvelopeV1::fromArray(validEnvelope(['events' => [$event]]));
})->throws(ContractException::class)->with([
    'empty class' => [validError(['class' => ''])],
    'empty file' => [validError(['file' => ''])],
    'zero line' => [validError(['line' => 0])],
    'non-integer line' => [validError(['line' => 1.5])],
    'stack is not a list' => [validError(['stack' => ['frame' => 'value']])],
    'stack contains non-string' => [validError(['stack' => [1]])],
    'empty fingerprint' => [validError(['fingerprint' => ''])],
    'null fingerprint' => [validError(['fingerprint' => null])],
]);

it('accepts property keys and strings at both caps', function (): void {
    $props = [
        str_repeat('k', Event::MAX_PROPERTY_KEY_LENGTH) => str_repeat('v', Event::MAX_PROPERTY_STRING_LENGTH),
        'nested' => [str_repeat('n', Event::MAX_PROPERTY_KEY_LENGTH) => str_repeat('x', Event::MAX_PROPERTY_STRING_LENGTH)],
    ];

    expect(EnvelopeV1::fromArray(validEnvelope([
        'events' => [validEvent(['props' => $props])],
    ]))->toArray()['events'][0]['props'])->toBe($props);
});

it('accepts empty property keys and string values at the lower cap boundary', function (): void {
    $props = ['' => ''];

    expect(EnvelopeV1::fromArray(validEnvelope([
        'events' => [validEvent(['props' => $props])],
    ]))->toArray()['events'][0]['props'])->toBe($props);
});

it('rejects property keys and strings over both caps recursively', function (array $props): void {
    EnvelopeV1::fromArray(validEnvelope(['events' => [validEvent(['props' => $props])]]));
})->throws(ContractException::class)->with([
    'top-level key' => [[str_repeat('k', Event::MAX_PROPERTY_KEY_LENGTH + 1) => true]],
    'nested key' => [['nested' => [str_repeat('k', Event::MAX_PROPERTY_KEY_LENGTH + 1) => true]]],
    'top-level string' => [['value' => str_repeat('v', Event::MAX_PROPERTY_STRING_LENGTH + 1)]],
    'nested string' => [['nested' => ['value' => str_repeat('v', Event::MAX_PROPERTY_STRING_LENGTH + 1)]]],
]);

it('rejects non-finite and non-JSON property values', function (mixed $value): void {
    EnvelopeV1::fromArray(validEnvelope(['events' => [validEvent(['props' => ['value' => $value]])]]));
})->throws(ContractException::class)->with([
    'infinity' => [INF],
    'negative infinity' => [-INF],
    'not a number' => [NAN],
    'object' => [new DateTimeImmutable],
    'invalid UTF-8 string' => ["\xB1\x31"],
]);

it('rejects NUL bytes from PostgreSQL-backed text and JSON values', function (): void {
    $payloads = [
        validEnvelope(['client_version' => "1.0\0invalid"]),
        validEnvelope(['events' => [validEvent(['name' => "name\0invalid"])]]),
        validEnvelope(['events' => [validEvent(['session_id' => "session\0invalid"])]]),
        validEnvelope(['events' => [validEvent(['props' => ["key\0invalid" => true]])]]),
        validEnvelope(['events' => [validEvent(['props' => ['value' => "text\0invalid"]])]]),
        validEnvelope(['events' => [validEvent([
            'type' => 'error',
            'error' => validError(['message' => "message\0invalid"]),
        ])]]),
    ];

    foreach ($payloads as $payload) {
        expect(fn () => EnvelopeV1::fromArray($payload))->toThrow(ContractException::class);
    }
});

it('accepts zero and positive cumulative dropped counts', function (int $count): void {
    expect(EnvelopeV1::fromArray(validEnvelope(['dropped_events_total' => $count]))->droppedEventsTotal)
        ->toBe($count);
})->with([0, 1, 1000]);

it('rejects invalid cumulative dropped counts', function (mixed $count): void {
    EnvelopeV1::fromArray(validEnvelope(['dropped_events_total' => $count]));
})->throws(ContractException::class)->with([
    'negative' => [-1],
    'float' => [1.0],
    'string' => ['1'],
]);

it('rejects invalid client versions', function (mixed $version): void {
    EnvelopeV1::fromArray(validEnvelope(['client_version' => $version]));
})->throws(ContractException::class)->with([
    'empty' => [''],
    'integer' => [1],
    'invalid UTF-8' => ["\xB1\x31"],
    'overlong' => [str_repeat('v', EnvelopeV1::MAX_CLIENT_VERSION_LENGTH + 1)],
]);

it('never admits identity or credential fields into the body', function (): void {
    $payload = validEnvelope([
        'install_token' => 'header-only',
        'ingest' => 'public-ingest-value',
        'ingest_key' => 'legacy-name',
        'app_id' => 'forged-app',
        'install_id' => 'forged-install',
        'events' => [validEvent([
            'install_token' => 'event-token',
            'app_id' => 'event-app',
            'install_id' => 'event-install',
        ])],
    ]);

    $encoded = EnvelopeV1::fromArray($payload)->toArray();
    $json = EnvelopeV1::fromArray($payload)->toJson();

    expect(array_keys($encoded))->toBe(['envelope_version', 'client_version', 'dropped_events_total', 'events'])
        ->and(array_keys($encoded['events'][0]))->toBe(['event_id', 'name', 'type', 'ts', 'session_id', 'props'])
        ->and($json)->not->toContain('install_token')
        ->and($json)->not->toContain('ingest')
        ->and($json)->not->toContain('app_id')
        ->and($json)->not->toContain('install_id');
});

it('extracts newer versions before v1 decoding rejects them', function (): void {
    $payload = validEnvelope(['envelope_version' => 2, 'future_field' => true]);
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect(EnvelopeV1::versionFrom($payload))->toBe(2)
        ->and(EnvelopeV1::versionFrom($json))->toBe(2);

    EnvelopeV1::fromJson($json);
})->throws(ContractException::class);

it('ignores unknown additive fields during v1 decoding', function (): void {
    $payload = validEnvelope([
        'future_envelope' => ['enabled' => true],
        'events' => [validEvent(['future_event' => 123])],
    ]);

    expect(EnvelopeV1::fromArray($payload)->toArray())->toBe(validEnvelope());
});

it('rejects invalid version declarations', function (mixed $version): void {
    EnvelopeV1::versionFrom(validEnvelope(['envelope_version' => $version]));
})->throws(ContractException::class)->with([
    'missing-compatible zero' => [0],
    'negative' => [-1],
    'float' => [1.0],
    'string' => ['1'],
]);
