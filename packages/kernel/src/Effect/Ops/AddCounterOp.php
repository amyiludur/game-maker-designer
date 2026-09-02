<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Put counters on a card.
 *
 * Damage is a counter like any other; nothing here knows it is lethal. What kills a card is
 * a state check the game declares, which is why a game can have damage that never kills or
 * no damage at all.
 */
final class AddCounterOp implements Op
{
    public function id(): string
    {
        return 'add_counter';
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
        $amount = $context->evaluateInt($node['amount'] ?? null, 1);
        if ($card === null || $amount <= 0) {
            return;
        }

        $counter = (string) $node['counter'];
        $instance = $context->draft->instance($card);
        $total = $instance->counter($counter) + $amount;

        $max = $context->system->counters[$counter]->max ?? null;
        if ($max !== null) {
            $total = min($total, $max);
        }

        $context->draft->mutateInstance($card, ['counters' => [...$instance->counters, $counter => $total]]);
        $context->emit('counter.added', ['card' => $card, 'counter' => $counter, 'amount' => $amount], $card);
    }
}
