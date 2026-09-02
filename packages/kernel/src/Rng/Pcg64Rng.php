<?php

declare(strict_types=1);

namespace Gmd\Kernel\Rng;

use Gmd\Kernel\Diagnostics\BadDocument;
use Random\Engine\PcgOneseq128XslRr64;

/**
 * PCG64 (oneseq 128-bit state, XSL-RR 64-bit output), as shipped in PHP's core `random`
 * extension.
 *
 * This is the only file in the kernel permitted to touch a random engine; the arch tests
 * enforce that. Three properties are specification, not implementation detail, because a
 * second implementation in another language would have to match them byte for byte to pass
 * the conformance suite (ADR-0002):
 *
 *  1. `position` counts raw engine draws and nothing else. In particular we do NOT use
 *     \Random\Randomizer, whose rejection loops consume an unspecified number of draws.
 *  2. A bounded draw uses only the low 32 bits of each draw, with modulo rejection
 *     (`min = 2^32 % range`; reject below it). Staying in 32 bits keeps every intermediate
 *     exactly representable, in PHP's signed ints and in a JavaScript Number alike.
 *  3. Shuffling is Fisher-Yates descending: for i from n-1 down to 1, swap i with
 *     nextInt(0, i).
 *
 * Reconstruction from (seed, position) is exact and cheap: the engine's jump() advances the
 * stream by N draws in O(log N).
 */
final class Pcg64Rng implements Rng
{
    private const TWO_32 = 0x100000000;

    private function __construct(
        private readonly PcgOneseq128XslRr64 $engine,
        private int $position,
    ) {}

    /** Build the stream for $seed, already advanced past $position draws. */
    public static function at(int $seed, int $position = 0): self
    {
        if ($position < 0) {
            throw BadDocument::because("rngPosition cannot be negative, got {$position}");
        }

        $engine = new PcgOneseq128XslRr64($seed);
        if ($position > 0) {
            $engine->jump($position);
        }

        return new self($engine, $position);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($min > $max) {
            throw BadDocument::because("empty random range [{$min}, {$max}]");
        }
        if ($min === $max) {
            return $min;
        }

        $range = $max - $min + 1;
        if ($range <= 0 || $range > self::TWO_32) {
            throw BadDocument::because("random range [{$min}, {$max}] exceeds 2^32 values");
        }

        if ($range === self::TWO_32) {
            return $min + $this->next32();
        }

        // Reject the values that would make the modulo non-uniform. With game-sized ranges
        // this rejects a vanishing fraction of draws, but it has to be here: a biased
        // shuffle is still deterministic, so no test would ever notice.
        $floor = self::TWO_32 % $range;
        do {
            $value = $this->next32();
        } while ($value < $floor);

        return $min + $value % $range;
    }

    public function shuffle(array $items): array
    {
        $count = count($items);
        for ($i = $count - 1; $i >= 1; $i--) {
            $j = $this->nextInt(0, $i);
            if ($j !== $i) {
                [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
            }
        }

        return $items;
    }

    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw BadDocument::because('cannot pick from an empty list');
        }

        return $items[$this->nextInt(0, count($items) - 1)];
    }

    /** One raw draw, narrowed to its low 32 bits. Always non-negative. */
    private function next32(): int
    {
        $this->position++;

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', substr($this->engine->generate(), 0, 4));

        return $unpacked[1];
    }
}
