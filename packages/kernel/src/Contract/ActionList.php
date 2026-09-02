<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

/**
 * Everything one side may do right now.
 *
 * `truncated` says the target combinations ran past the enumeration budget and the client
 * should fetch targets lazily instead of expecting a complete list.
 */
final readonly class ActionList implements \Countable, \IteratorAggregate
{
    /** @param list<LegalAction> $actions */
    public function __construct(
        public array $actions = [],
        public bool $truncated = false,
    ) {}

    public function count(): int
    {
        return count($this->actions);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->actions);
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    public function find(Action $action): ?LegalAction
    {
        foreach ($this->actions as $legal) {
            if ($legal->matches($action)) {
                return $legal;
            }
        }

        return null;
    }

    /** @return list<string> distinct action template ids on offer */
    public function actionIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (LegalAction $a): string => $a->actionId,
            $this->actions,
        )));
    }
}
