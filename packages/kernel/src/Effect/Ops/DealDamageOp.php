<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Deal damage, as damage counters.
 *
 * The amount goes through the replacement window first, so "prevent 1 damage" and "damage
 * dealt to your hero is doubled" are ordinary replacement abilities rather than special
 * cases in the engine.
 */
final class DealDamageOp implements Op
{
    public function id(): string
    {
        return 'deal_damage';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('target', 'selector')
            ->required('amount', 'expression')
            ->optional('source', 'selector')
            ->optional('counter', 'string')
            ->optional('defendable', 'boolean');
    }

    public function execute(array $node, OpContext $context): void
    {
        $counter = (string) ($node['counter'] ?? 'damage');

        foreach ($context->cardList($node['target']) as $target) {
            $event = $context->propose('damage.dealt', [
                'target' => $target,
                'amount' => $context->evaluateInt($node['amount']),
                'source' => isset($node['source']) ? $context->card($node['source']) : $context->item->sourceInstance,
            ]);

            if ($event === null) {
                $context->emit('damage.prevented', ['target' => $target], $context->item->sourceInstance);

                continue;
            }

            $amount = max(0, (int) $event->get('amount', 0));
            if ($amount === 0) {
                continue;
            }

            $instance = $context->draft->instance($target);
            $context->draft->mutateInstance($target, [
                'counters' => [...$instance->counters, $counter => $instance->counter($counter) + $amount],
            ]);
            $context->emit('damage.dealt', $event->payload, $event->source);
        }
    }
}
