<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Discard cards from a hand.
 *
 * With a `chooser` this asks; without one it takes from the top of the hand, which is what
 * a random or forced discard means when nobody gets to pick.
 */
final class DiscardOp implements Op
{
    public function id(): string
    {
        return 'discard';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->optional('player', 'selector')
            ->optional('count', 'expression')
            ->optional('chooser', 'selector')
            ->optional('query', 'query')
            ->optional('card', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        if (isset($node['card'])) {
            $card = $context->card($node['card']);
            if ($card !== null) {
                CardMovement::move($context, $card, CardMovement::discardZone($context), $context->draft->instance($card)->owner);
            }

            return;
        }

        $side = $context->side($node['player'] ?? null);
        $count = $context->evaluateInt($node['count'] ?? null, 1);
        if ($count <= 0) {
            return;
        }

        $candidates = isset($node['query'])
            ? $context->cards($node['query'])
            : $context->draft->zone($context->zoneKey($side, 'hand'));

        if ($candidates === []) {
            return;
        }

        if (isset($node['chooser'])) {
            $chosen = $context->answer('discard');
            if ($chosen === null) {
                $context->await(new PendingChoice(
                    id: 'discard',
                    kind: PendingChoice::CHOOSE_CARDS,
                    side: $context->side($node['chooser']),
                    options: ['cards' => $candidates],
                    prompt: 'Choose ' . $count . ' card(s) to discard',
                    count: min($count, count($candidates)),
                    sourceInstance: $context->item->sourceInstance,
                    abilityId: $context->item->abilityId,
                ));

                return;
            }
            $candidates = array_values(array_intersect($candidates, (array) $chosen));
        }

        foreach (array_slice($candidates, 0, $count) as $card) {
            CardMovement::move($context, $card, CardMovement::discardZone($context), $context->draft->instance($card)->owner);
        }
    }
}
