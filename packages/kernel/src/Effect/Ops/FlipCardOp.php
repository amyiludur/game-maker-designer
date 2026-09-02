<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/**
 * Turn a card to its other side: hero to alter-ego, villain stage I to stage II.
 *
 * Counters, attachments and damage persist by default (doc 16 §4). A hero who flips with
 * five damage still has five damage, which is the correct and frequently mishandled
 * behaviour — and it needs no special code here, because flipping only changes the printed
 * characteristics at layer 0 and everything above simply recomputes.
 */
final class FlipCardOp implements Op
{
    public function id(): string
    {
        return 'flip_card';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('card', 'selector')
            ->optional('to', 'string', 'A specific face; otherwise the other one.')
            ->optional('carry', 'array', 'What to keep; everything is kept by default.');
    }

    public function execute(array $node, OpContext $context): void
    {
        $card = $context->card($node['card']);
        if ($card === null) {
            return;
        }

        $instance = $context->draft->instance($card);
        $definition = $context->system->cards->get($instance->code);
        $face = (string) ($node['to'] ?? $definition->otherFace($instance->face));

        if ($face === $instance->face || ! isset($definition->faces[$face])) {
            return;
        }

        $context->draft->mutateInstance($card, ['face' => $face]);
        $context->emit('card.flipped', ['card' => $card, 'from' => $instance->face, 'to' => $face], $card);
    }
}
