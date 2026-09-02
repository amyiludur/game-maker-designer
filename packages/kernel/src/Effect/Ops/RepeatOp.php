<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Run a body a fixed number of times, binding `$each` to the iteration number. */
final class RepeatOp implements Op
{
    public function id(): string
    {
        return 'repeat';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('count', 'expression')->required('do', 'effect');
    }

    public function execute(array $node, OpContext $context): void
    {
        $count = max(0, $context->evaluateInt($node['count']));
        if ($count === 0) {
            return;
        }

        $context->descend(
            $context->childPath('do'),
            ['__loopVar' => 'each', 'each' => 1],
            range(1, $count),
        );
    }
}
