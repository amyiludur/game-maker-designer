# ADR-0007 — PCG64 comes from PHP's own engine, not from arithmetic we write

**Status:** Accepted

## Context

[ADR-0005](0005-determinism-and-replay.md) requires that all randomness derive from a seeded
PCG64 stream and that `GameState` store `(seed, rngPosition)` so the generator is
reconstructible. Implementing PCG64 is four lines of arithmetic:

```
state = state * 6364136223846793005 + increment
```

In PHP that multiplication overflows, and PHP does not wrap on overflow — **it silently
promotes to float**. In this environment `6364136223846793005 * 3` evaluates to
`float(1.909…E+19)`. Neither GMP nor bcmath is installed, so there is no arbitrary-precision
escape hatch.

This is the worst shape a bug can take. The generator keeps returning plausible numbers. Only
the low bits are wrong, only sometimes, and the first symptom is a conformance fixture that
fails on one machine and passes on another — months later, in the one subsystem whose entire
value is being trustworthy.

The obvious workaround is to implement wrapping 64-bit arithmetic over pairs of 32-bit limbs,
where every intermediate product fits exactly in a signed 64-bit int. That works, and it is
roughly two hundred lines of code whose correctness the determinism of the whole platform
depends on.

## Decision

Use `\Random\Engine\PcgOneseq128XslRr64` from PHP's core `random` extension, and write none of
the arithmetic ourselves. `Rng/Pcg64Rng.php` is the only file in the kernel permitted to touch
a random engine, and an architecture test enforces that.

Three properties of the wrapper are **specification**, not implementation detail, because a
second implementation in another language has to match them byte for byte to pass the
conformance suite:

1. **`position` counts raw engine draws and nothing else.** In particular `\Random\Randomizer`
   is deliberately *not* used: its rejection loops consume an unspecified number of draws, so
   `position` would stop being reconstructible.
2. **A bounded draw uses only the low 32 bits of each draw, with modulo rejection**
   (`min = 2^32 % range`, reject below it). Staying inside 32 bits keeps every intermediate
   exactly representable in PHP's signed ints *and* in a JavaScript `Number`, which is what a
   future TypeScript kernel would have to work with.
3. **Shuffling is Fisher-Yates descending**: for `i` from `n-1` down to `1`, swap `i` with
   `nextInt(0, i)`.

Reconstruction from `(seed, position)` uses the engine's `jump()`, which advances the stream
by N draws in O(log N) — so rebuilding a state 4,000 draws in costs about twelve operations
rather than four thousand.

## Consequences

**Good.** The arithmetic is C, tested by PHP's own suite, and cannot be got subtly wrong here.
Reconstruction is cheap enough that the state does not have to carry engine internals — just
two integers, which keeps `GameState` pure JSON as ADR-0005 requires. The three properties
above give a second implementation something exact to match.

**The cost.** PHP 8.2 or newer, and a hard dependency on `ext-random`. Both are already
required. Bounded draws throw away 32 bits of every 64-bit draw, which is a rounding error
next to the cost of getting it wrong.

**What would make us revisit it.** A second implementation finding `PcgOneseq128XslRr64`
impractical to reproduce — in which case the answer is to change the engine, once, and
re-bless every fixture, not to reimplement this one by hand.
