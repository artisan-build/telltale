# Telltale Contracts

Versioned wire contracts shared by the Telltale server and NativePHP client.

## Envelope v1

`EnvelopeV1` serializes this JSON object:

```json
{
  "envelope_version": 1,
  "client_version": "1.0.0",
  "dropped_events_total": 0,
  "events": [
    {
      "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
      "name": "checkout.completed",
      "type": "event",
      "ts": "2026-10-03T12:34:56.123Z",
      "session_id": "session-1",
      "props": {}
    }
  ]
}
```

The envelope version is exactly `1`, `client_version` is non-empty, `dropped_events_total` is a
non-negative integer, and `events` is a list. The drop count is cumulative per install rather than
per batch. A server can therefore persist the latest count without counting the same drops again
when a batch is replayed.

Each event has a canonical uppercase ULID `event_id`, a non-empty `name`, one of the exact event
types `screen`, `event`, `error`, `session_start`, `session_end`, or `context`, an RFC3339 `ts`, a
non-empty `session_id`, and a JSON-object `props`. An `error` object is required only when `type` is
`error`; other event types reject it. Error details contain a non-empty `class`, `message`, non-empty
`file`, positive integer `line`, list of string `stack` frames, and optional non-empty string
`fingerprint`.

### Limits

Limits are measured in bytes and are fixed for v1:

| Value | Maximum |
| --- | ---: |
| Every property-object key, recursively | 128 bytes |
| Every property string value, recursively | 4,096 bytes |
| Error message | 4,096 bytes |
| Error file | 1,024 bytes |
| Error stack frames | 100 |
| Each error stack frame | 2,048 bytes |

Properties may otherwise contain JSON objects, lists, strings, finite numbers, booleans, and null.
Non-finite numbers and PHP values that JSON cannot represent are rejected. Empty `props` always
serializes as `{}`.

### Compatibility And Identity

Decoding ignores unknown object fields so producers can add fields without breaking a v1 consumer.
Malformed JSON, invalid shapes or values, and unsupported versions throw `ContractException`.
`EnvelopeV1::versionFrom()` reads the declared positive version without requiring v1 decoding, so a
server must inspect the version first and can return an actionable response for a newer producer.

Install tokens are header-only credentials. Ingest values or keys, app IDs, and install IDs are not
members of the envelope or event body. Unknown fields with those names are ignored during decoding
and are never emitted during encoding. Runtime transport, authentication, and persistence are
outside this package.
