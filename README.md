# Game Maker Designer

A platform for **designing, authoring, balancing and playtesting Living Card Games (LCGs)**.

The core idea: *the game itself is data*. A game system — its zones, phases, resources,
card types, keywords, deckbuilding rules and win conditions — is described in JSON. Cards
are authored against that system. A single deterministic rules engine reads the JSON and
runs real matches, in the browser or headless at scale for balance simulation.

That means one platform can host many different games, and one game can evolve safely
because every change is a diffable, versioned JSON document.

---

## What this repository is (right now)

This repository currently contains the **design and specification set** — no application
code yet. It is the blueprint that the implementation and the UI work will be built from.

Everything here is intended to be executable-in-spirit: the JSON Schemas are real and
validate the worked example, and the example game is complete enough to build a UI against.

---

## Reading order

| # | Document | What it answers |
|---|---|---|
| 01 | [Vision & scope](docs/01-vision-and-scope.md) | What we're building, who for, what's explicitly out of scope |
| 02 | [Architecture](docs/02-architecture.md) | Laravel + Vue + Postgres, the layer cake, where rules actually run |
| 03 | [Data model](docs/03-data-model.md) | Database schema, versioning, the "JSON is truth, columns are indexes" pattern |
| 04 | [Game system spec](docs/04-game-system-spec.md) | The JSON format that defines an entire game |
| 05 | [Card, set & deck spec](docs/05-card-set-deck-spec.md) | How cards, sets and decks are authored |
| 06 | [Effect DSL](docs/06-effect-dsl.md) | How card abilities are expressed as data (the hard part) |
| 07 | [Rules engine](docs/07-rules-engine.md) | State machine, event timing, the modifier layer system, determinism |
| 08 | [Playtest runtime](docs/08-playtest-runtime.md) | Playing a game through the site: sessions, transport, undo, replays |
| 09 | [Automation & balance](docs/09-automation-and-balance.md) | Bots, batch simulation, the metrics that tell you a card is broken |
| 10 | [API spec](docs/10-api-spec.md) | REST + WebSocket surface |
| 11 | [Frontend architecture](docs/11-frontend-architecture.md) | Vue 3 app structure, stores, routing |
| 12 | [UI design brief](docs/12-ui-design-brief.md) | **Screen-by-screen brief — the input for Claude Design** |
| 13 | [Validation & testing](docs/13-validation-and-testing.md) | Schema linting, golden replays, fuzzing |
| 14 | [Roadmap](docs/14-roadmap.md) | Milestones, in build order, with exit criteria |
| 15 | [Glossary](docs/15-glossary.md) | LCG terms and platform terms |
| 16 | [Cooperative & adversary games](docs/16-cooperative-and-adversary-games.md) | 1–4 players vs. an automated villain and its encounter deck |
| 17 | [Repository layout](docs/17-repository-layout.md) | Where the code lives, what may depend on what, every command |

Architecture decisions with their trade-offs are recorded in [`docs/adr/`](docs/adr/).

## Repository layout

```
packages/kernel/          The rules engine — framework-free PHP, no I/O, no clock
packages/harness/         Drivers around it: fixtures, bots, headless matches, replays, fuzz
apps/api/                 Laravel 12 — persistence, HTTP, compilation, match lifecycle
apps/web/                 Vue 3 + Vite SPA
docs/                     Specifications and plans
  adr/                    Architecture decision records
schemas/                  JSON Schema (draft 2020-12) — the machine-readable contracts
examples/emberfall/       Worked example 1 — a competitive two-player duel
examples/wardens-hollow/  Worked example 2 — 1-4 player co-op vs. an automated adversary
scripts/                  validate-examples.mjs — schema + cross-document integrity checks
design/                   High-fidelity screen designs + handoff (six screens, dark theme)
```

What may depend on what, and every command worth knowing, is
[doc 17](docs/17-repository-layout.md).

## The worked examples

Two complete games, defined entirely in this platform's data formats, covering the two
shapes an LCG takes:

| Example | Shape | Proves |
|---|---|---|
| [`emberfall/`](examples/emberfall/) | Competitive 2-player duel | Resources, combat, keywords, attachments, modifier layers |
| [`wardens-hollow/`](examples/wardens-hollow/) | **1–4 player co-op vs. an automated adversary** | Encounter decks, scripted villains, double-sided cards, per-player scaling, shared defeat |

The second is the Marvel Champions / Arkham Horror / LOTR LCG shape. It is a first-class
supported configuration, not an extension — see [doc 16](docs/16-cooperative-and-adversary-games.md).

They exist so that:

* every spec claim is backed by a concrete file you can look at,
* the UI can be designed and built against real, representative data,
* the engine has conformance targets covering both shapes.

Validate every example against the schemas — plus the cross-document integrity checks a
JSON Schema can't express (unknown keywords, out-of-vocabulary traits, deck legality):

```bash
npm install
npm run validate
```

This runs in CI on every push. The example game is a fixture, not decoration: if it stops
validating, either the schemas or the example is wrong.

## Design

[`design/`](design/) holds six high-fidelity screens — card editor, play table, system
editor, card browser, deck builder, simulation report — drawn against the Emberfall data,
with a full token set and per-screen specs in [`design/HANDOFF.md`](design/HANDOFF.md).
They cover the competitive duel shape; the cooperative play table is not yet designed.

## Running it

```bash
pg_ctlcluster 16 main start                       # Postgres 16, no Docker Compose yet

composer install && npm install
cd apps/api && composer install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed
php artisan games:import ../../examples/emberfall
php artisan serve --port=8811                     # and, in another shell:
npm run web                                       # http://127.0.0.1:5273
```

Play a match headlessly, with no database and no browser:

```bash
php packages/harness/bin/gmd play emberfall --seed 1 --log
php packages/harness/bin/gmd fuzz emberfall --matches=200
php packages/harness/bin/gmd replay examples/emberfall/replays/round-one-opening.json
```

## What is built

| Milestone | State |
|---|---|
| M2 · The kernel | **Done.** 10,000 random-bot matches with zero invariant violations; the golden replay reproduces bit-identically |
| M0 · Foundations | **Done**, except auth and a Docker Compose environment |
| M1 · Authoring | Card browser and card editor; the system editor is not built |
| M3 · Play in the browser | A solo match against the random bot plays end to end; no animation layer, no replay scrubber |
| M3.5 · Co-op | The kernel is co-op-shaped (side ids, not seat integers), but `reveal_encounter` and `run_activation` are not implemented — Warden's Hollow lints two errors and cannot be played yet |
| M4–M6 | Not started |

## Next steps

1. The remaining co-op ops, so Warden's Hollow plays (M3.5).
2. The system editor, so a game can be defined without touching a file (M1).
3. Authentication and workspace scoping, which every route currently goes without (M5).
