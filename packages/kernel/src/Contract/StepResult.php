<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

use Gmd\Kernel\State\EventRecord;
use Gmd\Kernel\State\GameState;

/**
 * What one call to the kernel produced: a new position, and what happened on the way.
 *
 * The event list is the animation script (doc 08). The client is told, in order, that a
 * card moved and then that damage was dealt; it never diffs two states to guess.
 */
final readonly class StepResult
{
    /** @param list<EventRecord> $events */
    public function __construct(
        public GameState $state,
        public array $events = [],
    ) {}

    /** @return list<string> */
    public function eventTypes(): array
    {
        return array_map(static fn (EventRecord $e): string => $e->type, $this->events);
    }
}
