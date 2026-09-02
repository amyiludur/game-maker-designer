<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/**
 * Where cards live, and who can see them.
 *
 * `visibility` is what drives redaction: a zone nobody can see has its contents replaced
 * with placeholders before the state ever leaves the server, so a client cannot cheat by
 * reading its own memory (ADR-0002).
 */
final readonly class ZoneDefinition
{
    public const SCOPE_PLAYER = 'player';
    public const SCOPE_SHARED = 'shared';
    public const SCOPE_ADVERSARY = 'adversary';

    public const VISIBILITY_NONE = 'none';
    public const VISIBILITY_OWNER = 'owner';
    public const VISIBILITY_CONTROLLER = 'controller';
    public const VISIBILITY_PUBLIC = 'public';

    public function __construct(
        public string $id,
        public string $name,
        public string $scope = self::SCOPE_PLAYER,
        public ?string $side = null,
        public string $visibility = self::VISIBILITY_PUBLIC,
        public bool $ordered = false,
        public bool $faceDown = false,
        public bool $supportsAttachments = false,
        public ?int $maxSize = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['id'],
            (string) ($raw['name'] ?? $raw['id']),
            (string) ($raw['scope'] ?? self::SCOPE_PLAYER),
            $raw['side'] ?? null,
            (string) ($raw['visibility'] ?? self::VISIBILITY_PUBLIC),
            (bool) ($raw['ordered'] ?? false),
            (bool) ($raw['faceDown'] ?? false),
            (bool) ($raw['supportsAttachments'] ?? false),
            isset($raw['maxSize']) ? (int) $raw['maxSize'] : null,
        );
    }

    public function isShared(): bool
    {
        return $this->scope === self::SCOPE_SHARED;
    }

    public function isPerSide(): bool
    {
        return ! $this->isShared();
    }
}
