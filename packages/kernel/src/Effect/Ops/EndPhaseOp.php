<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** End the current phase immediately, skipping any steps it had left. */
final class EndPhaseOp implements Op
{
    public function id(): string
    {
        return 'end_phase';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make();
    }

    public function execute(array $node, OpContext $context): void
    {
        $context->endPhase();
    }
}
