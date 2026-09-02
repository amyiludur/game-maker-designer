<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Run a list of ops in order. Useful as the body of a branch that needs several steps. */
final class SequenceOp implements Op
{
    public function id(): string
    {
        return 'sequence';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('do', 'effect', 'The ops to run, in order.');
    }

    public function execute(array $node, OpContext $context): void
    {
        $context->descend($context->childPath('do'));
    }
}
