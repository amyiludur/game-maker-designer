<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Give a player resources.
 *
 * `mode: "set"` is what makes a Netrunner-style "you have N this round" economy work, and
 * `mode: "add"` an accumulating pool. The engine has no opinion about which a game should
 * use — that is what the system document is for.
 */
final class GainResourceOp implements Op
{
    public function id(): string
    {
        return 'gain_resource';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('resource', 'string')
            ->required('amount', 'expression')
            ->optional('player', 'selector')
            ->optional('mode', 'string', '"add" (default) or "set"');
    }

    public function execute(array $node, OpContext $context): void
    {
        $side = $context->side($node['player'] ?? null);
        $player = $context->draft->playerBySide($side);
        $resource = (string) $node['resource'];
        $amount = $context->evaluateInt($node['amount']);

        $definition = $context->system->resources[$resource] ?? null;
        $value = ($node['mode'] ?? 'add') === 'set' ? $amount : $player->resource($resource) + $amount;

        $value = max($definition?->min ?? 0, $value);
        if ($definition?->max !== null) {
            $value = min($definition->max, $value);
        }

        $context->draft->setPlayer($player->with([
            'resources' => [...$player->resources, $resource => $value],
        ]));
        $context->emit('resource.gained', ['player' => $side, 'resource' => $resource, 'amount' => $value - $player->resource($resource)]);
    }
}
