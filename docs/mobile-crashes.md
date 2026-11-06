# Mobile crash reporting

The optional `artisan-build/telltale-crashes` plugin imports native reports into the Telltale
client's SQLite outbox. Upgrade the server first: crash events use envelope v2. Ordinary-only
batches keep using v1, and existing clients remain supported.

## Install after release approval

This recipe targets the planned 1.2.0 split releases. It is not an installation claim for an
unpublished package. The crash mirror, split-token access, CI and device checks must be approved
before these commands are usable. A VCS repository for the monorepo root cannot install its
subdirectory packages.

In a NativePHP Mobile v4 app on PHP 8.4+, add the three split mirrors as Composer repositories:

```bash
composer config repositories.telltale-contracts vcs https://github.com/artisan-build/telltale-contracts
composer config repositories.telltale-client vcs https://github.com/artisan-build/telltale-client
composer config repositories.telltale-crashes vcs https://github.com/artisan-build/telltale-crashes
composer require artisan-build/telltale-contracts:1.2.0 artisan-build/telltale-client:1.2.0 artisan-build/telltale-crashes:1.2.0
php artisan native:plugin:register artisan-build/telltale-crashes
php artisan native:plugin:list
php artisan native:run ios
php artisan native:run android
```

If the app has no `app/Providers/NativeServiceProvider.php`, run
`php artisan vendor:publish --tag=nativephp-plugins-provider` before registration. The register
command adds `ArtisanBuild\TelltaleCrashes\TelltaleCrashesServiceProvider::class` to that
provider's `plugins()` list. Keep the app's existing entries. Laravel discovers the PHP provider
through Composer; the explicit plugin list is what permits native compilation. Rebuild both
platforms after registering; a PHP-only update cannot add native code.

Laravel 11, 12 and 13 are supported. PHP needs JSON, SQLite3, zlib and DOM; DOM is used by the
iOS build hook. The plugin targets iOS 18+ and Android API 30+ (native tombstones require API 31+).
It reuses the client's existing server and ingest configuration and database queue, with no new
required environment values. An empty server URL sends nothing. A sync queue is refused.

## Capture and delivery

iOS subscribes to MetricKit diagnostics and metrics. Crash and hang reports keep their attributed
stack order and available binary UUIDs, addresses and offsets. MetricKit hangs are nonfatal.
Positive memory-limit and watchdog exit counts retain their foreground/background category and
reporting window. A window-end timestamp is not an invented time for an individual death.
Historical app versions and builds are retained when available; the relaunch build is never
substituted for an unknown crashed build.

Android reads `ApplicationExitInfo` for crashes, ANR deaths and low-memory exits. Unknown SIGKILL
is not classified as OOM. API 31 native tombstones are decoded as protobuf and export only the
selected thread's allowlisted frame and binary/build fields. Registers, memory, logs, file
descriptors and opaque trace bytes do not enter the wire payload. JVM exceptions are saved locally;
the installed handler always calls the previous handler, including after a persistence failure.

Native collection and storage run off the main thread. The bridge reads a cached snapshot;
provider startup only schedules a queued import. Current-process captures wait for a later launch.
Historical reports newly delivered by the OS are eligible in the current launch, and a readiness
event covers reports arriving after the first empty poll. No upload runs at crash time. Delivery
needs a running app and working queue; this does not add OS background-upload execution.

Each native store retains at most 32 reports for seven days, with a 256 KiB source-payload bound.
Oldest reports are evicted and corrupt entries are isolated. The importer uses stable report-derived
event IDs and acknowledges only a durable outbox enqueue or a deliberate drop. Transient failures
retain the native report for retry. Invalid reports with usable IDs are dropped and counted. Entries
without a valid receipt ID are counted within the 32-row snapshot bound but cannot be acknowledged;
native corruption handling and expiry own their removal.

The import job verifies asynchronous acknowledgment on a delayed retry, with at most ten attempts.
A persisted scheduling lease prevents acknowledgment/readiness notifications from creating a new
job chain. An empty poll releases that lease so later same-launch collection can schedule work.

The client bounds complete encoded batches to 524,288 bytes by default, below the server's 1 MiB
ceiling. A 413 reduces later batches without acknowledging their unsent rows. One oversized row
is dropped with accounting so later rows can drain. Offline requests keep the normal backoff.

## Privacy and callbacks

