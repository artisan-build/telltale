# Workflow - Telltale

Project profile for the `multi-agent-build` skill and every agent working on Telltale. The
coordinator reads this first.

Telltale is a pre-launch, self-hosted product analytics and error-reporting system for NativePHP
applications. The monorepo contains its server, versioned contracts, and device-side client package.
Product behavior lands only through the PR sequence in the authoritative plan.

## Phase And Mode

- phase: `pre-launch`
- default mode: `A-autonomous`
- merge policy: `merge on green CI; no human PR code review (brain, under Ed's MVP-speed directive, 2026-10-03)`
- security review: PR2 (ingest/register) and PR6 (MCP) require the full independent quality reviewer
  + acceptance judge pair before merge.
- merge method: `gh pr merge --squash`

## Role Resolution

Resolve implementer, quality reviewer, and acceptance judge roles at runtime from
`~/Herd/brain/agents.json` by following `~/Herd/brain/playbooks/resolve-agent-role.md`. This profile
does not pin a harness map.

## Hard Gate

- command: `composer ready`
- order: IDE helper generation, Rector, root Pint, package Pint, root PHPStan/Larastan, package
  PHPStan, root Pest, both package Pest suites, root Composer audit, and both package audits.
- package suites: `composer packages:lint:check`, `composer packages:stan`,
  `composer packages:test`, and `composer packages:audit`.
- monorepo: the Laravel server is at root; path repositories are
  `packages/telltale-contracts` and `packages/telltale-client`.

The coordinator runs the full hard gate once on the committed candidate with a clean tree.
Implementers run only focused tests covering their changes, then static analysis and lint once before
handoff.

## CI

- status: defined; the coordinator verifies it on the pull request.
- exact required contexts: `ci (8.4)`, `ci (8.5)`, and `quality`.
- `.github/workflows/tests.yml`: root Rector, root PHPStan/Larastan, root Pest, both package
  static-analysis and Pest suites, and Composer audits on PHP 8.4 and 8.5 against PostgreSQL 16.
- `.github/workflows/lint.yml`: root and package Pint checks on PHP 8.5.
- both workflows target pushes and pull requests to `main`.

Do not rename the jobs or matrix entries without updating branch protection; required contexts are
literal strings.

## Dependency Install

- root: `composer install --no-interaction --prefer-dist`
- contracts: `composer -d packages/telltale-contracts install --no-interaction --prefer-dist`
- client: `composer -d packages/telltale-client install --no-interaction --prefer-dist`
- local post-install: copy `.env.example` to `.env`, run `php artisan key:generate`, and migrate.
- PostgreSQL prerequisites: local databases `telltale` and `telltale_app_test`; CI creates
  `telltale_app_test` through its PostgreSQL 16 service.
- tests use the real `telltale_app_test` PostgreSQL database configured in `phpunit.xml`, never
  SQLite.

## Ship Details

- branch naming: `feat/<slug>` (`fix/<slug>` for fixes, `chore/<slug>` for maintenance)
- PR target: `artisan-build/telltale`, branch `main`
- release trigger: tags matching `v*` run `.github/workflows/release.yml` and `kibble:split` both
  packages in lockstep.
- release prerequisites: seed the read-only `artisan-build/telltale-contracts` and
  `artisan-build/telltale-client` mirrors and grant a fine-grained `SPLIT_REPO_TOKEN` with Contents:
  write before any tag is pushed. Do not create mirrors or tags as part of ordinary feature work.

## Plan And Coordination

- plan: `/Users/edgrosvenor/Herd/brain/projects/telltale/PRD.md` (section 2 is locked)
- build brief: `/Users/edgrosvenor/Herd/brain/projects/telltale/brief-mvp-build.md`
- Solo project: Telltale, id 74
- run log: `Telltale MVP build - run log`

## Built For Cloud App

- app role: customer-owned server for NativePHP product analytics and error reporting.
- manifest: `config/built-for-cloud.php`.
- deployment: no environment or resource is provisioned by the scaffold. Laravel Cloud resource
  settings are managed by Cloud and must not be shadowed with hand-written variables.

## Stack Notes

- Laravel 13, Livewire 4, Flux 2, and PHP 8.4 or newer.
- Nodeless by design: no Node, npm, Vite, or frontend build step.
- Tailwind is served from `public/build/assets/app.css`; regenerate it only with
  `php artisan tailwind:optimize` and commit the output.
- `composer ready` regenerates committed IDE helper files. `.phpstorm.meta.php` remains ignored.
- Keep `phpstan-baseline.neon` empty.
- Client real-device verification is blocked until the later Dreiland integration has Xcode.
