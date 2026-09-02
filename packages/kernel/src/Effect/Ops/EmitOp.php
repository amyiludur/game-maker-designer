<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Fire a named event.
 *
 * This is what an action's `emits` list compiles to. Without it, every action that wanted
 * "when you play a card" triggers to work would have to emit the event by hand somewhere in
 * its effect, and a designer who forgot would get a card that silently never triggers.
 */
final class EmitOp implements Op
{
    public function id(): string
    {
        return 'emit';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('type', 'string')->optional('payload', 'object');
    }

    public function execute(array $node, OpContext $context): void
    {
        $payload = [];
        foreach ($node['payload'] ?? [] as $key => $value) {
            $resolved = $context->evaluate($value);
            if ($resolved !== null) {
                $payload[(string) $key] = $resolved;
            }
        }

        $context->emit((string) $node['type'], $payload);
    }
}
