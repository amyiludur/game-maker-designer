# ADR-0005 — Determinism is a hard requirement

**Status:** Accepted

## Context

A rules engine can be "mostly deterministic" — using a seeded shuffle but a wall-clock
tiebreak here, a hash-ordered iteration there. It usually works. It also makes replays
unreliable, which quietly removes bug reproduction, regression testing, state recovery and
A/B balance comparison from the table.

## Decision

Strict determinism, enforced by tests, not by intent:

1. All randomness derives from a seeded PCG64 stream. `GameState` stores `(seed,
   rngPosition)`, so the generator is reconstructible and the state stays pure JSON.
2. No wall-clock, locale, floating-point rules maths, or unordered iteration inside the
   kernel. An architecture test bans `rand`, `shuffle`, `array_rand`, `time`, `uniqid`,
   `now()` from the kernel package outright.
3. Custom ability handlers are held to the same rules, and are reviewed as code.
4. A nightly job re-replays sampled stored matches against their snapshots. Divergence is
   a P0.

## Consequences

One mechanism — `(initial state, seed, actions[])` — provides:

| Capability | How |
|---|---|
| Bug reports that reproduce | Share the replay |
| Regression tests | Golden replays with state hashes |
| Undo | Truncate the log and replay |
| Crash/state recovery | Rebuild from the log |
| Replay scrubbing | Nearest snapshot + replay forward |
| A/B balance testing | Same seeds, different game version |
| Cross-implementation conformance | Hash comparison ([ADR-0002](0002-single-authoritative-kernel.md)) |

That is an unusually large amount of product surface for one constraint, and it is why the
constraint is non-negotiable.

**Costs**

* Discipline. It is easy to break by accident, hence the automated bans.
* Snapshots cost storage (mitigated: every 20 actions, `jsonb` compressed).
* Integer-only rules maths occasionally forces awkward formulations (e.g. "half, rounded up"
  must be written explicitly rather than relying on float division).

## Revisit

Never. This one is load-bearing.
