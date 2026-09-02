<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Exhaust every card in a zone. */
final class ExhaustAllOp implements Op
{
    public function id(): string
    {
        return 'exhaust_all';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('zone', 'string')->optional('player', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $key = $context->zoneKey($context->side($node['player'] ?? null), (string) $node['zone']);

        foreach ($context->draft->zone($key) as $card) {
            if ($context->draft->instance($card)->exhausted) {
                continue;
            }
            $context->draft->mutateInstance($card, ['exhausted' => true], widensBoard: false);
            $context->emit('card.exhausted', ['card' => $card], $card);
        }
    }
}
