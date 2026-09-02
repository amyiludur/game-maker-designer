# 02 — Architecture

## Stack

| Layer | Choice | Why |
|---|---|---|
| Backend | **Laravel 12** (PHP 8.4) | Laravel 11 reached end of life before this was built; 12 is the closest supported version and every package needed supports it |
| Rules kernel | **Plain PHP**, framework-free package (`packages/kernel`) | Must run in HTTP, in queue workers, and in tests with no Laravel bootstrapping |
| Database | **PostgreSQL 16** | `jsonb` + GIN indexes; we lean on JSON heavily |
| Cache / queue | **Redis** | Job queue for simulations, live table state cache |
| Realtime | **Laravel Reverb** (WebSocket) | First-party, self-hosted, no external dependency |
| Frontend | **Vue 3.5** + TypeScript 5.7 + **Vite 6** | Requested |
| State | **Pinia 2** | Store-per-domain, good TS inference |
| Routing | **Vue Router 4** | |
| Styling | **Plain CSS over a custom-property token layer** | See below — the accent is game data, so it cannot be a build-time colour |
| Validation | **JSON Schema draft 2020-12** — `opis/json-schema` (PHP), `ajv` (TS) | One contract, both sides |
| Testing | **Pest 3** (kernel/harness), PHPUnit (app), **Vitest 3** + **Playwright 1.6** (TS) | |

**Not yet built**, and named here so the table is not read as a description of the tree:
Redis is optional (the cache store is configurable and match state rebuilds from the action
log without it), there is no Docker Compose environment, and Reverb is not installed —
online multiplayer is M5, and solo and hotseat are REST. There is no authentication yet.

**Why not Tailwind.** The design handoff's `--accent` is *game data* — `ui.theme.accent`,
`#c0392b` for Emberfall — read from the loaded game document at runtime. A build-time colour
palette cannot express that, and the whole point of a multi-game platform is that Emberfall
does not look like Warden's Hollow. The token layer is CSS custom properties
(`apps/web/src/design/tokens.css`) and the components use them directly.

The tree these live in, and which parts may depend on which, is
[doc 17](17-repository-layout.md).

## The layer cake

```
┌───────────────────────────────────────────────────────────────┐
│  Vue 3 SPA                                                    │
│  Designer UI · Deck builder · Play table · Simulation lab      │
│  Renders state. Never decides legality.                        │
└──────────────┬──────────────────────────┬─────────────────────┘
               │ REST (author/query)      │ WebSocket (live table)
┌──────────────▼──────────────────────────▼─────────────────────┐
│  Laravel application                                           │
│  ├─ Authoring    games, sets, cards, decks, versions, diffs    │
│  ├─ Compiler     system JSON → per-card-type JSON Schema,      │
│  │               form descriptors, lint report, rules digest   │
│  ├─ Match svc    session lifecycle, action intake, redaction   │
│  ├─ Sim svc      queued batch runs, metrics aggregation        │
│  └─ Export       card renders, PDF proxies, rulebook, bundles  │
└──────────────┬────────────────────────────────────────────────┘
               │ pure function calls, no HTTP
┌──────────────▼────────────────────────────────────────────────┐
│  Rules Kernel  (framework-free PHP package)                    │
│  reduce(GameState, Action) → (GameState', Event[])             │
│  · Legality      · Effect interpreter   · Modifier layers      │
│  · Event bus     · Trigger queue/stack  · Seeded RNG           │
│  Deterministic. No I/O. No clock. No global state.             │
└──────────────┬────────────────────────────────────────────────┘
               │
┌──────────────▼────────────────────────────────────────────────┐
│  PostgreSQL          Redis                                     │
│  jsonb documents     live state cache, job queue                │
│  + index columns                                                │
└───────────────────────────────────────────────────────────────┘
```

## Where the rules run — and why only there

The kernel is **authoritative and singular**. It runs on the server. The Vue client holds
no rules logic at all: it renders the redacted state it is given and displays the list of
legal actions the server computed.

This is a deliberate trade. The alternative — porting the kernel to TypeScript so the
browser can simulate locally — buys snappier interactions and offline play, and costs you
two implementations of the most subtle code in the system, which *will* diverge. Divergence
in a rules engine is not a cosmetic bug; it means the game you playtested is not the game
you shipped.

The latency cost is real but small: an action round-trip on a LAN/localhost dev setup is
~5–15ms, and the UI hides it with optimistic *animation* (the card visually moves) while
still waiting for authoritative state before committing.

