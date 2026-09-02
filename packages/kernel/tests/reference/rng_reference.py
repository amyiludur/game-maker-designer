#!/usr/bin/env python3
"""
Independent reference for the kernel's derived RNG operations.

PHP's core `random` extension provides PCG64 itself, so what needs checking is not the
generator but everything we build on top of it: how a raw stream becomes a bounded integer,
and how bounded integers become a shuffle. Those are the parts a second implementation of
the kernel (ADR-0002) would have to reproduce exactly, so they are pinned here in a
different language, from the written specification rather than from the PHP source.

Usage: rng_reference.py <path to a JSON file of raw low-32-bit draws>
"""
import json
import sys

TWO_32 = 1 << 32


class Stream:
    def __init__(self, draws):
        self.draws = draws
        self.position = 0

    def next32(self):
        value = self.draws[self.position]
        self.position += 1
        return value

    def next_int(self, lo, hi):
        if lo == hi:
            return lo
        rng = hi - lo + 1
        if rng == TWO_32:
            return lo + self.next32()
        floor = TWO_32 % rng
        while True:
            value = self.next32()
            if value >= floor:
                return lo + value % rng

    def shuffle(self, items):
        items = list(items)
        for i in range(len(items) - 1, 0, -1):
            j = self.next_int(0, i)
            if j != i:
                items[i], items[j] = items[j], items[i]
        return items


def main():
    draws = json.load(open(sys.argv[1]))
    out = {}

    s = Stream(draws)
    out["nextInt_0_5"] = [s.next_int(0, 5) for _ in range(10)]
    out["nextInt_0_5_position"] = s.position

    s = Stream(draws)
    out["nextInt_1_24"] = [s.next_int(1, 24) for _ in range(10)]
    out["nextInt_1_24_position"] = s.position

    s = Stream(draws)
    out["shuffle_25"] = s.shuffle(list(range(25)))
    out["shuffle_25_position"] = s.position

    s = Stream(draws)
    out["nextInt_same"] = [s.next_int(7, 7) for _ in range(3)]
    out["nextInt_same_position"] = s.position

    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
