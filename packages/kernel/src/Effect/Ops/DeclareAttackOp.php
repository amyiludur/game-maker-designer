<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Declare an attacker.
 *
 * The declaration is recorded in game vars under a namespaced key rather than in a
 * dedicated state field: GameState has no combat slot, and adding one would push a
 * two-player duel's vocabulary into a format that also has to describe cooperative games
 * with no combat step at all.
 *
 * The defender is worked out and stored now, so resolution does not have to re-derive it
 * from a board that may have changed in between.
 */
final class DeclareAttackOp implements Op
{
    public const ATTACKS = 'combat.attacks';

    public function id(): string
    {
        return 'declare_attack';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('attacker', 'selector')
            ->optional('defender', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $attacker = $context->card($node['attacker']);
        if ($attacker === null) {
            return;
        }

        $attackerSide = $context->draft->instance($attacker)->controller;
        $defender = isset($node['defender'])
            ? $context->side($node['defender'])
            : $this->defenderOf($attackerSide, $context);

        if ($defender === null) {
            return;
        }

        /** @var list<array{attacker: string, defender: string, blocker: ?string}> $attacks */
        $attacks = $context->draft->var(self::ATTACKS, []);
        $attacks[] = ['attacker' => $attacker, 'defender' => $defender, 'blocker' => null];
        $context->draft->setVar(self::ATTACKS, $attacks);

        $context->emit('attack.declared', [
            'attacker' => $attacker,
            'defender' => $defender,
            'target' => null,
        ], $attacker);
    }

    private function defenderOf(string $attackerSide, OpContext $context): ?string
    {
        $others = array_values(array_filter(
            $context->draft->playerSides(),
            static fn (string $side): bool => $side !== $attackerSide,
        ));

        // A duel has exactly one answer. More seats need the game to say who is being
        // attacked, via a `defender` target on the action.
        return count($others) === 1 ? $others[0] : null;
    }
}
