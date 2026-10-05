# Workflow - Telltale

Project profile for Telltale: what to run, what CI requires, and the rules the stack depends on.
Read this before opening a pull request. Maintainer-private notes live outside this repo.

Telltale is a pre-launch, self-hosted product analytics and error-reporting system for NativePHP
applications. The monorepo contains its server, versioned contracts, and device-side client package.

## Phase And Mode

- phase: `pre-launch`
- merge policy: `merge on green CI (all required checks)`
- merge method: `gh pr merge --squash`

## Hard Gate

- command: `composer ready`
- order: IDE helper generation, Rector, root Pint, package Pint, root PHPStan/Larastan, package
  PHPStan, root Pest, both package Pest suites, root Composer audit, and both package audits.
- package suites: `composer packages:lint:check`, `composer packages:stan`,
  `composer packages:test`, and `composer packages:audit`.
- monorepo: the Laravel server is at root; path repositories are
  `packages/telltale-contracts` and `packages/telltale-client`.

Run the full hard gate once on the committed candidate with a clean tree. While work is in
progress, run only the focused tests covering the changed files, then static analysis and lint once
before finalizing.

## CI

- status: defined; verified on the pull request.
- exact required contexts: `server (8.5)`, `client (8.4)`, `client (8.5)`, and `quality`.
- `.github/workflows/tests.yml`: the `server (8.5)` job runs root and contracts checks on PHP
  8.5 against PostgreSQL 16; standalone client jobs run client static analysis, Pest, and audit on
  PHP 8.4 and 8.5 without PostgreSQL.
- `.github/workflows/lint.yml`: root and package Pint checks on PHP 8.5.
- both workflows target pushes and pull requests to `main`.

Do not rename the jobs or matrix entries without updating branch protection; required contexts are
literal strings.

## Dependency Install

- root: `composer install --no-interaction --prefer-dist`
- contracts: `composer -d packages/telltale-contracts install --no-interaction --prefer-dist`
- client: `composer -d packages/telltale-client install --no-interaction --prefer-dist`
- local post-install: copy `.env.example` to `.env`, run `php artisan key:generate`, and migrate.
- PostgreSQL prerequisites: local databases `telltale` and `telltale_app_test`; the server CI job
  creates `telltale_app_test` through its PostgreSQL 16 service. Client CI jobs do not use PostgreSQL.
- tests use the real `telltale_app_test` PostgreSQL database configured in `phpunit.xml`, never
  SQLite.
- no part of the monorepo requires a commercial licence to install; only the free
  `livewire/flux` package is required, and Flux Pro (`php artisan flux:pro`) is optional.

## Ship Details

- branch naming: `feat/<slug>` (`fix/<slug>` for fixes, `chore/<slug>` for maintenance)
- PR target: `artisan-build/telltale`, branch `main`
- release trigger: tags matching `v*` run `.github/workflows/release.yml` and `kibble:split` both
  packages in lockstep.
- release prerequisites: seed the read-only `artisan-build/telltale-contracts` and
  `artisan-build/telltale-client` mirrors and grant a fine-grained `SPLIT_REPO_TOKEN` with Contents:
  write before any tag is pushed. Do not create mirrors or tags as part of ordinary feature work.

## Built For Cloud App

- app role: customer-owned server for NativePHP product analytics and error reporting.
- manifest: `config/built-for-cloud.php`.
- deployment: no environment or resource is provisioned by the scaffold. Laravel Cloud resource
  settings are managed by Cloud and must not be shadowed with hand-written variables.

## Stack Notes

- Laravel 13, Livewire 4, Flux 2, and a Composer PHP floor of `^8.4`.
- Server and contracts CI runs on PHP 8.5. Client CI additionally covers device PHP 8.4.
- Nodeless by design: no Node, npm, Vite, or frontend build step.
- Tailwind is served from `public/build/assets/app.css`; regenerate it only with
  `php artisan tailwind:optimize` and commit the output.
- `composer ready` regenerates committed IDE helper files. `.phpstorm.meta.php` remains ignored.
- Keep `phpstan-baseline.neon` empty.
- Client real-device verification is blocked until the later Dreiland integration has Xcode.
