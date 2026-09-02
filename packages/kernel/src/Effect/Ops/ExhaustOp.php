<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Turn a card sideways. Used as a cost far more often than as an effect. */
final class ExhaustOp implements Op
{
    public function id(): string
    {
        return 'exhaust';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->optional('card', 'selector')->optional('query', 'query');
    }

    public function execute(array $node, OpContext $context): void
    {
        foreach ($this->targets($node, $context) as $card) {
            if ($context->draft->instance($card)->exhausted) {
                continue;
            }
            $context->draft->mutateInstance($card, ['exhausted' => true], widensBoard: false);
            $context->emit('card.exhausted', ['card' => $card], $card);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function targets(array $node, OpContext $context): array
    {
        if (isset($node['query'])) {
            return $context->cards($node['query']);
        }

        $card = $context->card($node['card'] ?? '$self');

        return $card === null ? [] : [$card];
    }
}
