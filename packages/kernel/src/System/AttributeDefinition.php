<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/**
 * One field on a card type.
 *
 * These are what make the platform multi-game: the engine knows "this type has an integer
 * called cost", never "cards have a cost". The same declarations generate the card
 * editor's form, the per-type JSON Schema, and the linter's checks.
 */
final readonly class AttributeDefinition
{
    /** @param list<string>|null $options */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public bool $required = false,
        public mixed $default = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?array $options = null,
        public ?string $vocabulary = null,
        public bool $perPlayer = false,
        public string|bool|null $showOnCard = null,
        public ?string $help = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            (string) $raw['name'],
            (string) $raw['type'],
            (bool) ($raw['required'] ?? false),
            $raw['default'] ?? null,
            $raw['min'] ?? null,
            $raw['max'] ?? null,
            $raw['options'] ?? null,
            $raw['vocabulary'] ?? null,
            (bool) ($raw['perPlayer'] ?? false),
            $raw['showOnCard'] ?? null,
            $raw['help'] ?? null,
        );
    }

    public function isNumeric(): bool
    {
        return $this->type === 'integer' || $this->type === 'decimal';
    }
}
