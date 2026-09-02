<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/**
 * How the game ends.
 *
 * The outcome supports both traditions: `winner`/`loser` for a duel, and `allWin`,
 * `allLose` or `eliminate` for a cooperative table (doc 16 §6). Swapping one field turns
 * "if any hero falls, everyone loses" into "a defeated investigator is out and the others
 * play on".
 */
final readonly class WinConditionDefinition
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $outcome
     */
    public function __construct(
        public string $id,
        public mixed $check,
        public array $outcome,
        public array $scope = [],
        public ?string $trigger = null,
        public ?string $text = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            $raw['check'] ?? null,
            $raw['outcome'] ?? [],
            $raw['scope'] ?? [],
            $raw['trigger'] ?? null,
            $raw['text'] ?? null,
        );
    }

    public function scopesPlayers(): bool
    {
        return isset($this->scope['players']);
    }

    /** Conditions gated on a named event only fire in response to it, not on every sweep. */
    public function isEventGated(): bool
    {
        return $this->trigger !== null;
    }
}
