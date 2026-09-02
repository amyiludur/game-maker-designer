<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\State\ProgramRef;

/**
 * One step of a phase: either an automatic script the engine runs, or a window in which
 * players act. Never both, and never neither.
 */
final readonly class StepDefinition
{
    public function __construct(
        public string $phaseId,
        public string $id,
        public string $name,
        public bool $hasAuto = false,
        public ?WindowDefinition $window = null,
        public bool $repeatPerPlayer = false,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(string $phaseId, array $raw): self
    {
        return new self(
            $phaseId,
            (string) $raw['id'],
            (string) ($raw['name'] ?? $raw['id']),
            isset($raw['auto']) && $raw['auto'] !== [],
            isset($raw['window']) ? WindowDefinition::fromArray($raw['window']) : null,
            (bool) ($raw['repeatPerPlayer'] ?? false),
        );
    }

    /** The id a window is addressed by from an action's `windows` list. */
    public function qualifiedId(): string
    {
        return $this->phaseId . '.' . $this->id;
    }

    public function autoProgram(): ProgramRef
    {
        return ProgramRef::system('step.' . $this->qualifiedId() . '.auto');
    }

    public function isWindow(): bool
    {
        return $this->window !== null;
    }
}
