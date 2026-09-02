<?php

declare(strict_types=1);

namespace Gmd\Kernel\Expr;

use Gmd\Kernel\Rng\Rng;
use Gmd\Kernel\State\StateView;
use Gmd\Kernel\System\SystemDocument;

/**
 * Everything an expression needs to mean something: a position, the rules, and what the
 * selectors currently point at.
 *
 * The RNG is deliberately optional. `random_int` is a legal expression op inside an effect,
 * where there is a draft to draw against — but a query filter or a legality requirement
 * must be a pure read, or `legalActions()` would mutate the state it is inspecting and stop
 * being cacheable. Contexts built for those pass null, and the evaluator refuses.
 */
final readonly class EvalContext
{
    public function __construct(
        public StateView $state,
        public SystemDocument $system,
        public Runtime $runtime,
        public Bindings $bindings = new Bindings,
        public ?Rng $rng = null,
    ) {}

    public function bind(string $name, mixed $value): self
    {
        return new self($this->state, $this->system, $this->runtime, $this->bindings->with($name, $value), $this->rng);
    }

    /** @param array<string, mixed> $values */
    public function bindAll(array $values): self
    {
        return new self($this->state, $this->system, $this->runtime, $this->bindings->withAll($values), $this->rng);
    }

    public function withState(StateView $state): self
    {
        return new self($state, $this->system, $this->runtime, $this->bindings, $this->rng);
    }

    /** A context that may not consume randomness, for legality and query filtering. */
    public function pure(): self
    {
        return new self($this->state, $this->system, $this->runtime, $this->bindings, null);
    }

    public function evaluate(mixed $expression): mixed
    {
        return $this->runtime->expressions->evaluate($expression, $this);
    }

    public function evaluateInt(mixed $expression): int
    {
        return $this->runtime->expressions->evaluateInt($expression, $this);
    }

    public function evaluateBool(mixed $expression): bool
    {
        return $this->runtime->expressions->evaluateBool($expression, $this);
    }
}
