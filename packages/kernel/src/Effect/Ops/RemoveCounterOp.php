<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Take counters off a card. Never below zero. */
final class RemoveCounterOp implements Op
{
    public function id(): string
    {
        return 'remove_counter';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('card', 'selector')
            ->required('counter', 'string')
            ->optional('amount', 'expression');
    }

    public function execute(array $node, OpContext $context): void
    {
        $card = $context->card($node['card']);
        if ($card === null) {
            return;
        }

        $counter = (string) $node['counter'];
        $instance = $context->draft->instance($card);
        $present = $instance->counter($counter);
        $amount = min($present, max(0, $context->evaluateInt($node['amount'] ?? null, 1)));
        if ($amount === 0) {
            return;
        }

        $counters = $instance->counters;
        $counters[$counter] = $present - $amount;
        if ($counters[$counter] === 0) {
            unset($counters[$counter]);
        }

        $context->draft->mutateInstance($card, ['counters' => $counters]);
        $context->emit('counter.removed', ['card' => $card, 'counter' => $counter, 'amount' => $amount], $card);
    }
}