`Telltale::optOut()` clears the client outbox, suppresses native capture and discards the native
backlog. `optIn()` resumes collection. A queued import also reconciles persisted consent on startup.
Native sources stay app-private and never travel as opaque attachments. Selected free text passes
through the client's privacy filtering; report IDs, UUIDs, build IDs and canonical frame addresses
are preserved for deduplication and symbolication.

The Mobile database queue runs in a separate PHP runtime. An interactive `beforeSend()` closure
does not survive into that worker. For crash redaction, publish the optional config with
`php artisan vendor:publish --tag=telltale-crashes-config`, then set `before_send` in
`config/telltale-crashes.php` to an invokable class:

```php
return ['before_send' => App\Telemetry\RedactCrash::class];
```

```php
namespace App\Telemetry;

final class RedactCrash
{
    public function __invoke(array $event): ?array
    {
        unset($event['crash']['trace']);

        return $event; // Return null to deliberately drop the report.
    }
}
```

The container resolves the class in the worker. Throwing or invalid callback configuration causes
retry without acknowledgment. A callback cannot replace stable import identity. Use provider-based
registration for any additional worker dependencies, rather than interactive state.

The `post_compile` hook merges `resources/PrivacyInfo.xcprivacy` into the iOS target's
`NativePHP/PrivacyInfo.xcprivacy`, after NativePHP has copied every plugin's project files and
assets. Dictionaries merge by key, data/API entries by category, and purposes/reasons by union.
True linked-data or tracking declarations are never downgraded. The hook preserves host and other
plugin declarations and is idempotent; malformed/conflicting XML fails the build without replacing
the host file. Convert a binary host plist to XML before building. NativePHP's synchronized target
folder includes the merged file as an app resource; inspect the final archive's root
`PrivacyInfo.xcprivacy` and Xcode privacy report before distribution.

Crash and performance diagnostics are disclosed for app functionality, linked to a random Telltale
install identifier, with no tracking. This is not IDFV, Android ID or another hardware identifier.
The native iOS implementation adds no required-reason API category: it does not use UserDefaults,
system uptime or file timestamps. Retain the declarations required by the client runtime and other plugins.
See [privacy declarations](privacy.md) for store-label and Data Safety additions.

Manifest placement follows Apple's
[privacy manifest guidance](https://developer.apple.com/documentation/bundleresources/adding-a-privacy-manifest-to-your-app-or-third-party-sdk)
and [bundle placement rules](https://developer.apple.com/documentation/bundleresources/placing-content-in-a-bundle).

## Verification and release gates

The committed Swift checkpoint passed a Foundation parser/store/runtime harness with 100 assertions
and an iOS SDK typecheck of the actual adapter using narrow NativePHP bridge stubs. Its generated
iOS projection passed a PHP SQLite outbox and HTTP-fake envelope test (48 assertions). These are
harness checks. They do not establish MetricKit delivery on a device; the simulator does not deliver
MetricKit reports.

There is no local JDK. The hosted Android lane compiles actual Kotlin sources against the Android
SDK with bridge stubs and runs a pure-JVM harness, but a green hosted run is still needed before
those checks can count as evidence for the Kotlin implementation. Neither native lane builds a
full NativePHP app or proves physical-device delivery. PHP import and scheduling fixtures remain
separate from device tests; an additional minimal Xcode resource fixture checks that the merged
manifest reaches the built app root, without compiling or launching a full NativePHP consumer.

Before tagging, the maintainer must create the `artisan-build/telltale-crashes` split mirror, grant
the existing split token Contents-write access, and approve the new `crashes (8.4)`, `crashes (8.5)`,
`crashes native (ios)` and `crashes native (android)` checks for protection. Keep all existing contexts
and lowest-dependency lanes. Kibble discovers all three package directories and strips path
repositories from split artifacts.

Physical-device dogfood must cover iOS crash/hang and positive memory/watchdog windows, late
MetricKit delivery during the same relaunch, Android JVM/native crashes, ANR/low-memory exits,
previous-handler chaining, offline retry and opt-out/resume. Final CI, the coordinator's candidate
gate and consumer verification precede tag approval. Consuming-app integration is a later, separate
dispatch. Do not submit to either store before dogfood.

## Deferred symbol uploads

No symbol-upload endpoint is included. A later design should key dSYMs by binary UUID, R8 mappings
by app version/build, and native ELF symbols by build ID. Uploads need authenticated app-scoped
access, retention and build association, including eventual Bifrost build association. Preserve
those native inputs now so later symbolication can use them without changing report identity.
