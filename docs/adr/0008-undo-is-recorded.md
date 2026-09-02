# ADR-0008 — Undo is recorded, not performed

**Status:** Accepted

## Context

Two documents give incompatible instructions about the same table.

[Doc 08](../08-playtest-runtime.md) describes undo as truncate-and-replay: drop the actions
after a sequence and rebuild. [Doc 03](../03-data-model.md) makes `match_actions` append-only,
with no `UPDATE` or `DELETE` grant for the application role — implemented here as a plpgsql
trigger, since there is no second database role.

Both are protecting something real. Truncation is the simplest correct undo, and undo is not
optional: a designer testing a card plays the same turn six different ways. An immutable log
is what makes a replay trustworthy, a bug report reproducible, and "the state can always be
rebuilt from the log" true rather than aspirational.

Taking either at face value loses the other.

## Decision

An undo is **recorded as an entry in the log**, not performed on it.

```json
{ "op": "undo", "toSequence": 12 }
```

Reconstruction folds those entries away: `MatchService::effectiveActions()` walks the log
forward, and an `undo` entry drops every entry after the sequence it names — including
earlier `undo` entries, so undoing an undo behaves the way anyone would expect. Everything
downstream of reconstruction — `rebuild()`, the replay export, snapshots — sees the sequence
of actions that actually shaped the position, and knows nothing about undo.

The trigger that enforces append-only had to be written carefully: it refuses `UPDATE`
always, but refuses `DELETE` only while the parent row still exists. Otherwise deleting a
match would be blocked by its own cascade, and "immutable" would mean "undeletable", which is
not what doc 03 is asking for.

## Consequences

**Good.** Both guarantees hold at once. The log stays complete and append-only, so replay
stays exact and a bug reproduces. A playtest note can see that an undo happened and where —
which is frequently the interesting part of the report, because a designer undoing the same
move three times is telling you something about the card.

It also means undo needs no special case anywhere else. A bot's move is an ordinary entry
([ADR-0009](0009-server-driven-bot-seats.md)), so an undo rewinds past the opponent's reply
without any code that knows what a bot is.

**The cost.** The log is longer than the position needs, and reconstruction does a fold that
truncation would not. Both are cheap: reconstruction already replays from the nearest
snapshot, and the fold is a single pass over at most twenty entries.

**What would make us revisit it.** A match long enough that the folded-away entries dominate
reconstruction cost. Snapshots every twenty actions already bound this, so it would take a
pathological undo loop — which is itself worth seeing in the log.
