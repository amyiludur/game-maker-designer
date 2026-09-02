<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\State\ProgramRef;

/**
 * An ability, resolved and ready for the interpreter.
 *
 * "Compiled" here means the keyword indirection is gone. A card that says `Bolster 2` has,
 * by this point, a full triggered ability with `n` bound to 2 — so the interpreter never
 * has to know what a keyword is, and changing what Bolster means is still a one-line edit
 * in the system document rather than a card sweep.
 */
final readonly class CompiledAbility
{
    public const KIND_TRIGGERED = 'triggered';
    public const KIND_ACTIVATED = 'activated';
    public const KIND_STATIC = 'static';
    public const KIND_REPLACEMENT = 'replacement';
    public const KIND_CONSTANT = 'constant';

    /**
     * @param  array<string, mixed>|null  $trigger  {event, filter, window}
     * @param  list<array<string, mixed>>  $targets
     * @param  list<mixed>  $requirements
     * @param  array<string, int>  $limit
     * @param  array<string, mixed>  $params  keyword parameters bound for this card
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $ownerCode,
        public string $face = 'front',
        public string $speed = 'action',
        public ?array $trigger = null,
        public mixed $activeWhile = null,
        public array $targets = [],
        public array $requirements = [],
        public array $limit = [],
        public array $params = [],
        public ?string $text = null,
        public bool $hasCost = false,
        public bool $hasEffect = false,
        public ?string $keywordId = null,
    ) {}

    public function triggerEvent(): ?string
    {
        return $this->trigger['event'] ?? null;
    }

    public function triggerWindow(): string
    {
        return $this->trigger['window'] ?? 'after';
    }

    public function isReplacement(): bool
    {
        return $this->kind === self::KIND_REPLACEMENT || $this->triggerWindow() === 'instead';
    }

    public function isContinuous(): bool
    {
        return $this->kind === self::KIND_STATIC || $this->kind === self::KIND_CONSTANT;
    }

    /**
     * What resolving this ability runs.
     *
     * An ability with targets gets a compiled prelude that chooses them, so target
     * selection goes through the same resumable machinery as everything else rather than
     * being special-cased in the trigger queue.
     */
    public function effectProgram(): ProgramRef
    {
        return $this->program($this->targets === [] ? 'effect' : 'resolve');
    }

    /** The effect body alone, without target selection. Read by the modifier engine. */
    public function bodyProgram(): ProgramRef
    {
        return $this->program('effect');
    }

    public function costProgram(): ProgramRef
    {
        return $this->program('cost');
    }

    private function program(string $part): ProgramRef
    {
        return $this->keywordId !== null
            ? new ProgramRef("keyword:{$this->keywordId}#{$this->id}.{$part}")
            : new ProgramRef("card:{$this->ownerCode}@{$this->face}#{$this->id}.{$part}");
    }
}
