<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Remember a value in the game state, for a later step or a later round to read back. */
final class SetVarOp implements Op
{
    public function id(): string
    {
        return 'set_var';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('id', 'string')->required('value', 'expression');
    }

    public function execute(array $node, OpContext $context): void
    {
        $context->draft->setVar((string) $node['id'], $context->evaluate($node['value']));
    }
}
