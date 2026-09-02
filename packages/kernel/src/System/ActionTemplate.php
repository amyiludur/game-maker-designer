<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\State\ProgramRef;

/**
 * A thing a player may do, and the only kind of thing legalActions() enumerates.
 *
 * Costs are declared here and are both checked and paid by the engine, which is what lets
 * the client grey out an unplayable card without knowing a single rule (doc 04).
 */
final readonly class ActionTemplate
{
    /**
     * @param  list<string>  $windows  qualified step ids, e.g. "action.main"
     * @param  list<array<string, mixed>>  $targets
     * @param  list<mixed>  $requirements
     * @param  list<string>  $emits  events fired on top of whatever the effect ops emit
     * @param  array<string, int>  $limit
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $windows = [],
        public array $targets = [],
        public array $requirements = [],
        public array $emits = [],
        public array $limit = [],
        public ?string $text = null,
        public bool $hasCost = false,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            (string) ($raw['name'] ?? $raw['id']),
            $raw['windows'] ?? [],
            $raw['targets'] ?? [],
            $raw['requirements'] ?? [],
            $raw['emits'] ?? [],
            $raw['limit'] ?? [],
            $raw['text'] ?? null,
            isset($raw['cost']) && $raw['cost'] !== [],
        );
    }

    public function effectProgram(): ProgramRef
    {
        return ProgramRef::action($this->id);
    }

    public function costProgram(): ProgramRef
    {
        return ProgramRef::action($this->id, 'cost');
    }

    public function isAvailableIn(string $qualifiedStep): bool
    {
        return $this->windows === [] || in_array($qualifiedStep, $this->windows, true);
    }
}
