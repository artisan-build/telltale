# Telltale

Telltale is a pre-launch, self-hosted product analytics and error-reporting system for NativePHP
desktop and mobile apps. Its server runs in the developer's Laravel Cloud account, while a small
client package captures device-side events for MCP-first analysis.

> **Status: pre-launch MVP.** The contracts, device client, ingest and storage server, MCP tools, and
> standard Built for Cloud package UI are implemented. Optional Mobile native crash reporting is
> prepared for the next lockstep release; publication and physical-device verification remain gated.

## Repository Layout

- `/` - the Telltale Laravel server application.
- `packages/telltale-contracts` - versioned wire contracts shared by client and server.
- `packages/telltale-client` - the package installed in NativePHP applications.
- `packages/telltale-crashes` - optional Mobile v4 native crash collection and queued import.

The monorepo uses Composer path repositories for local development, while tagged releases are split
into read-only mirrors and published to Packagist for installation in other applications.

## Product Boundaries

Telltale v1 will support events, screen views, estimated sessions, release adoption, product
analytics, and the PHP error paths exposed by NativePHP. Native crashes, OOM failures, ANRs,
background mobile uploads, session replay, feature flags, and a chart dashboard are not part of v1.
The optional crash plugin extends Mobile capture without changing desktop capture or adding OS
background-upload execution. See the [mobile crash guide](docs/mobile-crashes.md) for its release gates.

## Client Installation

The client is published on Packagist at v1.1.0 and supports Laravel 11, 12, and 13 on PHP 8.4 or newer.
It requires the `ext-json`, `ext-sqlite3`, and `ext-zlib` PHP extensions in the NativePHP build environment.

Install the client in a compatible NativePHP application:

```bash
composer require artisan-build/telltale-client
```

## Documentation

- [Setup](docs/setup.md) - generated from the same source as the package setup page and Scalpels handoff.
- [Privacy declarations](docs/privacy.md) - Apple manifest, App Store label, and Google Play answers.
- [Platform data reference](docs/data-reference.md) - exact capture, trace-header, and v1 limits.
- [Mobile crashes](docs/mobile-crashes.md) - planned split-package installation, privacy hook and device checks.
- [Scalpels post-provision handoff](docs/scalpels-handoff.md) - deterministic integration copy with no
  client-wiring automation.

Regenerate the setup artifacts with `php artisan telltale:generate-docs --local` and check drift with
`php artisan telltale:generate-docs --local --check`.

## Local Development

Requires PHP 8.4+, Composer, and PostgreSQL. Every dependency is free: the root application uses
the free `livewire/flux` package, so `composer install` works with no licence anywhere in the
monorepo. Flux Pro is optional — run `php artisan flux:pro` only if you already hold a licence and
want its extra components.

Create local databases named `telltale` and `telltale_app_test`, then run:

```bash
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
php artisan migrate
composer -d packages/telltale-contracts install --no-interaction --prefer-dist
composer -d packages/telltale-client install --no-interaction --prefer-dist
composer -d packages/telltale-crashes install --no-interaction --prefer-dist
composer dev
```

Telltale is nodeless by design. Do not add Node, npm, Vite, or a frontend build step. Static assets
are committed under `public/build`.

## Quality Gate

`composer ready` is the hard gate. It regenerates IDE helpers, runs Rector and root/package Pint,
performs root and package static analysis, runs all four Pest suites, and audits every Composer lock
file.

Focused package commands are also available:

```bash
composer packages:lint:check
composer packages:stan
composer packages:test
composer packages:audit
```

CI defines the required `server (8.5)`, `client (8.4)`, `client (8.5)`, and `quality` check contexts.
The server and contracts run on PHP 8.5 against PostgreSQL 16. The device-side client runs its
standalone package checks on PHP 8.4 and 8.5 without PostgreSQL. Composer's PHP floor remains `^8.4`.
The added `crashes (8.4)` and `crashes (8.5)` lanes cover the PHP plugin. `crashes native (ios)` and
`crashes native (android)` compile actual native sources with narrow bridge stubs and run harnesses;
they do not build full NativePHP apps or replace device dogfood. Existing lowest-dependency lanes
also exercise the crash package on Laravel 11, 12 and 13.

## Built For Cloud

The server uses `artisan-build/built-for-cloud` for its authentication and credential foundation.
Its Scalpels-facing manifest is maintained in `config/built-for-cloud.php`. No Laravel Cloud
environment or resource has been provisioned by this scaffold.

## Releases

Tags matching `v*` run the package split workflow. Telltale v1.0.0 was split to the read-only
`artisan-build/telltale-contracts` and `artisan-build/telltale-client` mirrors and published on
Packagist. The next release also splits `artisan-build/telltale-crashes`, whose mirror and split-token
access must be approved before tagging because Kibble discovers all three package directories,
removes local path repositories from split artifacts, and tags them in lockstep. No crash release is
claimed here.

## Repository

<https://github.com/artisan-build/telltale>
