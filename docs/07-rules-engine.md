# 07 — The Rules Engine (Kernel)

The kernel is a **pure, deterministic reducer** over game state. It has no I/O, no clock,
no static state, and no framework dependency. Everything else in the platform is a driver
around it.

```php
reduce(GameState, Action) -> (GameState', Event[])
```

---

## GameState

Fully serialisable to JSON. Schema: [`game-state.schema.json`](../schemas/game-state.schema.json).

```jsonc
{
  "systemVersion": "0.4.0",
  "seed": 8412773901,
  "rngPosition": 47,               // how many values drawn — makes RNG resumable
  "version": 312,                  // optimistic-lock counter, ++ per action
  "round": 4,
  "activePlayer": 0,
  "firstPlayer": 0,
  "phase": "action",
  "step": "main",
  "priority": 1,                   // whose decision it is, null if none pending
  "consecutivePasses": 1,

  "players": [
    { "seat": 0, "resources": { "ember": 2 }, "flags": {}, "identityInstance": "i-001",
      "status": "playing" }
  ],

  "zones": {
    "p0.deck":    ["i-045", "i-032", "..."],
    "p0.hand":    ["i-101", "i-102"],
    "p0.play":    ["i-001", "i-011"],
    "shared.removed": []
  },

  "instances": {
    "i-011": {
      "code": "core-012", "owner": 0, "controller": 0, "zone": "p0.play",
      "exhausted": false, "faceDown": false,
      "counters": { "damage": 1 },
      "attachedTo": null, "attachments": [],
      "enteredOnRound": 3,
      "usedLimits": { "a1": { "round": 4, "count": 1 } }
    }
  },

  "modifiers": [
    { "id": "m-7", "source": "i-014", "layer": 6, "timestamp": 118,
      "query": { "...": "..." }, "changes": [ ... ], "duration": "round" }
  ],

  "stack": [ /* resolving abilities, LIFO */ ],
  "triggerQueue": [ /* triggers awaiting placement on the stack */ ],
  "pendingChoice": null,           // set when waiting on a player decision
  "vars": {},
  "log": [ /* recent events, capped; full history lives in the DB */ ],
  "result": null                   // { winner, reason } once terminal
}
```

Design notes:

* **Zones are ordered arrays of instance ids.** Position in the array *is* position in the
  zone (index 0 = top of deck).
* **Instances are a flat map**, not nested inside zones, so an instance's identity is
  stable across zone changes — essential for animation and for "when this leaves play"
  triggers.
* **Derived values are absent.** Attack, health, cost etc. are computed on demand from the
  card definition plus `modifiers`. Storing them would create two sources of truth.
* **`rngPosition` rather than an RNG object.** The generator is reconstructed from
  `(seed, position)`, which makes state pure JSON and replays exact.

## Determinism

Non-negotiable, and enforced:

1. **All randomness goes through the seeded source.** A PCG64 stream derived from `seed`;
   every draw increments `rngPosition`. Any use of `rand()`, `shuffle()`, `array_rand()`,
   `uniqid()`, `time()` inside the kernel package fails an architecture test.
2. **No iteration over unordered collections.** Maps are always iterated in a defined
   order (insertion or sorted by id). PHP array ordering is stable, but the test suite
   asserts explicit sorts where order affects outcome.
3. **No wall-clock, no locale, no floats in rules maths.** Integer arithmetic only;
   `decimal` attributes are stored as scaled integers internally.
4. **Verification job.** A nightly task re-replays a sample of stored matches and compares
   against stored snapshots. Any divergence is a P0.

The payoff: a replay is `(initial_state, seed, action[])` — a few kilobytes that reproduce
a bug exactly, on any machine, months later.

## The main loop

```
apply(state, action):
    assert action is in legalActions(state, action.seat)
    payCosts → chooseTargets(already supplied in action params)
    push ability/action effect onto stack
    settle(state)

settle(state):
    loop:
        if stack not empty:            resolve top item one step
        else if triggerQueue not empty: order and push triggers onto stack
        else if pendingChoice:         return (waiting on a player)
        else if stateChecks fire:      apply them, continue
        else if current step is auto:  run its script, advance
        else if window is open:        return (waiting on a player)
        else:                          advance step/phase/round
        if terminal condition:         set result, return
```

`settle` is what makes the engine feel like a game rather than a database: after one player
action, the engine runs everything automatic until it needs a human again.

### Step advancement

```
step ends → step.ended → next step in phase
          → if none: phase.ended → next phase
          → if none: round.ended → round.began, rotate first player, round++
```

## Event & timing model

Every op emits an event. Events flow through three windows:

```
        ┌── "before"  → replacement abilities may modify or cancel
event ──┤
        └── "after"   → triggered abilities are queued
```

1. **Replacement (`instead`) window.** Applicable replacement effects are collected. If
   more than one applies, the *affected player* chooses the order (standard practice, and
   avoids an arbitrary rule). Each replacement may apply once per event.
