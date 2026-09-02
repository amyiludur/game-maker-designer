<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/**
 * A marker that sits on a card.
 *
 * Damage is a counter like any other; nothing about it is special-cased. What makes damage
 * lethal is a state check the game declares, which is why a game can have damage that
 * heals, damage that never kills, or no damage at all.
 */
final readonly class CounterDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $visual = null,
        public ?int $max = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            (string) ($raw['name'] ?? $raw['id']),
            $raw['visual'] ?? null,
            isset($raw['max']) ? (int) $raw['max'] : null,
        );
    }
}
