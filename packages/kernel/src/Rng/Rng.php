<?php

declare(strict_types=1);

namespace Gmd\Kernel\Rng;

/**
 * Every source of chance in the platform.
 *
 * The kernel never calls a global random function; it is handed one of these. The
 * generator is fully described by (seed, position), both of which live in GameState, so a
 * match is reproducible from its seed and action log alone (ADR-0005).
 */
interface Rng
{
    /** How many raw 64-bit draws have been consumed. This is what GameState stores. */
    public function position(): int;

    /** A uniform integer in [$min, $max], both inclusive. */
    public function nextInt(int $min, int $max): int;

    /**
     * Fisher-Yates, descending. Takes and returns a list so the caller cannot accidentally
     * shuffle in place and lose the original ordering the draw depended on.
     *
     * @template T
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array;

    /**
     * @template T
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed;
}
