# ADR-0004 — Continuous effects resolve through a fixed layer system

**Status:** Accepted

## Context

"What is this creature's attack?" is trivial until several effects argue about it:

* a lord gives all Soldiers +1
* an effect sets base attack to 1
* it has two +1/+1 counters
* an effect doubles its attack
* another effect says it isn't a Soldier any more

Apply these in different orders and you get different, equally defensible answers. Card
games that don't fix this order end up with rules that depend on the order effects happened
to be written — unpredictable for players and untestable for developers.

Two implementation approaches exist: **mutate stored stats** when an effect applies, or
**recompute derived values on read** from base + active modifiers.

## Decision

Derived characteristics are **never stored**. They are recomputed on read from base values
plus all active modifiers, applied in a fixed 10-layer order (documented in
[doc 06](../06-effect-dsl.md#modifiers-and-the-layer-system)). Within a layer, modifiers
apply in timestamp order; same-layer dependencies resolve by iterating to a fixed point,
capped at 8 passes.

## Consequences

**Good**

* Order-independence: the answer depends on the layer rules, not on the sequence in which
  cards were played. The same board always yields the same stats.
* Removing an effect is trivial — drop the modifier. Under the mutation approach, "undo the
  +1" is famously error-prone when other effects have since changed the same value.
* The **modifier breakdown** in the card inspector ("Attack 3 = 2 base +1 from Warhorn")
  falls out for free, and is the single most useful debugging affordance in the whole play
  table.
* Layer conflicts are testable as a matrix.

**Bad**

* Reads are more expensive than a field lookup. Mitigated by memoisation keyed on the state
  version counter.
* The layer order must be understood by anyone extending the engine, and getting it wrong is
  subtle.
* Circular dependencies are possible in principle. Handled explicitly: the fixed-point cap
  raises a `ModifierCycle` diagnostic naming the cards, rather than hanging — which matters
  enormously during unattended simulation runs.

## Revisit if

Profiling shows modifier resolution dominating simulation time even with memoisation — the
answer then is a smarter invalidation strategy, not stored stats.
