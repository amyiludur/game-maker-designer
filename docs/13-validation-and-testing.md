# 13 — Validation & Testing

Three defence lines: **schemas** (is this document well-formed?), **lint** (is this game
sane?), **tests** (does the engine do what we said?).

---

## 1. Schema validation

Every document is validated against a JSON Schema (draft 2020-12) in [`schemas/`](../schemas/).

* **Client**: `ajv` — instant feedback while typing.
* **Server**: `opis/json-schema` — authoritative, on every write.
* **CI**: every file in `examples/` is validated against its schema. The example game is a
  test fixture, and a broken example fails the build.

Card documents are validated twice: once against the generic `card.schema.json`, and once
against the **compiled per-card-type schema** derived from the game system. The second is
what catches "this character has no health" for a game whose characters need health, without
the platform knowing anything about characters.

## 2. Lint rules

Run on every system/card save; results surface in the UI as errors and warnings.

### System lint

| Rule | Severity |
|---|---|
| Zone/phase/step/action ids unique and referenced consistently | error |
| Every `playableTo` names a declared zone | error |
| Every trigger event is core or declared | error |
| Every keyword referenced by a card exists | error |
| Every attribute referenced by an ability exists on that card type | error |
| Modifier targets an attribute not in `modifiableAttributes` | error |
| Every action is reachable from at least one window | warning |
| No win condition declared | error |
| No round cap or equivalent terminating condition | **error** (simulations would hang) |
| Resource declared but never spent by any action or ability | warning |
| Counter declared but never added or read | warning |
| Keyword grants a permission or restriction nothing ever checks | warning |
| Keyword defined but no card carries it | info |
| Vocabulary entry never used | info |

The grant rule earns its place: a keyword that grants a restriction nothing consults reads
like a rule on the card, a designer balances around it, and it does nothing. Emberfall's
`guard` is exactly that today, on two printed cards — see the note in
[the Emberfall README](../examples/emberfall/README.md).

### Card lint

| Rule | Severity |
|---|---|
| Attributes validate against the card type's compiled schema | error |
| Trait/faction/rarity outside its vocabulary | error |
| Ability references an undeclared selector (`$target.x` never declared) | error |
| Ability uses an op the kernel doesn't implement | error |
| Required target whose query can never match anything in this game | error |
| `textOverride` diverges from generated text | warning (with a diff) |
| Card has no art asset | info |
| Cost far outside the regression prediction for its stats | info |
| Card is an exact stat+ability duplicate of another card | warning |
| Ability uses `custom` | info (tracked as a ratio; see [doc 06](06-effect-dsl.md)) |

### Deck lint

Deckbuilding constraints from the system document, evaluated by the same expression
evaluator as everything else.

## 3. Kernel tests

### Unit

Per op, per predicate, per expression: given a small hand-built state, assert the state
delta and the emitted events. Boring, numerous, fast — the foundation.

### Layer system

Table-driven tests over the modifier layers: a matrix of `(base, set, add, multiply,
counters)` combinations with expected results, including deliberately dependent modifiers,
plus cycle cases asserting `ModifierCycle` is raised rather than hanging.

### Timing

Scenario tests for the parts that are always subtly wrong:

* simultaneous triggers ordered APNAP, with the controller choosing among their own
* a trigger queued during another trigger's resolution goes on top
* replacement effects: multiple applicable, affected player orders them, each applies once
* targets that become illegal before resolution are dropped; all-targets-gone fizzles
* state checks cascade (a death causes another death) and terminate

### Property tests

Invariants that must hold after **every** action, checked by running Random bots over
generated states:

1. Every instance is in exactly one zone, and that zone's array contains it exactly once.
2. Zone array length equals the count of instances claiming that zone.
3. Resources are within `[min, max]`.
4. No counter is negative.
5. Attachments are mutual and both cards are in attachment-supporting zones.
6. `settle()` always terminates within the configured step budget.
7. `legalActions()` never returns an action that `apply()` rejects.
8. A window never opens with an empty action list.

Invariant 7 is the important one: it is exactly the guarantee the UI depends on.

### Fuzzing

