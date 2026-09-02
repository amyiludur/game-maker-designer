<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\State\ProgramRef;

/**
 * An engine-controlled side.
 *
 * The important thing an adversary is *not* is a bot. A bot receives a view and chooses
 * among legal actions; an adversary executes a fixed script written in the same effect DSL
 * as everything else. That is what makes a scenario reproducible from its seed, and what
 * means difficulty is data rather than an opponent's competence (doc 16 §2).
 */
final readonly class AdversaryDefinition
{
    /**
     * @param  list<string>  $zones  zone ids scoped to this adversary
     * @param  list<array{id: string, type: string, zone?: string, required?: bool}>  $anchors
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $zones = [],
        public array $anchors = [],
        public string $controlledBy = 'engine',
        public bool $hasActivation = false,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            (string) ($raw['name'] ?? $raw['id']),
            $raw['zones'] ?? [],
            $raw['anchors'] ?? [],
            (string) ($raw['controlledBy'] ?? 'engine'),
            isset($raw['activation']) && $raw['activation'] !== [],
        );
    }

    public function activationProgram(): ProgramRef
    {
        return ProgramRef::adversary($this->id);
    }

    /** An engine-controlled side is never asked to decide; its script already said what it does. */
    public function isEngineControlled(): bool
    {
        return $this->controlledBy === 'engine';
    }
}
