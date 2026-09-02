<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * A triggered ability that has fired but has not yet gone on the stack.
 *
 * Triggers wait in a queue until the current resolution finishes, then go on the stack in
 * APNAP order (doc 07). `queuedAt` preserves the order they fired in, which is the
 * tiebreak within one controller when the system uses `triggerOrdering: "declaration"`.
 */
final readonly class TriggerRecord
{
    /**
     * @param  array<string, mixed>  $bindings  including the $event payload that fired it
     */
    public function __construct(
        public string $id,
        public string $event,
        public string $controller,
        public ProgramRef $program,
        public array $bindings = [],
        public ?string $sourceInstance = null,
        public ?string $abilityId = null,
        public int $depth = 0,
        public int $queuedAt = 0,
    ) {}
}
