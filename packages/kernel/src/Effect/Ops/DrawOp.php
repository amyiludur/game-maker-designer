<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Draw cards from the top of a deck.
 *
 * Running out matters: a draw from an empty deck emits `deck.exhausted`, which is what a
 * "deck out" win condition listens for. Without the event the game would simply stop
 * drawing and nobody would ever lose.
 */
final class DrawOp implements Op
{
    public function id(): string
    {
        return 'draw';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->optional('player', 'selector')
            ->optional('count', 'expression')
            ->optional('upTo', 'boolean', 'Draw as many as are available rather than failing.');
    }

    public function execute(array $node, OpContext $context): void
    {
        $side = $context->side($node['player'] ?? null);
        $count = $context->evaluateInt($node['count'] ?? null, 1);
        $deckKey = $context->zoneKey($side, 'deck');
        $handZone = 'hand';

        $drawn = [];
        for ($i = 0; $i < $count; $i++) {
            $deck = $context->draft->zone($deckKey);
            if ($deck === []) {
                $context->emit('deck.exhausted', ['player' => $side]);
                break;
            }
            $card = $deck[0];
            CardMovement::move($context, $card, $handZone, $side);
            $drawn[] = $card;
        }

        if ($drawn !== []) {
            $context->emit('cards.drawn', ['player' => $side, 'count' => count($drawn), 'cards' => $drawn]);
        }
    }
}
