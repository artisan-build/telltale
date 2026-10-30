# Mobile crash contract

The contracts package defines envelope v2 and crash schema 1. Server ingest, native capture and
client delivery follow in later changes; this document also records their agreed behavior.
Ordinary clients continue sending v1. The existing AsyncTask and desktop behavior stays unchanged.

## Wire format

`EnvelopeV2` has the same batch headers and event fields as v1, with `envelope_version: 2`.
It accepts ordinary events and `type: "crash"` in one batch. A crash event requires a `crash`
object and rejects `error`. Other types reject crash details. `EnvelopeV1` rejects crashes.
`EnvelopeDecoder::decode()` accepts JSON or an array and returns the matching v1/v2 DTO;
unknown versions throw `ContractException`. `Event` adds only an optional constructor argument.

| Field | Rule |
| --- | --- |
| `schema_version` | Integer 1 |
| `report_id` | Stable SHA-256 digest, 64 lowercase hexadecimal characters |
| `platform` | `ios` or `android` |
| `kind` | `crash`, `hang`, `memory_exit`, `watchdog_exit` |
| `source` | See source table below |
| `reason` | Nonempty machine classification, 255 bytes; letters, digits, `_`, `.`, `:`, `-` |
| `fatal` | Boolean, consistent with source and kind |
| `occurred_at` | RFC3339; event `ts` must match this string |
| `timestamp_precision` | `exact` or `window_end` |
| `occurrence_count` | Integer from 1 through 1,000,000 |
| `reporting_window_start`, `reporting_window_end` | Optional together; required for `window_end` |
| `app_version`, `build` | Optional crashed-build strings, each 255 bytes; omit when unknown |
| `frames` | Ordered list of at most 64 typed frames; absent becomes an empty list |
| `trace` | Optional sanitized UTF-8 JVM/ANR text, at most 16,384 bytes |
| `metadata` | Optional JSON object, at most 16 keys; finite scalar values only |

| Platform and source | Kinds and fatality |
| --- | --- |
| iOS `metrickit_diagnostic` | Fatal `crash`, nonfatal `hang` |
| iOS `metrickit_exit` | Fatal `memory_exit` or `watchdog_exit`; requires `window_end` |
| Android `application_exit_info` | Fatal `crash`, `hang` (ANR death), `memory_exit` or `watchdog_exit` |
| Android `jvm_exception` | Fatal `crash` |

Windows must satisfy start <= end and contain the occurrence timestamp. With `window_end`,
`occurred_at` equals the end string. Timestamps are at most 255 bytes. Text must be valid UTF-8
without NUL or non-text control bytes. Metadata keys are nonempty and at most 255 bytes; string
values may be empty and are at most 1,024 bytes. Identity and credential field names are rejected
in metadata. Native projection and the client's Privacy/beforeSend processing must remove sensitive
values; contracts cannot identify a credential hidden in arbitrary text.

Unknown crash and frame fields are rejected, including opaque payload/base64 fields. There is no
raw tombstone field. A native decoder must use the published Android protobuf schema and export
only allowlisted frame/build metadata. Memory dumps, logs, registers, open files and arbitrary
bytes remain off the wire. Trace validation checks text and size, without guessing its encoding.

Frames accept optional `binary_name`, `binary_uuid` (UUID or 8–128 hex-character Android build ID),
`address`, `offset`, `load_address`, and `symbol`. Binary names and IDs are at most 255 bytes;
symbols are at most 1,024. At least a symbol or a binary identity plus offset/address is required.
Locations are lowercase canonical unsigned 64-bit hex strings: `0x0` or `0x` followed by up to
16 digits without leading zeroes. Strings preserve precision above PHP's signed integer range.
When both address and offset exist, derive the load address by unsigned subtraction and check any
supplied load address. Address plus load address can similarly derive offset. Negative differences
and contradictions are rejected; missing inputs are never guessed.

`CrashDetails::fingerprint()` hashes the JSON tuple of platform, kind, reason and the first five
normalized frame locations. Native frames prefer lowercase UUID plus offset, then binary name
plus offset; JVM frames prefer the supplied class/method symbol. Symbols are used when relative
binary locations are unavailable. An address-only frame contributes its binary identity, with
reduced grouping precision. Absolute ASLR addresses never enter the fingerprint. Empty frames
fall back to platform/kind/reason. Trace text, exception messages, report ID and app build are
excluded. The server recomputes this value, while retaining all binary/symbol inputs for later
dSYM/R8 association. No symbol-upload endpoint is part of this phase.

