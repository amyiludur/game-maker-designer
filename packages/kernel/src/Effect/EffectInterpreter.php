<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

use Gmd\Kernel\Event\EventBus;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\System\SystemDocument;

/**
 * Runs effect scripts, one op at a time, with an explicit program counter.
 *
 * The obvious implementation is a recursive walk, or a PHP generator that yields when it
 * needs a decision. Both are ruled out by the runtime: doc 08 parks a mid-effect state in
 * Redis and may resume it in a different worker, and neither a call stack nor a generator
 * can be serialised. So the "where am I" lives in the state instead — a program reference,
 * a path into it and a counter — and an op that needs an answer simply does not advance
 * until it has one.
 *
 * The cost is a small discipline: the handful of ops that ask questions have to be
 * idempotent up to the point where they ask. The payoff is that undo-by-replay, crash
 * recovery, and hashing the stack all come for free.
 */
final class EffectInterpreter
{
    public function __construct(
        private readonly OpRegistry $ops,
        private readonly EventBus $events,
        private readonly Runtime $runtime,
    ) {}

    /**
     * Advance the top of the stack by one op.
     *
     * @return bool whether anything was done — false when the stack is empty or parked
     */
    public function step(Draft $draft, SystemDocument $system): bool
    {
        $stack = $draft->stack();
        if ($stack === []) {
            return false;
        }

        $item = $stack[count($stack) - 1];
        if ($item->awaiting !== null) {
            return false;
        }

        if ($item->frames === []) {
            $draft->popStack();

            return true;
        }

        $frame = $item->top();
        $nodes = $system->programs->opsAt($frame->program, $frame->path);

        if ($frame->pc >= count($nodes)) {
            $this->finishFrame($draft, $item, $frame, $nodes);

            return true;
        }

        $node = $nodes[$frame->pc];
        if (! is_array($node) || ! isset($node['op'])) {
            // A malformed node is skipped rather than fatal: the linter is where authoring
            // mistakes get reported, and a fuzz run should not die on one.
            $draft->replaceTopOfStack($this->advance($item, $frame));

            return true;
        }

        $this->run($draft, $system, $item, $frame, $node);

        return true;
    }

    /** @param array<string, mixed> $node */
    private function run(Draft $draft, SystemDocument $system, StackItem $item, StackFrame $frame, array $node): void
    {
        $op = $this->ops->get((string) $node['op']);
        $op->params()->validate($op->id(), $node);

        $context = new OpContext(
            $draft,
            $system,
            $this->runtime,
            $this->events,
            $item,
            $frame,
            (new Bindings($item->bindings))->withAll($frame->vars),
        );

        $op->execute($node, $context);

        $control = $context->control();

        if ($control === null) {
            $draft->replaceTopOfStack($this->advance($item, $frame));

            return;
        }

        match ($control->kind) {
            ControlFlow::AWAIT => $this->park($draft, $item, $control->choice),
            ControlFlow::DESCEND => $this->descend($draft, $item, $frame, $control),
            ControlFlow::INVOKE => $this->invoke($draft, $item, $frame, $control),
            ControlFlow::BIND => $draft->replaceTopOfStack($this->advance($item, $frame)->with([
                'bindings' => [...$item->bindings, ...$control->values],
            ])),
            ControlFlow::FIZZLE => $draft->popStack(),
            ControlFlow::END_STEP => $this->endScope($draft, '__endStep'),
            ControlFlow::END_PHASE => $this->endScope($draft, '__endPhase'),
            default => $draft->replaceTopOfStack($this->advance($item, $frame)),
        };
    }

    /**
     * A frame has run out of ops: either loop round again, or pop it.
     *
     * @param  list<mixed>  $nodes
     */
    private function finishFrame(Draft $draft, StackItem $item, StackFrame $frame, array $nodes): void
    {
        if ($frame->items !== null && $frame->index + 1 < count($frame->items)) {
            $index = $frame->index + 1;
            $frames = $item->frames;
            $frames[count($frames) - 1] = $frame->with([
                'pc' => 0,
                'index' => $index,
                'vars' => [...$frame->vars, ...$this->loopBindings($frame, $index)],
            ]);
            $draft->replaceTopOfStack($item->with(['frames' => $frames]));

            return;
        }

        $frames = $item->frames;
        array_pop($frames);

        if ($frames === []) {
            $draft->popStack();

            return;
        }

        $draft->replaceTopOfStack($item->with(['frames' => $frames]));
    }

    /** @return array<string, mixed> */
    private function loopBindings(StackFrame $frame, int $index): array
    {
        $value = $frame->items[$index] ?? null;
        $name = (string) ($frame->vars['__loopVar'] ?? 'each');

        return [$name => $value];
    }

    private function advance(StackItem $item, StackFrame $frame): StackItem
    {
        $frames = $item->frames;
        $frames[count($frames) - 1] = $frame->advanced();

        return $item->with(['frames' => $frames]);
    }

    /**
     * Descend into a nested op list, having first advanced past the op that asked.
     *
     * Advancing first is what makes the parent resume *after* the loop rather than
     * re-entering it forever.
     */
    private function descend(Draft $draft, StackItem $item, StackFrame $frame, ControlFlow $control): void
    {
        $parent = $this->advance($item, $frame);
        $draft->replaceTopOfStack($parent->with([
            'frames' => [...$parent->frames, $control->frame],
        ]));
    }

    private function park(Draft $draft, StackItem $item, ?\Gmd\Kernel\Contract\PendingChoice $choice): void
    {
        if ($choice === null) {
            return;
        }

        // The program counter deliberately does not advance: when the answer arrives the
        // same op runs again and finds it. That is what makes the asking ops idempotent up
        // to the question.
        $draft->setPendingChoice($choice);
        $draft->replaceTopOfStack($item->with(['awaiting' => $choice->id]));
    }

    private function invoke(Draft $draft, StackItem $item, StackFrame $frame, ControlFlow $control): void
    {
        $draft->replaceTopOfStack($this->advance($item, $frame));
        $draft->pushStack(new StackItem(
            id: $draft->nextId('stack', 's'),
            kind: $control->itemKind,
            controller: (string) $control->controller,
            frames: [new StackFrame($control->program ?? $frame->program)],
            bindings: $control->bindings?->all() ?? [],
            depth: $item->depth + 1,
        ));
    }

    /**
     * `end_step` and `end_phase` stop the current effect and tell the phase machine.
     *
     * The remaining frames are abandoned rather than run: an effect that says "end the
     * phase" means now, not after the rest of its script.
     */
    private function endScope(Draft $draft, string $flag): void
    {
        $draft->setVar($flag, true);
        $draft->popStack();
    }
}
