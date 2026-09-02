# 17 — Repository layout

Where the code lives, and which parts are allowed to know about which. Written after the
build, because the earlier documents describe a single Laravel application and the tree is a
monorepo — this is the file that reconciles them.

---

## The tree

```
packages/kernel/      gmd/kernel   — the rules engine. Framework-free PHP, no I/O, no clock.
packages/harness/     gmd/harness  — drivers around it: fixtures, bots, matches, replays, fuzz.
apps/api/                          — Laravel 12. Persistence, HTTP, compilation, match lifecycle.
apps/web/             @gmd/web     — Vue 3 + Vite SPA.
schemas/                           — JSON Schema 2020-12. The contract all four share.
examples/                          — two complete worked games (emberfall, wardens-hollow).
docs/  design/  scripts/           — specification, screen designs, the schema validator.
```

## What may depend on what

```
apps/web  ──HTTP──▶  apps/api  ──▶  packages/harness  ──▶  packages/kernel  ──▶  (nothing)
                          └──────────────────────────────────▶
```

The arrows are enforced, not merely intended:

* **The kernel depends on nothing** — no `Illuminate\*`, no framework, no filesystem, no
  clock, no global state. An architecture test fails the build if that stops being true, and
  a second test proves the scanner can still see. This is what lets the identical engine
  serve an HTTP request, a queue worker, the CLI and the test suite.
* **Nothing inside `packages/kernel/src` reads a file.** `bin/gmd` loads the system, set and
  deck JSON and hands parsed arrays in; the API passes the same arrays out of jsonb columns.
  That boundary is the whole reason one engine covers every driver.
* **The harness depends only on the kernel.** It is a plain library — bots, a match runner,
  a replay runner, a fuzzer — so the API requires it for exactly one thing: driving a bot's
  seat with the same agent the fuzzer uses.
* **The SPA depends on neither.** It talks to `/api/v1` and computes no rules (ADR-0002).

Both PHP packages are wired in through composer *path* repositories, so they are real
dependency boundaries rather than folders that happen to sit next to each other.

## Path translation

Doc 11 was written for a single Laravel application and describes the frontend as living in
`resources/js/`. It does not; every such path maps as:

| Doc 11 says | It is |
|---|---|
| `resources/js/main.ts` | `apps/web/src/main.ts` |
| `resources/js/stores/…` | `apps/web/src/stores/…` |
| `resources/js/components/…` | `apps/web/src/components/…` |
| `resources/js/design/tokens.css` | `apps/web/src/design/tokens.css` |
| `resources/js/api/types.gen.ts` | `apps/web/src/api/documents.gen.d.ts` |

Two consequences of the split that the single-application layout would not have had:

* **The SPA is served by Vite, not by Laravel.** In development the Vite dev server proxies
  `/api` to `php artisan serve` (`apps/web/vite.config.ts`), which keeps the browser on one
  origin. When authentication lands, Sanctum will need stateful domains and CORS configured
  rather than getting a same-origin session for free.
* **There are two `package.json` files and two `composer.json` files.** The root of each is
  the workspace; running a suite from the wrong directory is the most common way to be
  confused about why something passes.

## Commands

Every one of these is what CI runs, so a green local run means a green pipeline.

| What | Where | Command |
|---|---|---|
| Schema-validate the examples | root | `npm run validate` |
| Kernel + harness tests | root | `composer test` |
| PHP style | root | `composer cs` (`composer cs:fix` to apply) |
| Verify a golden replay | root | `php packages/harness/bin/gmd replay <file>` |
| Bless a replay's hashes | root | `php packages/harness/bin/gmd replay <file> --bless` |
| Play a headless match | root | `php packages/harness/bin/gmd play emberfall --seed 1` |
| Fuzz | root | `php packages/harness/bin/gmd fuzz emberfall --matches=200` |
| Compile / lint a game | root | `php packages/harness/bin/gmd compile emberfall`, `… lint emberfall` |
| API tests | `apps/api` | `php artisan test` |
| Import a game | `apps/api` | `php artisan games:import ../../examples/emberfall` |
| Rebuild index columns | `apps/api` | `php artisan cards:reproject`, `php artisan decks:reproject` |
| Web build + typecheck | root | `npm run web:build` |
| Web unit tests | root | `npm run web:test` |
| Web lint | root | `npm run lint` |
| Regenerate types from schemas | root | `npm run types` (`types:check` asserts they are current) |
| End-to-end | root | `npm run e2e` (needs both servers running) |

Services run natively; there is no Docker Compose file yet:

```bash
pg_ctlcluster 16 main start && service redis-server start
```

## Generated files that are committed

`apps/web/src/api/documents.gen.d.ts` is generated from `schemas/*.json` and committed, and
CI regenerates it to check it is current. A schema change the frontend has not caught up with
is then a build failure rather than a runtime cast.

It is a `.d.ts` rather than a `.ts` on purpose. Not everything a JSON Schema can say is
expressible in TypeScript — `vocabularies` in the game system schema has named optional
properties alongside an `additionalProperties` index signature, and TypeScript rejects that
because an optional property is `T | undefined` while the index type is not. A declaration
file is covered by `skipLibCheck`, so the generated text is exempt while every *use* of those
types is still checked. Rewriting a correct schema to suit a code generator would be the
wrong way round.

## Known gaps

Recorded here rather than left to be discovered:

* **PHPStan is not installed.** Doc 13 asks for level 8 and it belongs in the `lint` job, but
  it is distributed as a phar from GitHub releases, which this project's build environment
  cannot reach. `vue-tsc --noEmit`, ESLint and the Pest architecture tests cover part of what
  it would.
* **There is no Docker Compose environment**, and no Redis-backed queue: the cache store is
  configurable and match state falls back to rebuilding from the action log, which is the
  behaviour that made Redis optional in the first place.
* **Laravel Reverb is not installed.** Online multiplayer is M5; solo and hotseat are REST.
* **No authentication.** Every route is currently unauthenticated, which is fine for a local
  single-user tool and is the first thing M5 has to fix. The workspace scoping the data model
  carries is in place and unused.
