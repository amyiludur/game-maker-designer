<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Ask a player to choose cards, then run a body once per chosen card.
 *
 * Note what happens when there is nothing to choose from and the choice is optional: the
 * op resolves silently, raising no prompt at all. The Emberfall replay pins exactly this —
 * Dust Weaver's Bolster finds no other friendly character and "resolves silently with no
 * choice raised" — because a prompt with no options is a dead end a player cannot get out
 * of.
 */
final class ChooseCardsOp implements Op
{
    public function id(): string
    {
        return 'choose_cards';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('query', 'query')
            ->optional('chooser', 'selector')
            ->optional('count', 'expression')
            ->optional('optional', 'boolean')
            ->optional('then', 'effect')
            ->optional('as', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $candidates = $context->cards($node['query']);
        $optional = (bool) ($node['optional'] ?? false);
        $count = $context->evaluateInt($node['count'] ?? null, 1);

        if ($candidates === []) {
            return;
        }

        $chosen = $context->answer('chosen');
        if ($chosen === null) {
            $context->await(new PendingChoice(
                id: 'chosen',
                kind: PendingChoice::CHOOSE_CARDS,
                side: $context->side($node['chooser'] ?? null),
                options: ['cards' => $candidates],
                prompt: (string) ($node['prompt'] ?? 'Choose a card'),
                count: min($count, count($candidates)),
                optional: $optional,
                sourceInstance: $context->item->sourceInstance,
                abilityId: $context->item->abilityId,
            ));

            return;
        }

        $selected = array_values(array_intersect($candidates, (array) $chosen));
        if ($selected === [] || ($node['then'] ?? []) === []) {
            return;
        }

        $name = (string) ($node['as'] ?? 'each');
        $context->descend($context->childPath('then'), ['__loopVar' => $name, $name => $selected[0]], $selected);
    }
}
