# Telltale Client

Device-side analytics and error reporting for NativePHP Mobile and Desktop applications.

The auto-discovered provider captures supported platform signals into a local SQLite outbox. Capture
never sends an HTTP request inline. Mobile's database queue worker or Desktop's queue/scheduler drains
the outbox later.

## Correlation Header

Laravel HTTP requests receive `X-Telltale-Session` automatically. Plain Guzzle clients can push the
container-resolved `TraceHeaderMiddleware` onto their handler stack. Its stable v1 value is:

```text
v1;session=<raw-session-uuid>;install=<sha256-install-hash>
```

The raw install id, ingest value, and install bearer token are never included. Registration and ingest
requests are excluded, and opting out removes the header.

## v1 Error Limit

Laravel-reported exceptions, failed queue jobs, and Mobile AsyncTask failures are captured. Exceptions
inside SuperNative screens are rendered by NativePHP without reaching Laravel's exception handler, so
they cannot be captured in v1 without a NativePHP framework hook.
