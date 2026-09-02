<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** End the current step immediately, abandoning whatever this effect had left to do. */
final class EndStepOp implements Op
{
    public function id(): string
    {
        return 'end_step';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make();
    }

    public function execute(array $node, OpContext $context): void
    {
        $context->endStep();
    }
}
