<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Turn a card upright again. */
final class ReadyOp implements Op
{
    public function id(): string
    {
        return 'ready';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->optional('card', 'selector')->optional('query', 'query');
    }

    public function execute(array $node, OpContext $context): void
    {
        $cards = isset($node['query'])
            ? $context->cards($node['query'])
            : array_filter([$context->card($node['card'] ?? '$self')]);

        foreach ($cards as $card) {
            if (! $context->draft->instance($card)->exhausted) {
                continue;
            }
            $context->draft->mutateInstance($card, ['exhausted' => false], widensBoard: false);
            $context->emit('card.readied', ['card' => $card], $card);
        }
    }
}
