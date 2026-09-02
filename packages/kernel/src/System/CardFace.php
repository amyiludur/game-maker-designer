<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/**
 * One side of a card.
 *
 * A flat card has a single "front"; a hero that flips to an alter-ego, or a villain that
 * advances a stage, has two. Everything that can differ between faces lives here, which is
 * why flipping is a layer-0 change and everything above it simply recomputes.
 */
final readonly class CardFace
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{id: string, params?: array<string, mixed>}>  $keywords
     * @param  list<CompiledAbility>  $abilities
     * @param  array<string, bool>  $permissions  granted at layer 9 by keywords
     * @param  array<string, bool>  $restrictions
     */
    public function __construct(
        public string $face,
        public string $name,
        public string $type,
        public array $attributes = [],
        public array $keywords = [],
        public array $abilities = [],
        public array $permissions = [],
        public array $restrictions = [],
        public ?string $text = null,
    ) {}

    public function attribute(string $id): mixed
    {
        return $this->attributes[$id] ?? null;
    }

    /** @return list<string> */
    public function traits(): array
    {
        $traits = $this->attributes['traits'] ?? [];

        return is_array($traits) ? array_values($traits) : [];
    }

    public function hasKeyword(string $id): bool
    {
        foreach ($this->keywords as $keyword) {
            if ($keyword['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
