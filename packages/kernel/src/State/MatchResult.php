<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * How a match ended.
 *
 * `winners`/`losers` are lists of side ids rather than a single seat, because a
 * cooperative game's outcome is `allWin` or `allLose` for the whole table (doc 16 §6) and
 * a single `winner` integer cannot say that. The schema's scalar `winner` is derived at
 * the codec boundary for the two-player case.
 */
final readonly class MatchResult
{
    /**
     * @param  list<string>  $winners  side ids
     * @param  list<string>  $losers   side ids
     */
    public function __construct(
        public array $winners,
        public array $losers,
        public string $reason,
        public int $rounds,
        public bool $draw = false,
    ) {}
}
