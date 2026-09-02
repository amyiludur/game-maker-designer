<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

/**
 * The inputs a match is built from.
 *
 * A match is pinned to exactly this: a system version, a deck per seat, and a seed. That
 * triple is what makes a replay from three months ago still reproduce — the kernel is
 * rebuilt from the same inputs rather than from whatever the cards say today.
 */
final readonly class MatchSetup
{
    /**
     * @param  list<SeatSetup>  $seats
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public array $seats,
        public int $seed,
        public array $config = [],
    ) {}

    public function seatCount(): int
    {
        return count($this->seats);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
