<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\State\StackItem;

/**
 * Take the adversary's turn.
 *
 * The villain does not decide anything — it runs the script its system document declares,
 * which is the whole difference between an adversary and a bot (doc 16 §2). That is what
 * makes a scenario reproducible from its seed, and difficulty a property of the data rather
 * than of an opponent's competence.
 *
 * The script goes on the stack as its own item rather than being run inline, so its effects
 * interleave with triggers and state checks exactly as a player's ability would: a minion
 * killed by the first half of an activation is gone before the second half looks for it.
 */
final class RunActivationOp implements Op
{
    public function id(): string
    {
        return 'run_activation';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->optional('adversary', 'string', 'Which adversary acts; defaults to the only one.');
    }

    public function execute(array $node, OpContext $context): void
    {
        $id = isset($node['adversary'])
            ? (string) $node['adversary']
            : $this->soleAdversary($context);

        $definition = $context->system->adversary($id) ?? throw BadDocument::because(
            "run_activation names adversary \"{$id}\", which this system does not define",
            ['adversary' => $id, 'defined' => array_keys($context->system->adversaries)],
        );

        // An adversary with nothing to do is not an error — a scenario may put a villain on
        // the table purely as a target — but it is worth not pushing an empty frame for.
        if (! $definition->hasActivation) {
            return;
        }

        if ($context->draft->adversary($id) === null) {
            throw BadDocument::because(
                "adversary \"{$id}\" is not on the table; the match was built without its scenario",
                ['adversary' => $id],
            );
        }

        $context->emit('adversary.activated', ['adversary' => $id]);

        $context->invoke(
            $definition->activationProgram(),
            $id,
            new Bindings(['adversary' => $id, 'you' => $id]),
            StackItem::KIND_ACTIVATION,
        );
    }

    private function soleAdversary(OpContext $context): string
    {
        $ids = array_keys($context->system->adversaries);
        if (count($ids) !== 1) {
            throw BadDocument::because(
                $ids === []
                    ? 'run_activation was used in a game with no adversary'
                    : 'run_activation must name its adversary: this game has ' . count($ids),
                ['defined' => $ids],
            );
        }

        return $ids[0];
    }
}
