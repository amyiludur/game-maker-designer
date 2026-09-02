<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/** A phase of the round, and its ordered steps. */
final readonly class PhaseDefinition
{
    /** @param list<StepDefinition> $steps */
    public function __construct(
        public string $id,
        public string $name,
        public array $steps,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $id = (string) $raw['id'];

        return new self(
            $id,
            (string) ($raw['name'] ?? $id),
            array_map(
                static fn (array $step): StepDefinition => StepDefinition::fromArray($id, $step),
                $raw['steps'],
            ),
        );
    }
}
