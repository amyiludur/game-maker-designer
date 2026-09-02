<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Branch on a condition. */
final class IfOp implements Op
{
    public function id(): string
    {
        return 'if';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('cond', 'expression')
            ->optional('then', 'effect')
            ->optional('else', 'effect');
    }

    public function execute(array $node, OpContext $context): void
    {
        $branch = $context->evaluateBool($node['cond']) ? 'then' : 'else';
        if (($node[$branch] ?? []) !== []) {
            $context->descend($context->childPath($branch));
        }
    }
}
