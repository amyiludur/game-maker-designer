<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Diagnostics\CostUnpayable;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Spend resources.
 *
 * Refusing loudly when the player cannot afford it is what makes cost checking honest: the
 * legality pass dry-runs the cost ops against a throwaway draft and catches this, so there
 * is one implementation of "can you pay for this" rather than two that disagree.
 */
final class PayResourceOp implements Op
{
    public function id(): string
    {
        return 'pay_resource';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('resource', 'string')
            ->required('amount', 'expression')
            ->optional('player', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $side = $context->side($node['player'] ?? null);
        $player = $context->draft->playerBySide($side);
        $resource = (string) $node['resource'];
        $amount = $context->evaluateInt($node['amount']);

        if ($player->resource($resource) < $amount) {
            throw CostUnpayable::because(
                "{$side} cannot pay {$amount} {$resource}: they have " . $player->resource($resource),
                ['player' => $side, 'resource' => $resource, 'amount' => $amount],
            );
        }

        $context->draft->setPlayer($player->with([
            'resources' => [...$player->resources, $resource => $player->resource($resource) - $amount],
        ]));
        $context->emit('resource.paid', ['player' => $side, 'resource' => $resource, 'amount' => $amount]);
    }
}
