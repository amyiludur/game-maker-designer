<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Resolve the attacks declared this combat.
 *
 * A parameterised built-in rather than something a game composes from smaller ops, because
 * "each attacker and its blocker deal damage to each other" is fiddly to get right and
 * recurs in every game of this shape — which is exactly the test ADR-0003 sets for when a
 * primitive belongs in the DSL.
 *
 * Under `simultaneous_strike` every damage amount is read *before* any of it is applied, so
 * two creatures that would kill each other both die. Reading them one at a time would let
 * the first death reduce the second's damage, which is the classic way to get this wrong.
 */
final class ResolveCombatOp implements Op
{
    public function id(): string
    {
        return 'resolve_combat';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->optional('model', 'string', 'simultaneous_strike (default), attacker_first, no_combat')
            ->optional('damageAttr', 'string')
            ->optional('healthAttr', 'string')
            ->optional('damageCounter', 'string')
            ->optional('unblockedTarget', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        /** @var list<array{attacker: string, defender: string, blocker: ?string}> $attacks */
        $attacks = $context->draft->var(DeclareAttackOp::ATTACKS, []);
        $context->draft->setVar(DeclareAttackOp::ATTACKS, []);

        if ($attacks === []) {
            return;
        }

        $model = (string) ($node['model'] ?? 'simultaneous_strike');
        if ($model === 'no_combat') {
            return;
        }

        $damageAttr = (string) ($node['damageAttr'] ?? 'attack');
        $counter = (string) ($node['damageCounter'] ?? 'damage');

        // Every amount is read from the board as it stands before anyone strikes.
        $assignments = [];
        foreach ($attacks as $attack) {
            if (! $context->draft->hasInstance($attack['attacker'])) {
                continue;
            }
            $attackerDamage = $this->amount($context, $attack['attacker'], $damageAttr);

            if ($attack['blocker'] !== null && $context->draft->hasInstance($attack['blocker'])) {
                $assignments[] = [$attack['blocker'], $attackerDamage, $attack['attacker']];
                if ($model === 'simultaneous_strike') {
                    $assignments[] = [
                        $attack['attacker'],
                        $this->amount($context, $attack['blocker'], $damageAttr),
                        $attack['blocker'],
                    ];
                }

                continue;
            }

            $target = $this->unblockedTarget($node, $attack, $context);
            if ($target !== null) {
                $assignments[] = [$target, $attackerDamage, $attack['attacker']];
            }
        }

        foreach ($assignments as [$target, $amount, $source]) {
            $this->damage($context, $target, $amount, $counter, $source);
        }

        $context->emit('combat.resolved', ['attacks' => $attacks]);
    }

    private function amount(OpContext $context, string $instanceId, string $attribute): int
    {
        $value = $context->runtime->modifiers->attribute($context->pure(), $instanceId, $attribute);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{attacker: string, defender: string, blocker: ?string}  $attack
     */
    private function unblockedTarget(array $node, array $attack, OpContext $context): ?string
    {
        if (($node['unblockedTarget'] ?? 'defending_identity') !== 'defending_identity') {
            return null;
        }

        return $context->draft->playerBySide($attack['defender'])->identityInstance;
    }

    private function damage(OpContext $context, string $target, int $amount, string $counter, string $source): void
    {
        if ($amount <= 0 || ! $context->draft->hasInstance($target)) {
            return;
        }

        $event = $context->propose('damage.dealt', [
            'target' => $target,
            'amount' => $amount,
            'source' => $source,
        ], $source);

        if ($event === null) {
            $context->emit('damage.prevented', ['target' => $target, 'source' => $source], $source);

            return;
        }

        $dealt = max(0, (int) $event->get('amount', 0));
        if ($dealt === 0) {
            return;
        }

        $instance = $context->draft->instance($target);
        $context->draft->mutateInstance($target, [
            'counters' => [...$instance->counters, $counter => $instance->counter($counter) + $dealt],
        ]);
        $context->emit('damage.dealt', $event->payload, $source);
    }
}
