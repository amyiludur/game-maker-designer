<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * One physical copy of a card in a match.
 *
 * A card (`core-012`) is a design object; an instance is a copy of it on the table, with
 * its own identity, counters, attachments and facing. Two copies of the same card are two
 * instances sharing one definition, and the kernel never mutates definitions.
 *
 * Note what is absent: attack, health, traits, types. Derived characteristics are never
 * stored (ADR-0004) — they are computed from the printed values plus the active modifiers,
 * every time, so a buff can never leave a stale number behind.
 */
final readonly class Instance
{
    /**
     * @param  array<string, int>  $counters
     * @param  list<string>  $attachments
     * @param  list<string>  $revealedTo   side ids that have seen this card regardless of zone visibility
     * @param  array<string, int>  $usedLimits  ability id + scope => uses, for `limit` enforcement
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $owner,
        public string $controller,
        public string $zone,
        public string $face = 'front',
        public bool $exhausted = false,
        public bool $faceDown = false,
        public array $counters = [],
        public ?string $attachedTo = null,
        public array $attachments = [],
        public int $enteredOnRound = 0,
        public array $revealedTo = [],
        public array $usedLimits = [],
    ) {}

    public function counter(string $counter): int
    {
        return $this->counters[$counter] ?? 0;
    }

    public function isAttached(): bool
    {
        return $this->attachedTo !== null;
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['code'] ?? $this->code,
            $changes['owner'] ?? $this->owner,
            $changes['controller'] ?? $this->controller,
            $changes['zone'] ?? $this->zone,
            $changes['face'] ?? $this->face,
            $changes['exhausted'] ?? $this->exhausted,
            $changes['faceDown'] ?? $this->faceDown,
            $changes['counters'] ?? $this->counters,
            array_key_exists('attachedTo', $changes) ? $changes['attachedTo'] : $this->attachedTo,
            $changes['attachments'] ?? $this->attachments,
            $changes['enteredOnRound'] ?? $this->enteredOnRound,
            $changes['revealedTo'] ?? $this->revealedTo,
            $changes['usedLimits'] ?? $this->usedLimits,
        );
    }
}
