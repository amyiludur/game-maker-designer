<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\Event\EventBus;
use Gmd\Kernel\Event\GameEvent;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\ProgramRef;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\System\SystemDocument;

/**
 * Everything one op gets to work with, and the only way it can affect what happens next.
 *
 * An op never manipulates the stack directly. It asks — descend into this loop, wait for
 * that choice, end this step — and the interpreter carries it out. Keeping control flow
 * declarative is what makes an interrupted effect resumable: there is no PHP call stack to
 * reconstruct, only a program reference and a counter.
 */
final class OpContext
{
    private ?ControlFlow $control = null;

    public function __construct(
        public readonly Draft $draft,
        public readonly SystemDocument $system,
        public readonly Runtime $runtime,
        public readonly EventBus $events,
        public readonly StackItem $item,
        public readonly StackFrame $frame,
        public readonly Bindings $bindings,
    ) {}

    // ------------------------------------------------------------------ evaluation

    public function eval(): EvalContext
    {
        return new EvalContext($this->draft, $this->system, $this->runtime, $this->bindings, $this->draft->rng());
    }

    /** A context that may not consume randomness, for reads that must be repeatable. */
    public function pure(): EvalContext
    {
        return $this->eval()->pure();
    }

    public function evaluate(mixed $expression): mixed
    {
        return $this->eval()->evaluate($expression);
    }

    public function evaluateInt(mixed $expression, int $default = 0): int
    {
        return $expression === null ? $default : $this->eval()->evaluateInt($expression);
    }

    public function evaluateBool(mixed $expression): bool
    {
        return $this->eval()->evaluateBool($expression);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    public function cards(array $query): array
    {
        return $this->runtime->queries->cards($query, $this->pure());
    }

    /** One instance id from a selector or expression, or null when it names nothing. */
    public function card(mixed $expression): ?string
    {
        $value = $this->evaluate($expression);
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $this->draft->hasInstance($value) ? $value : null;
    }

    /** @return list<string> */
    public function cardList(mixed $expression): array
    {
        $value = $this->evaluate($expression);
        $ids = array_map(strval(...), is_array($value) ? $value : array_filter([$value]));

        return array_values(array_filter($ids, $this->draft->hasInstance(...)));
    }

    /** A side id from a selector, defaulting to whoever controls this effect. */
    public function side(mixed $expression, ?string $default = null): string
    {
        if ($expression === null) {
            return $default ?? $this->item->controller;
        }
        $value = $this->evaluate($expression);
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) ? $value : ($default ?? $this->item->controller);
    }

    /** @return list<string> */
    public function sides(mixed $expression): array
    {
        if ($expression === null) {
            return [$this->item->controller];
        }
        $value = $this->evaluate($expression);

        return array_values(array_map(strval(...), is_array($value) ? $value : [$value]));
    }

    public function zoneKey(string $side, string $zoneId): string
    {
        return $this->system->qualifiedZone($side, $zoneId);
    }

    // ------------------------------------------------------------------ events

    /** @param array<string, mixed> $payload */
    public function emit(string $type, array $payload = [], ?string $source = null): void
    {
        $this->events->announce(
            new GameEvent($type, $payload, $source ?? $this->item->sourceInstance),
            $this,
        );
    }

    /**
     * Offer an event to replacement effects before it happens.
     *
     * @param  array<string, mixed>  $payload
     */
    public function propose(string $type, array $payload = [], ?string $source = null): ?GameEvent
    {
        return $this->events->propose(
            new GameEvent($type, $payload, $source ?? $this->item->sourceInstance),
            $this,
        );
    }

    // ------------------------------------------------------------------ control flow

    /**
     * The path to a nested op list inside the node currently running.
     *
     * `$context->childPath('do')` addresses this op's `do` block, which is how a loop body
     * is named without copying it.
     *
     * @return list<string>
     */
    public function childPath(string ...$keys): array
    {
        return [...$this->frame->path, (string) $this->frame->pc, ...$keys];
    }

    /**
     * Run a nested op list — a loop body, an if branch — and come back here afterwards.
     *
     * The child inherits what the parent had bound, so a loop variable survives being read
     * one level further in: `for_each_player` with an `if` inside it is a branch on that
     * player, and without inheritance `$player` would simply vanish at the brace.
     *
     * @param  list<string>  $path
     * @param  array<string, mixed>  $vars
     * @param  list<mixed>|null  $items  when set, the frame repeats once per item
     */
    public function descend(array $path, array $vars = [], ?array $items = null, ?ProgramRef $program = null): void
    {
        $this->control = ControlFlow::descend(new StackFrame(
            $program ?? $this->frame->program,
            $path,
            0,
            $items,
            0,
            [...$this->frame->vars, ...$vars],
        ));
    }

    /** Start an independent effect script, as its own stack item resolved before this one continues. */
    public function invoke(ProgramRef $program, string $controller, Bindings $bindings, string $kind = StackItem::KIND_ABILITY): void
    {
        $this->control = ControlFlow::invoke($program, $controller, $bindings, $kind);
    }

    /**
     * Record a binding for the rest of this ability.
     *
     * @param  array<string, mixed>  $values
     */
    public function bind(array $values): void
    {
        $this->control = ControlFlow::bind($values);
    }

    /**
     * Give up on this ability.
     *
     * A required target with no legal choice means the ability does nothing at all — it
     * does not resolve partially (doc 06, "Targets").
     */
    public function fizzle(): void
    {
        $this->control = ControlFlow::fizzle();
    }

    /** Park this effect until a player answers. */
    public function await(PendingChoice $choice): void
    {
        $this->control = ControlFlow::await($choice);
    }

    /** The answer to a choice this op previously awaited, or null if it has not been given. */
    public function answer(string $choiceId): mixed
    {
        return $this->bindings->get('choice.' . $choiceId);
    }

    public function endStep(): void
    {
        $this->control = ControlFlow::endStep();
    }

    public function endPhase(): void
    {
        $this->control = ControlFlow::endPhase();
    }

    public function control(): ?ControlFlow
    {
        return $this->control;
    }

    // ------------------------------------------------------------------ helpers

    public function seatOf(string $side): int
    {
        return Side::seatOrFail($side);
    }

    public function requireCard(mixed $expression, string $op): string
    {
        return $this->card($expression)
            ?? throw BadDocument::because("op \"{$op}\" names a card that is not in play");
    }
}
