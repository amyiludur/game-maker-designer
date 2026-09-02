<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Ready every card in a zone. The refresh phase of most games is one of these. */
final class ReadyAllOp implements Op
{
    public function id(): string
    {
        return 'ready_all';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('zone', 'string')->optional('player', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $key = $context->zoneKey($context->side($node['player'] ?? null), (string) $node['zone']);

        foreach ($context->draft->zone($key) as $card) {
            if (! $context->draft->instance($card)->exhausted) {
                continue;
            }
            $context->draft->mutateInstance($card, ['exhausted' => false], widensBoard: false);
            $context->emit('card.readied', ['card' => $card], $card);
        }
    }
}
