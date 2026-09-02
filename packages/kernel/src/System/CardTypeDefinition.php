<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/** A kind of card, and the attribute schema every card of that kind is authored against. */
final readonly class CardTypeDefinition
{
    /**
     * @param  list<string>  $playableTo  zone ids this type can be played into
     * @param  array<string, AttributeDefinition>  $attributes  keyed by attribute id
     * @param  list<string>  $modifiableAttributes  which attributes continuous effects may change
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $playableTo = [],
        public array $attributes = [],
        public array $modifiableAttributes = [],
        public ?int $abilitySlots = null,
        public bool $unique = false,
        public bool $isIdentity = false,
        public bool $doubleSided = false,
        public string $controlledBy = 'player',
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $attributes = [];
        foreach ($raw['attributes'] ?? [] as $attribute) {
            $definition = AttributeDefinition::fromArray($attribute);
            $attributes[$definition->id] = $definition;
        }

        return new self(
            (string) $raw['id'],
            (string) $raw['name'],
            $raw['playableTo'] ?? [],
            $attributes,
            $raw['modifiableAttributes'] ?? [],
            isset($raw['abilitySlots']['max']) ? (int) $raw['abilitySlots']['max'] : null,
            (bool) ($raw['unique'] ?? false),
            (bool) ($raw['isIdentity'] ?? false),
            (bool) ($raw['doubleSided'] ?? false),
            (string) ($raw['controlledBy'] ?? 'player'),
        );
    }

    public function attribute(string $id): ?AttributeDefinition
    {
        return $this->attributes[$id] ?? null;
    }

    public function isModifiable(string $attributeId): bool
    {
        return in_array($attributeId, $this->modifiableAttributes, true);
    }

    public function isAdversaryControlled(): bool
    {
        return $this->controlledBy === 'adversary';
    }
}