`CrashDetails::eventId()` encodes the first 128 bits of `report_id` as a canonical Crockford ULID
(26 uppercase characters). Its time bits are opaque. Retries use the same event ID, explicit
occurrence timestamp and `session_id="native-" + report_id` as a report correlation key. They
must not invent a user session or use the launch build as the crashed build. Envelope/event
identity fields are ignored and never emitted; install credentials determine app/install writes.

## Native handoff and delivery (later changes)

Native schema 1 persists stable report IDs, bounded source payloads, source windows and build
context. Keep at most 32 reports, each source payload at most 256 KiB, for at most 7 days. Evict
oldest first and isolate corrupt entries. Parsing, OS history collection and bridge polling run
in background/queued work. Provider boot only schedules work. No main-thread launch file scan
or network request, no signal handler, no upload at crash time. Uncaught JVM stack persistence
is the sole crash-time file operation and always chains the previous handler in `finally`.

`TelltaleCrashes.Pending` gives a bounded, non-destructive snapshot of reports from before this
launch. IDs remain stable across reads. Historical MetricKit reports delivered during this launch
are immediately eligible; only reports attributable to this process wait for the next launch.
`TelltaleCrashes.Acknowledge` removes only named IDs, after durable enqueue or a deliberate
privacy/drop decision. Transient failure leaves reports pending. Native/PHP/storage failures must
not throw into the host; unavailable bridges return an empty/error result. Plugin order cannot
affect capture correctness.

On Android API 30+, persist history before advancing durable seen identities. Use timestamp,
pid, reason and process identity, including tied timestamps. Link saved JVM stacks to the matching
ApplicationExitInfo death. API 31+ native tombstones require protobuf decoding. Unknown SIGKILL
does not establish OOM. MetricKit exit aggregates retain their reporting windows and counts.

Crash strings pass through `beforeSend` and Privacy. Opt-out discards the native backlog; TTL and
eviction cover cases where PHP cannot run. No new required environment settings. Empty URL means
no network; offline retry uses existing backoff/outbox transport. Crash batches select a prefix
that fits an encoded-JSON byte cap (default 524,288, below the server's 1,048,576). A valid event
must fit alone. Deliberately drop and account for oversized individual events so they cannot block
the outbox. Preserve ordinary v1 batching where possible.

## Server and MCP (later changes)

Authenticated v2 ingest keeps credential-derived identity, existing size/rate caps, ULID
idempotency and transactions. Also deduplicate report IDs per app/install if an event ID changes.
Crash projection must skip ordinary session, context and activity updates. Store typed details
alongside raw events, group by server-derived fingerprint per app, and retain first/last times,
builds and distinct installs. Delete/prune removes crash samples associated with removed installs.

The tools are `crash_groups(app_id, from?, to?, limit?)`,
`crash_detail(app_id, event_id)` and `crash_free_users(app_id, from?, to?)`. Keep server-wide
reader access with existing read admission and requested-app query scoping inside `handle`.
No team ownership is added. Foreign-app report IDs reveal no crash data.

UTC windows are half-open `[from,to)`. Default to the past min(30, configured raw retention) days;
allow at most min(366, configured raw retention) days. Require start < end and reject starts before
the retention horizon with an explicit retention-limit error. Use retained raw ordinary and crash
events for both populations; pruned intervals cannot claim complete metrics.

Return `active_users`, `affected_users`, `crash_free_users`, `crash_free_percentage` (null for an
empty population), window bounds, `identity_basis='install'`, timestamp attribution and coverage
caveats. The denominator is the union of ordinary-active installs and reporting installs in the
window. Fatal reports affect an install once; nonfatal hangs do not. Multiple sources and aggregate
counts cannot multiply affected installs. Disclose window-end attribution, delayed delivery and
partial OS coverage: this is an observed install proxy, not a complete population crash rate.
Other analytics retain v1 semantics. Device delivery and store privacy disclosures need later
native/device verification; contract fixtures do not establish MetricKit delivery.
