<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;
use Gmd\Kernel\State\ModifierRecord;

/**
 * Clear out modifiers whose time is up.
 *
 * A cleanup step calls this with `duration: "round"`. Nothing else has to remember that a
 * buff was temporary — the duration is written on the modifier, and the game's own round
 * structure decides when to sweep.
 */
final class ExpireModifiersOp implements Op
{
    public function id(): string
    {
        return 'expire_modifiers';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('duration', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $duration = (string) $node['duration'];
        $removed = $context->draft->removeModifiers(
            static fn (ModifierRecord $m): bool => $m->duration === $duration,
        );

        if ($removed > 0) {
            $context->emit('modifiers.expired', ['duration' => $duration, 'count' => $removed]);
        }
    }
}
