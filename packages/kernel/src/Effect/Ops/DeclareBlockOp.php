<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Assign a blocker to a declared attack. */
final class DeclareBlockOp implements Op
{
    public function id(): string
    {
        return 'declare_block';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('blocker', 'selector')
            ->required('attacker', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $blocker = $context->card($node['blocker']);
        $attacker = $context->card($node['attacker']);
        if ($blocker === null || $attacker === null) {
            return;
        }

        /** @var list<array{attacker: string, defender: string, blocker: ?string}> $attacks */
        $attacks = $context->draft->var(DeclareAttackOp::ATTACKS, []);
        $assigned = false;

        foreach ($attacks as $index => $attack) {
            if ($attack['attacker'] === $attacker && $attack['blocker'] === null) {
                $attacks[$index]['blocker'] = $blocker;
                $assigned = true;
                break;
            }
        }

        if (! $assigned) {
            return;
        }

        $context->draft->setVar(DeclareAttackOp::ATTACKS, $attacks);
        $context->emit('block.declared', ['blocker' => $blocker, 'attacker' => $attacker], $blocker);
    }
}
