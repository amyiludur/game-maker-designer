<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Remove damage. */
final class HealOp implements Op
{
    public function id(): string
    {
        return 'heal';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('target', 'selector')
            ->required('amount', 'expression')
            ->optional('counter', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $counter = (string) ($node['counter'] ?? 'damage');
        $amount = max(0, $context->evaluateInt($node['amount']));

        foreach ($context->cardList($node['target']) as $target) {
            $instance = $context->draft->instance($target);
            $healed = min($instance->counter($counter), $amount);
            if ($healed === 0) {
                continue;
            }

            $counters = $instance->counters;
            $counters[$counter] = $instance->counter($counter) - $healed;
            if ($counters[$counter] === 0) {
                unset($counters[$counter]);
            }

            $context->draft->mutateInstance($target, ['counters' => $counters]);
            $context->emit('counter.removed', ['card' => $target, 'counter' => $counter, 'amount' => $healed], $target);
        }
    }
}
