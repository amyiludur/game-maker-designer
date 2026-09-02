# ADR-0006 — The canonical state hash

**Status:** Accepted

## Context

[ADR-0005](0005-determinism-and-replay.md) makes replays the universal mechanism and
[ADR-0002](0002-single-authoritative-kernel.md) says a second implementation must clear the
conformance suite. Both rest on a single number: the hash of a game state. Every blessed
fixture, every checkpoint, every "divergence is a P0" alarm compares hashes rather than
states, because comparing two large JSON documents tells you *that* they differ far more
easily than it tells you *where*.

Neither document says what goes into that hash, and the choice is not obvious. `GameState`
carries things that are unambiguously part of the position (zones, instances, resources), and
things that are not (a truncatable event log). Hashing the wrong set makes a fixture either
too brittle to keep or too weak to catch anything.

The decision has to be made once and cannot be revised casually: changing it invalidates
every fixture in the repository at the same moment.

## Decision

`sha256` over a canonical JSON encoding of the state, with `log` removed and the compiled
system's digest added.

**The encoding** (`State/Codec/CanonicalJson.php`):

* UTF-8, object keys sorted bytewise, no insignificant whitespace, arrays in their natural
  order — array order is data.
* **Integers only.** Encoding a float throws rather than rounding. A float in a hashed state
  is the failure mode ADR-0005 exists to prevent, and it would arrive silently.
* An explicit empty object is `\stdClass`, so `{}` and `[]` do not collide. PHP cannot tell
  them apart once a document has been through an associative decode, so the distinction is
  carried in the type rather than inferred.

**What is hashed:**

| In | Out |
|---|---|
| `zones`, `instances`, `players`, `vars` (including the `__`-prefixed engine counters) | `log` |
| `stack`, `triggerQueue`, `pendingChoice` | |
| `round`, `phase`, `step`, `activeSide`, `priority`, `version` | |
| `seed`, `rngPosition` | |
| `systemDigest` — the compiled rules the state was produced under | |

`log` is excluded because doc 07 defines it as "recent events, capped": presentational,
truncatable, and not part of the position. Two states that differ only in how much of their
history they still remember are the same position.

`stack`, `triggerQueue` and `pendingChoice` are included, which required giving them concrete
shapes in `game-state.schema.json` — it previously said "array of object" with no inner
structure, which cannot support a byte-identical claim.

`systemDigest` is folded in so that the same actions replayed against edited rules produce a
different hash rather than a confusing pass.

## Consequences

**Good.** A fixture is a few kilobytes of actions and a list of hashes, not a megabyte of
serialised states. A conformance failure names the exact action at which two implementations
diverged. Editing a card invalidates the replays that depended on it, loudly, at the moment
of the edit.

**The cost.** Changing the encoding, the excluded set, or the digest invalidates every
fixture in the repository at once. That is the intended weight: it is why `log` was argued
about before the first fixture was blessed rather than after.

**What would make us revisit it.** A second implementation finding a construct it cannot
reproduce byte-for-byte — most plausibly around integer width, which is why bounded RNG draws
stay inside 32 bits (see [ADR-0007](0007-pcg64-from-phps-engine.md)).
