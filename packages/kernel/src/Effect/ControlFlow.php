<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\State\ProgramRef;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;

/** What an op asked the interpreter to do next, if anything. */
final readonly class ControlFlow
{
    public const DESCEND = 'descend';
    public const INVOKE = 'invoke';
    public const AWAIT = 'await';
    public const BIND = 'bind';
    public const FIZZLE = 'fizzle';
    public const END_STEP = 'end_step';
    public const END_PHASE = 'end_phase';

    /** @param array<string, mixed> $values */
    private function __construct(
        public string $kind,
        public ?StackFrame $frame = null,
        public ?PendingChoice $choice = null,
        public ?ProgramRef $program = null,
        public ?string $controller = null,
        public ?Bindings $bindings = null,
        public string $itemKind = StackItem::KIND_ABILITY,
        public array $values = [],
    ) {}

    public static function descend(StackFrame $frame): self
    {
        return new self(self::DESCEND, frame: $frame);
    }

    public static function invoke(ProgramRef $program, string $controller, Bindings $bindings, string $itemKind): self
    {
        return new self(self::INVOKE, program: $program, controller: $controller, bindings: $bindings, itemKind: $itemKind);
    }

    public static function await(PendingChoice $choice): self
    {
        return new self(self::AWAIT, choice: $choice);
    }

    /**
     * Record a binding on the stack item, so it outlives the op that made it.
     *
     * A chosen target has to be visible to every op that follows in the same ability, which
     * frame-local variables are not.
     *
     * @param  array<string, mixed>  $values
     */
    public static function bind(array $values): self
    {
        return new self(self::BIND, values: $values);
    }

    /** Abandon this ability entirely: a required target had no legal choice. */
    public static function fizzle(): self
    {
        return new self(self::FIZZLE);
    }

    public static function endStep(): self
    {
        return new self(self::END_STEP);
    }

    public static function endPhase(): self
    {
        return new self(self::END_PHASE);
    }
}
