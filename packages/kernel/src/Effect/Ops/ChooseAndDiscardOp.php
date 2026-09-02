<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Make a player discard cards of their own choosing.
 *
 * The common case is a hand-size limit at end of round: the player picks which cards to
 * lose, so this asks rather than taking.
 */
final class ChooseAndDiscardOp implements Op
{
    public function id(): string
    {
        return 'choose_and_discard';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('player', 'selector')
            ->required('count', 'expression')
            ->optional('zone', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $side = $context->side($node['player']);
        $count = $context->evaluateInt($node['count']);
        if ($count <= 0) {
            return;
        }

        $hand = $context->draft->zone($context->zoneKey($side, (string) ($node['zone'] ?? 'hand')));
        if ($hand === []) {
            return;
        }

        $count = min($count, count($hand));
        $chosen = $context->answer('discard');

        if ($chosen === null) {
            $context->await(new PendingChoice(
                id: 'discard',
                kind: PendingChoice::CHOOSE_CARDS,
                side: $side,
                options: ['cards' => $hand],
                prompt: 'Discard down to your hand size: choose ' . $count . ' card(s)',
                count: $count,
                sourceInstance: $context->item->sourceInstance,
                abilityId: $context->item->abilityId,
            ));

            return;
        }

        $selected = array_slice(array_values(array_intersect($hand, (array) $chosen)), 0, $count);
        foreach ($selected as $card) {
            CardMovement::move($context, $card, CardMovement::discardZone($context), $context->draft->instance($card)->owner);
        }
    }
}
