# Telltale v1 Platform Data Reference

This reference describes the data emitted by `artisan-build/telltale-client` v1. Context is captured
once per estimated session rather than copied into every event. Every captured event has a client ULID,
name, type, timestamp, session UUID, bounded properties, and the client package version.

## Captured On Both Platforms

- Developer events sent with `Telltale::event()`, including developer-defined bounded properties.
- Optional opaque user identity sent with `Telltale::identify()`; use a one-way hash rather than direct
  personal data.
- Screen views exposed by the platform hooks listed below.
- Estimated sessions. A first event starts a session and more than 30 minutes of inactivity starts a new
  one by default; session end can be inferred.
- A random Telltale install UUID generated on device. It resets on reinstall and is not IDFV,
  Android ID, or another hardware identifier.
- App/client version, platform context, client-reported outbox drop totals, and bounded PHP error details.
- Developer-defined properties may contain personal data. A `beforeSend` callback can scrub or drop an
  event, and `optOut()` stops capture and clears the outbox.

The server uses the request IP for rate limiting but does not store it as analytics data. Obvious email
and phone patterns are scrubbed from automatically captured strings; property keys and values are
length-capped.

## Mobile v4

| Area | v1 capture |
|---|---|
| Screens | NativePHP Mobile 4.1+ `ScreenMounted`, `ScreenResumed`, and `ScreenUnmounted` signals, with component and URI when exposed |
| Version | `nativephp.version`, `version_code`, and the existing `UpdateInstalled` event |
| Device and OS | Device model, operating system, OS version, and language from `Device::getInfo()` |
| Network context | Connected state, type, expensive state, and constrained state when exposed by `Network::status()` |
| PHP errors | Laravel-reported HTTP/web-view exceptions and failed queued jobs |
| Delivery | SQLite outbox drained by queued work while the app is able to run; no mobile background upload |

## Desktop v2

| Area | v1 capture |
|---|---|
| Screens | Window focus/blur and route-change observations |
| Session hints | Existing `UserDidBecomeActive`, `UserDidResignActive`, window focus/blur, and the inactivity rule |
| Version | `App::version()` and existing AutoUpdater available/downloaded events |
| Device and OS | Process platform and architecture, app locale, and system timezone |
| PHP errors | Exceptions reported through Laravel's exception handler |
| Delivery | SQLite outbox drained by the queue or scheduler |

## PHP Error Payload

A PHP error contains the exception class, a scrubbed and capped message, normalized file and line, a
capped stack trace, and an optional fingerprint used for grouping. This is PHP error reporting, not
native crash reporting.

## Backend Trace Header

The opt-out HTTP middleware adds this header to the NativePHP app's own outbound Laravel HTTP requests;
plain Guzzle clients can install the same middleware explicitly:

```text
X-Telltale-Session: v1;session=<raw-session-uuid>;install=<sha256-install-hash>
```

The install portion is `sha256("telltale-install-v1:" + random-install-uuid)`. The raw install id,
public ingest value, and per-install bearer token are never included. Requests to Telltale's own
registration and ingest endpoints do not receive the header, and opting out removes it.

## Not Captured In v1

- Native crashes, OOM failures, ANRs, or Electron Crashpad reports.
- Exceptions rendered inside SuperNative screens; they do not reach Laravel's exception handler.
- AsyncTask failures. NativePHP 4.5.2 and 4.6.0 consume `AsyncTaskFailed` before global dispatch. A
  globally dispatched failure event or supported hook is a documented NativePHP ask, not a Telltale
  framework workaround.
- New foreground, background, launch, quit, connectivity, or other lifecycle signals. Telltale only
  observes existing core events listed above.
- Mobile background upload or background flush.
- A separate OTA bundle-version API.
- Native plugin privacy-manifest automation or guidance.
- Nightwatch sensor telemetry, native crash plugins, lifecycle plugins, crash-free metrics, heatmaps,
  session replay, experiments, feature flags, or a chart dashboard.

Native crash hooks, lifecycle additions, background flush, AsyncTask hooks, an OTA-version API,
Nightwatch reuse, crash-free metrics, and privacy-plugin guidance are v2 or NativePHP asks. Telltale v1
does not implement them.
