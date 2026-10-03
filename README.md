# Telltale

Telltale is a pre-launch, self-hosted product analytics and error-reporting system for NativePHP
desktop and mobile apps. Its server runs in the developer's Laravel Cloud account, while a small
client package will capture device-side events for MCP-first analysis.

> **Status: pre-launch scaffold.** This repository currently contains the Laravel server shell,
> package boundaries, and quality/release tooling. Event capture, registration, ingest, storage,
> aggregation, MCP tools, and product UI have not shipped yet.

## Repository Layout

- `/` - the Telltale Laravel server application.
- `packages/telltale-contracts` - versioned wire contracts shared by client and server.
- `packages/telltale-client` - the package installed in NativePHP applications.

The packages are Composer path repositories during development and are designed to be split into
read-only mirrors for lockstep releases.

## Product Boundaries

Telltale v1 will support events, screen views, estimated sessions, release adoption, product
analytics, and the PHP error paths exposed by NativePHP. Native crashes, OOM failures, ANRs,
background mobile uploads, session replay, feature flags, and a chart dashboard are not part of v1.

## Local Development

Requires PHP 8.4+, Composer, and PostgreSQL. Create local databases named `telltale` and
`telltale_app_test`, then run:

```bash
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
php artisan migrate
composer -d packages/telltale-contracts install --no-interaction --prefer-dist
composer -d packages/telltale-client install --no-interaction --prefer-dist
composer dev
```

Telltale is nodeless by design. Do not add Node, npm, Vite, or a frontend build step. Static assets
are committed under `public/build`.

## Quality Gate

`composer ready` is the hard gate. It regenerates IDE helpers, runs Rector and root/package Pint,
performs root and package static analysis, runs all three Pest suites, and audits every Composer lock
file.

Focused package commands are also available:

```bash
composer packages:lint:check
composer packages:stan
composer packages:test
composer packages:audit
```

CI defines the required `ci (8.4)`, `ci (8.5)`, and `quality` check contexts. The test matrix uses
PostgreSQL 16, not SQLite.

## Built For Cloud

The server uses `artisan-build/built-for-cloud` for its authentication and credential foundation.
Its Scalpels-facing manifest is maintained in `config/built-for-cloud.php`. No Laravel Cloud
environment or resource has been provisioned by this scaffold.

## Releases

Tags matching `v*` run the package split workflow. Before the first tag, the read-only
`artisan-build/telltale-contracts` and `artisan-build/telltale-client` mirror repositories must be
seeded and the repository must receive a fine-grained `SPLIT_REPO_TOKEN` with Contents: write.
Neither mirrors nor tags are created by the scaffold.

## Repository

<https://github.com/artisan-build/telltale>
