# 03 — Data Model

## The governing pattern: JSON is truth, columns are indexes

Every domain object that describes *the game* is stored as a `jsonb` document. Alongside
it we store a handful of generated/denormalised columns whose only job is to make queries
fast (filter cards by cost, search by name, list by type).

Rules:

1. **Writes go to the document.** Index columns are populated from the document on save,
   inside the same transaction, by a single `Projector` class per table.
2. **Nothing reads an index column to make a game decision.** The kernel only ever sees
   documents. If an index column were wrong, matches would still be correct.
3. **Index columns are droppable.** You can `DROP` and rebuild every one of them from the
   documents. This is enforced by a `cards:reproject` artisan command that must be
   idempotent, and a test that asserts it.

This buys the flexibility of schemaless authoring (each game has different card
attributes!) without giving up query performance or referential integrity where it
matters.

## Entity overview

```
users ──< memberships >── workspaces                      ✓
                             │
                             └──< games                     ✓
                                    ├──< game_versions ──< game_snapshots   ✓
                                    ├──< sets ──< cards ──< card_revisions  ✓
                                    ├──< assets                             M6
                                    ├──< card_templates                     M6
                                    ├──< decks ──< deck_versions            ✓
                                    ├──< matches ──< match_players          ✓
                                    │        ├──< match_actions   (append-only)  ✓
                                    │        ├──< match_snapshots            ✓
                                    │        └──< match_notes               M5
                                    └──< simulation_batches ──< simulation_runs  M4
                                                    └──< balance_reports    M4
```

`✓` is migrated today; the rest are specified against the milestone that needs them.
`bot_profiles` (not drawn above — it hangs off `games` with a nullable `game_id`, so a
game-agnostic profile like the built-in random opponent belongs to no game) is also
migrated. `php artisan migrate:status` is the authority.

## Tables

### Identity & tenancy

**`users`** — `id`, `name`, `email`, `password`, timestamps.

**`workspaces`** — `id`, `name`, `slug`, `owner_id`. A design team's container.

**`memberships`** — `workspace_id`, `user_id`, `role` (`owner|designer|playtester|viewer`).
Unique on (`workspace_id`, `user_id`).

Roles matter for playtesting: a `playtester` can join matches and file notes but cannot
edit cards.

### Game definition

**`games`** — the project.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `workspace_id` | uuid FK | |
| `slug` | citext | unique per workspace |
| `name`, `summary` | text | |
| `current_version_id` | uuid FK nullable | the working draft |
| `settings` | jsonb | non-rules preferences (default bot, export options) |

**`game_versions`** — an immutable-once-published system definition.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `game_id` | uuid FK | |
| `semver` | text | `0.4.1` |
| `status` | text | `draft \| published \| archived` |
| `document` | jsonb | **the game-system.json** — see [04](04-game-system-spec.md) |
| `compiled` | jsonb | derived: per-card-type JSON Schema, form descriptors, rules digest |
| `lint` | jsonb | warnings/errors from the last compile |
| `parent_version_id` | uuid FK nullable | what it was branched from |
| `published_at` | timestamptz nullable | |

`compiled` is a cache: it is deterministically derivable from `document` and is rebuilt by
`games:compile`. It exists so the card editor can fetch a form descriptor in one query.

Constraint: `status = 'published'` rows are protected by a trigger that rejects `UPDATE`
of `document`.

**`game_snapshots`** — on publish, one row containing the complete game (system + every
card revision at that moment) as a single `jsonb` bundle. Denormalised on purpose: it makes
"replay a match from six months ago" a single read.

### Content

**`sets`** — `id`, `game_id`, `code` (`core`, `emb1`), `name`, `release_order`, `status`,
`document` (jsonb: description, print run notes).

**`cards`** — the card's identity and current head revision.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `game_id`, `set_id` | uuid FK | |
| `code` | text | stable human id, e.g. `core-012`; unique per game |
| `head_revision_id` | uuid FK | |
| `document` | jsonb | current card document — see [05](05-card-set-deck-spec.md) |
| `status` | text | `draft \| review \| approved \| retired` |
| **index columns** | | |
| `name` | text | generated from `document->>'name'` |
| `card_type` | text | generated |
| `faction` | text | generated, nullable |
| `cost` | int | generated from `document->'attributes'->>'cost'`, nullable |
| `traits` | text[] | extracted by projector (GIN indexed) |
| `keywords` | text[] | extracted by projector (GIN indexed) |
| `search` | tsvector | name + text + flavour, GIN indexed |

