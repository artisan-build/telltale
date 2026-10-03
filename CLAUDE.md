# Telltale

Telltale is a pre-launch, self-hosted product analytics and error-reporting system for NativePHP
desktop and mobile apps. A Composer client will run on devices and report to a customer-owned
Built for Cloud server. The product is MCP-first and has no analytics dashboard beyond its setup and
management UI.

The authoritative product definition is
`/Users/edgrosvenor/Herd/brain/projects/telltale/PRD.md`; section 2 is locked. Read it before adding
product behavior. The current repository is only the server scaffold, package boundaries, and
quality/release tooling.

## Product Boundaries

- One product serves desktop and mobile; platform is a dimension.
- One server installation serves many apps, each with a public write-only ingest value.
- v1 excludes native crashes, OOM failures, ANRs, background mobile uploads, session replay,
  experiments, feature flags, and a chart dashboard.
- Do not implement behavior assigned to later PRs while working on scaffold or infrastructure.

## Workflow

Read `.solo/workflow.md` for repository policy, gates, CI, package commands, release prerequisites,
and role resolution. The hard gate is `composer ready`.

## Stack

- PHP 8.4+ and Laravel 13.
- PostgreSQL for local and CI tests; do not substitute SQLite for production-facing behavior.
- Root Laravel server plus `packages/telltale-contracts` and `packages/telltale-client` Composer
  packages.
- Nodeless: no Node, npm, Vite, or frontend build step.

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. Regenerate that
bundle only with `php artisan tailwind:optimize`, then commit the output.

## Built For Cloud

`artisan-build/built-for-cloud` provides the server authentication and credential foundation.
Telltale's manifest lives at `config/built-for-cloud.php`. Laravel Cloud injects configuration for
provisioned resources; never add values that shadow Cloud-managed resource variables.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed because the gate regenerates them and
PHPStan scans the model helper. `.phpstorm.meta.php` stays ignored because it contains machine paths.

## Static Analysis

Root PHPStan/Larastan runs at level 6 and includes both packages. Each package also owns an
independent level-8 PHPStan configuration. Keep `phpstan-baseline.neon` empty; fix findings instead
of expanding it.
