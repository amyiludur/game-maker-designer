<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Make every player discard down to their hand size.
 *
 * Each player is asked in turn. The op does not advance while it is waiting for an answer,
 * so it runs again once one arrives, finds that player now within the limit, and moves on
 * to the next — which is how one op asks several questions without holding a call stack
 * open across a decision that might take a human ten minutes.
 *
 * The limit comes from the hand zone's `maxSize` unless the step overrides it, so a game
 * with no hand limit simply does not set one and this does nothing.
 */
final class EnforceHandSizeOp implements Op
{
    public function id(): string
    {
        return 'enforce_hand_size';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->optional('max', 'expression')->optional('zone', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $zoneId = (string) ($node['zone'] ?? 'hand');
        $max = isset($node['max'])
            ? $context->evaluateInt($node['max'])
            : $context->system->zone($zoneId)->maxSize;

        if ($max === null) {
            return;
        }

        foreach ($context->draft->players() as $player) {
            if (! $player->isPlaying()) {
                continue;
            }

            $side = $player->side();
            $hand = $context->draft->zone($context->zoneKey($side, $zoneId));
            $excess = count($hand) - $max;
            if ($excess <= 0) {
                continue;
            }

            $choiceId = 'discard_' . $side;
            $chosen = $context->answer($choiceId);

            if ($chosen === null) {
                $context->await(new PendingChoice(
                    id: $choiceId,
                    kind: PendingChoice::CHOOSE_CARDS,
                    side: $side,
                    options: ['cards' => $hand],
                    prompt: 'Discard down to ' . $max . ' cards: choose ' . $excess,
                    count: $excess,
                ));

                return;
            }

            foreach (array_slice(array_values(array_intersect($hand, (array) $chosen)), 0, $excess) as $card) {
                CardMovement::move($context, $card, CardMovement::discardZone($context), $player->side());
            }
        }
    }
}
