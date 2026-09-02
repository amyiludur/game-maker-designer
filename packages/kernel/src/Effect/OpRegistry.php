<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

use Gmd\Kernel\Diagnostics\UnknownOp;

/**
 * Every verb the kernel implements.
 *
 * The registry is also the answer to "which ops does this kernel support?", which the
 * linter asks of every authored effect and the ability builder asks to populate its
 * palette. Adding a primitive to the DSL is registering one class here — the escape hatch
 * for anything else is the `custom` op, and ADR-0003 says that if more than about 5% of
 * cards reach for it, the right response is to add the missing primitive rather than write
 * more handlers.
 */
final class OpRegistry
{
    /** @var array<string, Op> */
    private array $ops = [];

    /** @param iterable<Op> $ops */
    public function __construct(iterable $ops = [])
    {
        foreach ($ops as $op) {
            $this->register($op);
        }
    }

    public function register(Op $op): self
    {
        $this->ops[$op->id()] = $op;

        return $this;
    }

    public function get(string $id): Op
    {
        return $this->ops[$id] ?? throw UnknownOp::because(
            "the kernel does not implement the op \"{$id}\"",
            ['op' => $id],
        );
    }

    public function has(string $id): bool
    {
        return isset($this->ops[$id]);
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = array_keys($this->ops);
        sort($ids);

        return $ids;
    }

    /** @return array<string, Op> */
    public function all(): array
    {
        return $this->ops;
    }

    /** Every op the kernel ships with. */
    public static function standard(): self
    {
        return new self([
            // flow
            new Ops\SequenceOp,
            new Ops\IfOp,
            new Ops\ForEachOp,
            new Ops\ForEachPlayerOp,
            new Ops\RepeatOp,
            new Ops\SetVarOp,
            new Ops\EmitOp,
            new Ops\SelectTargetOp,
            new Ops\EndStepOp,
            new Ops\EndPhaseOp,
            // cards and zones
            new Ops\DrawOp,
            new Ops\MoveCardOp,
            new Ops\PutIntoPlayOp,
            new Ops\DestroyOp,
            new Ops\DiscardOp,
            new Ops\ShuffleOp,
            new Ops\AttachOp,
            new Ops\DetachOp,
            new Ops\FlipCardOp,
            // card state
            new Ops\ExhaustOp,
            new Ops\ReadyOp,
            new Ops\ReadyAllOp,
            new Ops\ExhaustAllOp,
            new Ops\AddCounterOp,
            new Ops\RemoveCounterOp,
            new Ops\DealDamageOp,
            new Ops\HealOp,
            // economy
            new Ops\GainResourceOp,
            new Ops\PayResourceOp,
            // continuous effects
            new Ops\ModifyOp,
            new Ops\ExpireModifiersOp,
            // choices
            new Ops\ChooseCardsOp,
            new Ops\ChooseAndDiscardOp,
            // parameterised built-ins
            new Ops\ResolveCombatOp,
            new Ops\DeclareAttackOp,
            new Ops\DeclareBlockOp,
            new Ops\EnforceHandSizeOp,
            new Ops\SetFirstPlayerOp,
            // outcomes
            new Ops\WinGameOp,
            new Ops\LoseGameOp,
            new Ops\DrawGameOp,
        ]);
    }
}
