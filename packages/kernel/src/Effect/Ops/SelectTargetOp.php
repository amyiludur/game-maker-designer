<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Choose an ability's target.
 *
 * Compiled in front of an ability's effect, so that targets are chosen when the ability
 * goes on the stack (doc 06) using the same resumable machinery as everything else, rather
 * than being special-cased in the trigger queue.
 *
 * Three behaviours are worth stating, because each is a rule rather than an implementation
 * convenience:
 *
 *  - A **required** target with no legal choice fizzles the whole ability. It does not
 *    resolve partially.
 *  - An **optional** target with no legal choice resolves silently, raising no prompt at
 *    all. The Emberfall replay pins exactly this: Dust Weaver's Bolster on an empty board
 *    "resolves silently with no choice raised". A prompt with no options is a dead end.
 *  - A required target with exactly one legal choice is taken without asking. There is no
 *    decision to make, and a prompt would put an answer into every replay that carries no
 *    information.
 */
final class SelectTargetOp implements Op
{
    public function id(): string
    {
        return 'select_target';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('id', 'string')
            ->required('query', 'query')
            ->optional('count', 'expression')
            ->optional('optional', 'boolean')
            ->optional('chooser', 'selector')
            ->optional('prompt', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $id = (string) $node['id'];
        $binding = 'target.' . $id;

        if ($context->bindings->has($binding)) {
            return;
        }

        $optional = (bool) ($node['optional'] ?? false);
        $wanted = max(1, $context->evaluateInt($node['count'] ?? null, 1));
        $candidates = $context->cards($node['query']);

        if ($candidates === []) {
            if ($optional) {
                $context->bind([$binding => null]);

                return;
            }
            $context->fizzle();

            return;
        }

        if (! $optional && count($candidates) <= $wanted) {
            $context->bind([$binding => $wanted === 1 ? $candidates[0] : $candidates]);

            return;
        }

        $answer = $context->answer($id);
        if ($answer === null) {
            $context->await(new PendingChoice(
                id: $id,
                kind: PendingChoice::CHOOSE_CARDS,
                side: $context->side($node['chooser'] ?? null),
                options: ['cards' => $candidates],
                prompt: (string) ($node['prompt'] ?? 'Choose a target'),
                count: $wanted,
                optional: $optional,
                sourceInstance: $context->item->sourceInstance,
                abilityId: $context->item->abilityId,
            ));

            return;
        }

        $chosen = array_values(array_intersect($candidates, (array) $answer));
        $context->bind([$binding => match (true) {
            $chosen === [] => null,
            $wanted === 1 => $chosen[0],
            default => array_slice($chosen, 0, $wanted),
        }]);
    }
}