**The door is left open.** ADR-0002 defines a conformance suite of golden replays. If a
TypeScript kernel is ever added for optimistic prediction, it must reproduce every replay
byte-for-byte to be allowed to ship. See [`adr/0002-single-authoritative-kernel.md`](adr/0002-single-authoritative-kernel.md).

## The kernel contract

The entire kernel is one pure reducer plus a legality query:

```php
interface Kernel {
    /** Every legal action the given player may take right now. */
    public function legalActions(GameState $s, PlayerId $p): ActionList;

    /** Apply one action. Throws IllegalActionException if not legal. */
    public function apply(GameState $s, Action $a): StepResult;   // { state, events }

    /** Advance automatic processing (auto-steps, trigger queue, stack) until
     *  the game is waiting on a human/bot decision or has ended. */
    public function settle(GameState $s): StepResult;

    /** Redact a state to what one player is allowed to see. */
    public function view(GameState $s, PlayerId $p): PlayerView;
}
```

Everything else in the platform — playtest tables, bots, simulations, replay verification,
regression tests — is a driver around this contract. There is exactly one place where the
game's meaning lives.

## Request flows

**Authoring a card**

```
Vue card editor
  → GET /api/v1/games/{g}/schema/card-types/{t}   (form descriptor + JSON Schema)
  → user edits, client validates with ajv (instant feedback)
  → PUT /api/v1/cards/{id}   → server re-validates with opis/json-schema (authoritative)
                             → lint pass (ability references, dangling keywords, cost sanity)
                             → new card revision row, jsonb document updated
```

Client-side validation is a convenience. Server-side validation is the rule. They use the
same schema document, so they cannot disagree.

**Taking an action in a live match**

```
Vue table  → WS: { matchId, actionId, params, expectedVersion }
Laravel    → load state (Redis, fall back to DB snapshot + log replay)
           → guard: is it this player's decision? does version match? (optimistic lock)
           → Kernel::apply → Kernel::settle
           → append to immutable action log; write snapshot every N actions
           → broadcast Kernel::view(state, p) to each player on their private channel
Vue table  ← per-player redacted state + legal action list + event feed
```

**Running a simulation batch**

```
POST /api/v1/simulations
  → SimulationBatch row, N jobs onto Redis queue
  → each worker: build initial state from (system version, deck versions, seed)
                 loop { legalActions → bot.choose → apply → settle } until terminal
                 record result row + per-card telemetry (no full log unless flagged)
  → aggregator job rolls up metrics, writes report
  → UI polls or receives a broadcast when the batch completes
```

Because the kernel is pure and has no I/O, a worker can run thousands of matches per
minute per core.

## Versioning model

Three independently versioned things, all immutable once published:

* **Game system version** — semver. Breaking = a change that could invalidate existing
  cards or decks (removing a zone, renaming an attribute, changing a phase).
* **Card revision** — monotonic integer per card. Card text/stats/abilities.
* **Deck version** — monotonic integer, pinned to a game system version.

A **match is pinned** to exactly one (system version, deck version, deck version) triple.
This is what makes a replay from three months ago still reproduce: you rebuild the same
kernel inputs, not "whatever the cards say today". It also makes A/B balance testing
possible — run the same decks against system v0.4 and v0.5 and diff the win rates.

Draft versions are mutable and playable; published versions are frozen. Publishing writes
an immutable snapshot document of the entire game (system + all cards) so it can be
reconstructed without walking revision history.

## Extension points

1. **Custom ability handlers.** A game may ship a PHP package registering named handlers
   for the `custom` op (see [06 — Effect DSL](06-effect-dsl.md)). Reviewed, versioned code
   — never user-supplied script.
2. **Bot strategies.** Implement the `Agent` interface; register by id. Bot *tuning* is
   JSON (weights, priorities), so designers can tune without code.
3. **Card layout templates.** Declarative positioned-element documents, so a game can look
   like itself without touching the renderer.
4. **Export targets.** `Exporter` interface — PDF proxies, TTS/Tabletop Simulator, CSV,
   full JSON bundle.

## Non-functional targets

| Concern | Target |
|---|---|
| Action round-trip (live table) | p95 < 120ms server-side |
| Legal action computation | < 15ms for a typical mid-game board |
| Headless match (2 bots, ~25 rounds) | < 200ms |
| Simulation batch of 10,000 matches | < 10 min on 4 workers |
| Card list query (5,000 cards, filtered) | < 100ms |
| Concurrent live tables per node | 200+ |
