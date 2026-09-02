<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/** A per-player economy. Most games have exactly one. */
final readonly class ResourceDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public int $start = 0,
        public int $min = 0,
        public ?int $max = null,
        public bool $carryOver = false,
        public ?string $icon = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            (string) ($raw['name'] ?? $raw['id']),
            (int) ($raw['start'] ?? 0),
            (int) ($raw['min'] ?? 0),
            isset($raw['max']) ? (int) $raw['max'] : null,
            (bool) ($raw['carryOver'] ?? false),
            $raw['icon'] ?? null,
        );
    }
}
