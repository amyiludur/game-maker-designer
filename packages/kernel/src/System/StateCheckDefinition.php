<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\State\ProgramRef;

/**
 * A rule the engine enforces continuously — lethal damage, hand size, uniqueness.
 *
 * `scope` says what `$card` or `$player` binds to as the check sweeps the board. This is
 * MtG's state-based actions, generalised: the engine has no idea that damage kills things,
 * only that this game declares a check saying so.
 */
final readonly class StateCheckDefinition
{
    /** @param array<string, mixed> $scope */
    public function __construct(
        public string $id,
        public mixed $when,
        public array $scope = [],
        public ?string $phase = null,
        public ?string $step = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            $raw['when'] ?? null,
            $raw['scope'] ?? [],
            $raw['phase'] ?? null,
            $raw['step'] ?? null,
        );
    }

    public function thenProgram(): ProgramRef
    {
        return ProgramRef::stateCheck($this->id);
    }

    /** Checks scoped to players bind $player; everything else binds $card. */
    public function scopesPlayers(): bool
    {
        return isset($this->scope['players']);
    }

    public function appliesIn(string $phase, string $step): bool
    {
        return ($this->phase === null || $this->phase === $phase)
            && ($this->step === null || $this->step === $step);
    }
}