Random bots play thousands of matches per CI run against every example game, asserting
invariants and catching `UnknownOp`, `StateCheckLoop`, `TriggerDepthExceeded`,
`ModifierCycle`. Any failing seed is auto-saved as a regression fixture — the failure
becomes a permanent test with no human transcription step.

### Golden replays

The conformance suite. Each fixture is a `replay.json`:

```json
{
  "gameVersion": "0.4.0",
  "seed": 8412773901,
  "decks": { "0": "...", "1": "..." },
  "actions": [ { "seq": 1, "seat": 0, "actionId": "play_card", "params": {...} } ],
  "expected": {
    "finalStateHash": "sha256:…",
    "result": { "winner": 0, "reason": "objective_burned", "rounds": 7 },
    "checkpoints": [ { "seq": 20, "stateHash": "sha256:…" } ]
  }
}
```

Replaying must reproduce every checkpoint hash exactly. These fixtures:

* prove determinism across machines and PHP versions,
* catch unintended rules changes when the kernel is refactored,
* are the contract any future TypeScript kernel must satisfy ([ADR-0002](adr/0002-single-authoritative-kernel.md)),
* are generated for free from real playtests: export the match's replay, then
  `php packages/harness/bin/gmd replay <file> --bless` fills in the hashes.

When a golden replay breaks after a *deliberate* rules change, the diff shows the first
diverging action — you re-bless it with an explicit command that records why.

## 4. Application tests

* **Feature tests** per API endpoint: auth, tenancy scoping, validation errors, optimistic
  concurrency (409 on stale version), immutability of published versions and action logs.
* **Projection test**: `cards:reproject` from documents must reproduce every index column
  byte-for-byte. This is what makes "JSON is truth" a fact rather than an aspiration.
* **Architecture tests** (Pest arch plugin):
  * the kernel package imports nothing from `Illuminate\*`
  * no `rand`, `shuffle`, `time`, `uniqid`, `array_rand`, `now()` anywhere in the kernel
  * no model query outside a workspace scope
* **Migration test**: fresh migrate + seed + reproject on every CI run.

## 5. Frontend tests

* **Vitest** — stores, composables, and the form/JSON round-trip in the ability builder
  (a property test: generate random valid abilities, render to form, read back, assert
  deep equality).
* **Component tests** — schema-driven form generation across several card-type shapes,
  including pathological ones (0 attributes, 20 attributes).
* **Playwright E2E**:
  1. create game from template → add a card → validate → save
  2. build a legal deck → start a solo match → play three rounds → assert the log
  3. run a 50-match simulation → open the report
  4. open a replay → scrub → fork
* **Visual regression** on the card face renderer and the play table (Playwright snapshots),
  since silent layout breakage in card rendering is otherwise very easy to miss.

## 6. CI pipeline

```
schemas       validate every examples/** file against its schema
kernel        Pest — unit, timing, layers, property invariants, architecture
conformance   replay every golden fixture, assert every checkpoint hash
fuzz          200 random-bot matches, asserting invariants after every action
api           migrate, import both example games, php artisan test
web           generated types are current, vue-tsc, vite build, Vitest
lint          php-cs-fixer, eslint, prettier
e2e           Playwright against a seeded app  (advisory)
```

Implemented in `.github/workflows/validate.yml`. Two deviations from the list above as it was
originally written, both deliberate:

* **`phpstan level 8` is absent.** It belongs here, but it ships as a phar from GitHub
  releases which this project's build environment cannot reach, and depending on something
  that cannot be installed would make the whole pipeline unrunnable to gain a check that
  would not run. `vue-tsc --noEmit`, ESLint and the architecture tests cover part of it.
* **Fuzz runs Emberfall only**, not "per example game". Warden's Hollow declares two ops the
  kernel does not implement yet (`reveal_encounter`, `run_activation` — the co-op ops
  deferred from this pass), so it cannot be played headlessly, and a permanently red job
  teaches people to ignore the pipeline.

The `perf` gate is asserted in the kernel's own suite rather than as a CI stage, measured in
CPU time via `getrusage()` — wall-clock made it flake whenever anything else was running on
the box, which is most of the time on a shared runner.

The `perf` gate exists because simulation throughput is a product feature: a kernel that
gets 5× slower quietly turns a coffee-break batch into an overnight one.
