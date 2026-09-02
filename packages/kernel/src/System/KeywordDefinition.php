<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/**
 * A named, reusable ability.
 *
 * Defined once, referenced by many cards, so changing what "Swift" means is a one-line
 * edit. A keyword grants one of three things: a permission or restriction (which lands at
 * layer 9 of the modifier stack), or a whole ability body.
 */
final readonly class KeywordDefinition
{
    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  list<array<string, mixed>>  $grants
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $reminder = null,
        public array $parameters = [],
        public array $grants = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            (string) ($raw['name'] ?? $raw['id']),
            $raw['reminder'] ?? null,
            $raw['parameters'] ?? [],
            $raw['grants'] ?? [],
        );
    }

    /** @return list<string> parameter ids, in declaration order */
    public function parameterIds(): array
    {
        return array_map(static fn (array $p): string => (string) $p['id'], $this->parameters);
    }
}
