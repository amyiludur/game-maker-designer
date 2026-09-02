<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

/**
 * A player's decision to do something.
 *
 * `actionId` is always the template id from the game system — `play_character` — and the
 * targets travel in `params`. That is what the replay format and the websocket payload both
 * speak, and it is the only shape that survives the point where target enumeration goes
 * lazy: past the combination budget there is no per-combination id to name.
 */
final readonly class Action
{
    public const PASS = 'pass';

    /** @param array<string, mixed> $params */
    public function __construct(
        public string $actionId,
        public string $side,
        public array $params = [],
    ) {}

    public static function pass(string $side): self
    {
        return new self(self::PASS, $side);
    }

    public function isPass(): bool
    {
        return $this->actionId === self::PASS;
    }

    public function seat(): int
    {
        return Side::seatOrFail($this->side);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'actionId' => $this->actionId,
            'seat' => Side::seatOf($this->side),
            'params' => $this->params === [] ? null : $this->params,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