2. **The event happens.** State mutates.
3. **Trigger collection.** Every triggered ability whose filter matches is added to
   `triggerQueue` with its controller.
4. **Trigger ordering.** When the current resolution finishes, queued triggers are put on
   the stack in **APNAP order** — active player's triggers first, then each other player in
   turn order. A player with multiple simultaneous triggers chooses their relative order
   (a `pendingChoice`), unless the system sets `triggerOrdering: "declaration"`.
5. **Stack resolution.** LIFO. Resolving an item may emit events, which may queue further
   triggers — those go on top and resolve first.

### Priority windows

In an `alternating` window, priority starts with the active player. Taking an action
resets the pass counter; passing increments it. Two consecutive passes end the step. A
player with no legal action other than "pass" auto-passes (configurable), so the UI does
not nag.

### State checks

After every stack resolution and before priority is handed back, `stateChecks` are
evaluated repeatedly until none fire. A cycle guard caps this at 32 iterations and raises
`StateCheckLoop` naming the checks and cards involved — this converts an infinite-loop hang
into a legible bug report, which matters enormously during simulation runs.

## Legality

```php
legalActions(state, seat) -> ActionList
```

For the current window, for each action template allowed in it:

1. Evaluate `requirements` — skip if false.
2. Enumerate target combinations (capped; see below) — skip if none legal.
3. Check `cost` payability against current resources and state.
4. Emit one entry per legal (action, target-combination), each with a stable `actionId`
   the client sends back.

Plus: activated abilities on cards the player controls in eligible zones, and `pass`.

**Combinatorial guard.** Enumerating every target combination is exponential for "choose
up to 3 of 12". The kernel enumerates fully up to a configurable budget (default 512
combinations per action); beyond that it returns a *parameterised* action — the client and
bots then request a target list separately via `legalTargets(state, actionId, targetId)`.
The UI experience is the same (click card → see legal targets highlighted); the
enumeration just becomes lazy.

## Hidden information

`Kernel::view(state, seat)` produces a redacted `PlayerView`:

* Cards in zones with `visibility: none` become `{ id, hidden: true }` — instance id
  preserved so the client can animate, definition withheld.
* `visibility: owner` reveals only to the owner.
* Per-instance `revealedTo: [seat]` overrides zone visibility (for "reveal the top card").
* Deck contents are hidden, but deck *size* is public.
* `pendingChoice` is only sent to the player who must decide.
* The view carries a `viewVersion` matching `state.version`, used for optimistic locking.

The server never sends a full state to a client. Not "the client is trusted not to look" —
the data is not transmitted.

## Undo, rewind and sandbox mode

* **Undo** is implemented by *rewinding the action log and replaying*, not by inverse
  operations. Inverse ops are a bug farm; replay is exact by construction.
* The log is not truncated to do it. An undo is *recorded* as an entry naming the sequence
  to rewind to, and reconstruction folds it away — which keeps doc 03's append-only
  guarantee and this exactness at the same time. See
  [ADR-0008](adr/0008-undo-is-recorded.md).
* Undo is allowed freely in `solo`/`hotseat`/`sandbox` modes. In `online` mode it requires
  consent from all seats (a proposal message), because undoing past a hidden-information
  reveal leaks.
* **Sandbox mode** unlocks god-mode actions: put any card into any zone, set resources,
  set counters, reveal all, force a phase. These are recorded in the log as
  `sandbox.*` actions so a sandbox session is still a valid, reproducible replay. Any match
  containing sandbox actions is flagged and excluded from balance statistics.

## Error taxonomy

The kernel throws typed, structured diagnostics — never bare exceptions — because these
surface directly in the designer's UI:

| Diagnostic | Meaning |
|---|---|
| `IllegalAction` | Action not in the legal set (client desync or cheating) |
| `UnknownOp` | Ability references an op the kernel doesn't implement |
| `UnresolvedSelector` | `$target.x` referenced but never declared |
| `ModifierCycle` | Layer resolution failed to reach a fixed point |
| `StateCheckLoop` | State checks did not stabilise |
| `TriggerDepthExceeded` | Trigger recursion beyond the configured depth (default 64) |
| `NoLegalActions` | A window opened with nothing to do and no pass available — a system bug |

Each carries the card, ability id and state version, so a designer sees *"Ashen Vanguard,
ability a1, round 4"* rather than a stack trace.

## Performance

* State is a plain PHP array/object graph; cloning uses copy-on-write structural sharing
  for zones and instances rather than deep copies.
* Derived attribute lookups are memoised, keyed on `state.version`.
* `legalActions` results are cached per `(version, seat)`.
* Target enumeration is short-circuited by the budget above.

Budget: a mid-game board with ~20 instances and ~10 modifiers should compute
`legalActions` in under 15ms and settle a typical action in under 5ms, giving ~200ms
headless matches for simulation.
