<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

/** A step in which players may act, and the rule for when it ends. */
final readonly class WindowDefinition
{
    public const ACTIVE_PLAYER = 'active_player';
    public const ALTERNATING = 'alternating';
    public const SIMULTANEOUS = 'simultaneous';
    public const DEFENDING_PLAYER = 'defending_player';

    /** @param list<string>|null $actions action ids this window restricts play to */
    public function __construct(
        public string $type,
        public ?string $endOn = null,
        public ?array $actions = null,
        public bool $skipIfNoActions = true,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) $raw['type'],
            $raw['endOn'] ?? null,
            $raw['actions'] ?? null,
            (bool) ($raw['skipIfNoActions'] ?? true),
        );
    }

    public function allows(string $actionId): bool
    {
        return $this->actions === null || in_array($actionId, $this->actions, true);
    }

    public function endsOnConsecutivePasses(): bool
    {
        return ($this->endOn ?? self::defaultEndOn($this->type)) === 'consecutive_passes';
    }

    public static function defaultEndOn(string $type): string
    {
        return match ($type) {
            self::ALTERNATING => 'consecutive_passes',
            self::SIMULTANEOUS => 'all_submitted',
            default => 'single_action',
        };
    }
}
