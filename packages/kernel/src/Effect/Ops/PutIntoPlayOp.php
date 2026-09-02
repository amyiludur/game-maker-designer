<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Put a card onto the table.
 *
 * The destination comes from the card type's `playableTo`, not from a hard-coded "play"
 * zone, so a game whose creatures go to a battlefield and whose schemes go somewhere else
 * needs no engine change.
 */
final class PutIntoPlayOp implements Op
{
    public function id(): string
    {
        return 'put_into_play';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('card', 'selector')
            ->optional('controller', 'selector')
            ->optional('ready', 'boolean')
            ->optional('face', 'string')
            ->optional('zone', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $card = $context->card($node['card']);
        if ($card === null) {
            return;
        }

        $instance = $context->draft->instance($card);
        $face = isset($node['face']) ? (string) $node['face'] : $instance->face;
        $definition = $context->system->cards->get($instance->code)->face($face);
        $zoneId = (string) ($node['zone'] ?? $context->system->cardType($definition->type)->playableTo[0] ?? 'play');

        CardMovement::move(
            $context,
            $card,
            $zoneId,
            isset($node['controller']) ? $context->side($node['controller']) : null,
            'bottom',
            false,
            isset($node['face']) ? $face : null,
        );

        if (($node['ready'] ?? true) === false) {
            $context->draft->mutateInstance($card, ['exhausted' => true], widensBoard: false);
        }
    }
}
