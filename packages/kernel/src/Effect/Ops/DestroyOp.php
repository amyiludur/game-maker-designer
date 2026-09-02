<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Destroy a card: it goes to its owner's discard.
 *
 * The event is proposed before the card moves, so a "if this would be destroyed, ..."
 * replacement gets its say while the card is still on the table.
 */
final class DestroyOp implements Op
{
    public function id(): string
    {
        return 'destroy';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('card', 'selector')->optional('source', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $card = $context->card($node['card']);
        if ($card === null) {
            return;
        }

        $instance = $context->draft->instance($card);
        $event = $context->propose('card.destroyed', [
            'card' => $card,
            'from' => $instance->zone,
            'source' => isset($node['source']) ? $context->card($node['source']) : $context->item->sourceInstance,
        ]);

        if ($event === null) {
            return;
        }

        CardMovement::move($context, $card, CardMovement::discardZone($context), $instance->owner);
        $context->emit('card.destroyed', $event->payload, $card);
    }
}
