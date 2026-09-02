<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Shuffle a zone.
 *
 * The only place in the kernel that consumes randomness in bulk, and therefore the single
 * biggest reason a match is reproducible: the same seed and the same actions deal the same
 * cards.
 */
final class ShuffleOp implements Op
{
    public function id(): string
    {
        return 'shuffle';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('zone', 'string')->optional('player', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $key = $context->zoneKey($context->side($node['player'] ?? null), (string) $node['zone']);
        $context->draft->setZone($key, $context->draft->rng()->shuffle($context->draft->zone($key)));
        $context->emit('zone.shuffled', ['zone' => (string) $node['zone']]);
    }
}
