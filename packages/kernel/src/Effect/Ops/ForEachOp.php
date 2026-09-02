<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Run a body once per card a query matches, binding `$each`.
 *
 * The matches are resolved once, up front. An effect that destroys every character must
 * not have its list change underneath it as the first destruction kills the second card.
 */
final class ForEachOp implements Op
{
    public function id(): string
    {
        return 'for_each';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->optional('query', 'query')
            ->optional('list', 'expression')
            ->required('do', 'effect')
            ->optional('as', 'string', 'Binding name for the current item; defaults to $each.');
    }

    public function execute(array $node, OpContext $context): void
    {
        $items = isset($node['query'])
            ? $context->cards($node['query'])
            : (array) $context->evaluate($node['list'] ?? []);

        if ($items === []) {
            return;
        }

        $name = (string) ($node['as'] ?? 'each');
        $context->descend(
            $context->childPath('do'),
            ['__loopVar' => $name, $name => array_values($items)[0]],
            array_values($items),
        );
    }
}