Indexes: `(game_id, card_type)`, `(game_id, cost)`, GIN on `traits`, `keywords`, `search`,
and GIN on `document` for ad-hoc `@>` containment queries (e.g. "every card with an
ability that deals damage").

**`card_revisions`** — append-only history.

`id`, `card_id`, `revision` (int), `document` (jsonb), `author_id`, `message` (text),
`created_at`. Unique (`card_id`, `revision`).

Diffs are computed on read (JSON diff between two revision documents), not stored. This
keeps writes cheap and lets us change the diff presentation later.

**`assets`** — `id`, `game_id`, `kind` (`art|icon|frame`), `path`, `mime`, `width`,
`height`, `checksum`, `metadata` jsonb (artist, licence, prompt). Art is referenced from
card documents by asset id, never by path, so files can move.

**`card_templates`** — `id`, `game_id`, `name`, `document` jsonb (declarative layout:
positioned text boxes, art frame, icon slots, fonts). Used by the card renderer for
proofing and PDF export.

### Decks

**`decks`** — `id`, `game_id`, `owner_id`, `name`, `head_version_id`, `archetype` (text,
free tag), `notes`.

**`deck_versions`** — `id`, `deck_id`, `version` (int), `game_version_id` (FK — the system
version this was built against), `document` jsonb (the card list + identity card),
`legality` jsonb (result of the last legality check: `{valid, violations[]}`), `created_at`.

A deck version is **pinned to a game version**. When the system changes, existing deck
versions stay valid as historical artifacts; the UI shows "built against v0.3, current is
v0.5 — re-validate?".

### Playtesting

**`matches`** — one playtest session.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `game_id`, `game_version_id` | uuid FK | pinned |
| `mode` | text | `solo \| hotseat \| online \| simulation` |
| `status` | text | `lobby \| active \| complete \| abandoned` |
| `seed` | bigint | the RNG seed — the whole match hangs off this |
| `config` | jsonb | options: first player, mulligan on/off, sandbox mode |
| `initial_state` | jsonb | the exact `GameState` at t=0 |
| `result` | jsonb nullable | `{winner, reason, rounds, endedAt}` |
| `action_count` | int | |

**`match_players`** — `match_id`, `seat` (int), `user_id` nullable, `bot_profile_id`
nullable, `deck_version_id` FK. Exactly one of user/bot is set.

**`match_actions`** — the append-only log. **This is the match.**

`id` (bigserial), `match_id`, `sequence` (int), `seat`, `action` (jsonb: the action id +
params), `rng_draws` (jsonb: values consumed, for verification), `created_at`.
Unique (`match_id`, `sequence`).

**`match_snapshots`** — `match_id`, `sequence`, `state` (jsonb). Written every N actions
(default 20) and at every match end. Purely an optimisation: seeking to action 400 loads
the nearest snapshot and replays ≤20 actions instead of 400.

Invariant, asserted by a scheduled verifier job: replaying `initial_state` + all actions
must reproduce every snapshot exactly. A mismatch means non-determinism has crept into the
kernel, which is a P0 bug.

**`match_notes`** — playtester feedback anchored to a moment.
`id`, `match_id`, `sequence`, `user_id`, `card_id` nullable, `kind`
(`bug|balance|clarity|idea`), `body`, `resolved_at`. Because it stores `sequence`, opening
a note jumps straight to that game state.

### Simulation & balance

**`bot_profiles`** — `id`, `game_id` nullable (null = generic), `name`, `strategy`
(`random|heuristic|mcts|scripted`), `config` jsonb (weights, iteration budget, script).

**`simulation_batches`** — `id`, `game_id`, `game_version_id`, `label`, `config` jsonb
(matchups, run count, seed range), `status`, `runs_total`, `runs_complete`,
`metrics` jsonb (rolled-up aggregate), `created_by`, timestamps.

**`simulation_runs`** — `id`, `batch_id`, `seed`, `matchup` jsonb (seat → deck version +
bot), `result` jsonb (`{winner, rounds, endReason}`), `telemetry` jsonb (per-card counters:
drawn / played / discarded unplayed / won-when-played), `duration_ms`, `log` jsonb nullable
(full action log, only when `config.keepLogs` or the run errored).

Telemetry is deliberately *per run*, not aggregated at write time, so new metrics can be
computed retroactively from old batches.

**`balance_reports`** — `id`, `batch_id`, `document` jsonb (findings, outlier cards,
win-rate matrix), `created_at`.

## Migration & integrity notes

* All ids are UUIDv7 (time-ordered — good index locality, no enumeration).
* Every content table has `workspace_id` reachable within two joins; a global query scope
  enforces tenancy. Direct `Model::query()` without scope is blocked by an architecture
  test.
* `match_actions` and `card_revisions` have no `UPDATE`/`DELETE` grants for the app role.
  Append-only is enforced at the database, not by convention.
* Deleting a game soft-deletes and detaches; matches referencing it are retained (a
  replay is evidence, and evidence shouldn't vanish because someone tidied up).
* Large `jsonb` documents (game snapshots, match logs) are `TOAST`-compressed by default;
  we do not store them in Redis, only the live working state.

## What lives in Redis

| Key | Contents | TTL |
|---|---|---|
| `match:{id}:state` | current `GameState` (msgpack) | 2h, refreshed on action |
| `match:{id}:version` | optimistic-lock counter | with state |
| `game:{versionId}:compiled` | compiled schemas/form descriptors | until version changes |
| `sim:{batchId}:progress` | counters for the progress bar | 24h |

Redis is a cache and a queue, never a system of record. Losing it costs a state rebuild
from the action log, not data.
