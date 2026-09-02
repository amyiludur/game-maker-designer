/**
 * A seeded generator for the property tests.
 *
 * `Math.random` is deliberately not used: a property test that fails once in fifty runs and
 * cannot be reproduced is worse than no test at all. Same rule as the kernel's RNG, for the
 * same reason — a failing case has to be replayable from its seed.
 */
export class Rand {
  private state: number

  constructor(seed: number) {
    this.state = seed >>> 0
  }

  next(): number {
    this.state = (this.state + 0x6d2b79f5) >>> 0
    let t = this.state
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }

  int(maxExclusive: number): number {
    return Math.floor(this.next() * maxExclusive)
  }

  pick<T>(items: readonly T[]): T {
    return items[this.int(items.length)] as T
  }

  bool(): boolean {
    return this.next() < 0.5
  }
}
