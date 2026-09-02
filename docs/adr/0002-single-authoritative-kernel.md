# ADR-0002 — One authoritative PHP rules kernel

**Status:** Accepted

## Context

The rules engine could live on the server (PHP), in the browser (TypeScript), or both.

Both is tempting: a client-side engine gives instant response, offline play, and
zero-latency simulation. Several commercial digital card games do exactly this.

## Decision

**The kernel exists once, in PHP, on the server.** The Vue client contains no rules logic.
It renders the redacted state it is given and displays the legal action list the server
computed.

## Consequences

**Good**

* One definition of the game. There is no possibility of the client and server disagreeing,
  because there is nothing to disagree with.
* Cheating is structurally impossible: hidden information is never transmitted, and illegal
  actions are rejected by the only authority.
* The engine used by playtests is byte-identically the engine used by simulations and
  regression tests. Test coverage means what it appears to mean.
* Half the engine work, and much less than half the debugging.

**Bad**

* Every action costs a round trip. Mitigated by animating optimistically while awaiting
  authoritative state, and by a p95 target under 120ms.
* No offline play.
* Simulation cannot run in the browser, so batch runs need workers. (Which we want anyway —
  10,000 matches shouldn't run on a laptop.)

## The escape route, if we need it

If latency becomes a genuine product problem, a TypeScript kernel may be added **for
prediction only** — never as an authority — under one hard condition:

> It must reproduce every golden replay fixture ([doc 13](../13-validation-and-testing.md))
> with identical state hashes at every checkpoint, in CI, on every commit.

If it can't pass the conformance suite, it doesn't ship. This turns "the two engines will
drift" from a certainty into a build failure.

## Revisit if

Measured p95 action latency exceeds ~200ms for real users, or offline authoring/playtesting
becomes a requirement.
