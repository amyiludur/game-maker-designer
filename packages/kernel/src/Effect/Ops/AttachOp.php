<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Attach one card to another. */
final class AttachOp implements Op
{
    public function id(): string
    {
        return 'attach';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('card', 'selector')->required('to', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $card = $context->card($node['card']);
        $host = $context->card($node['to']);
        if ($card === null || $host === null || $card === $host) {
            return;
        }

        CardMovement::detachFrom($context, $card);

        $context->draft->mutateInstance($card, ['attachedTo' => $host]);
        $context->draft->mutateInstance($host, [
            'attachments' => [...$context->draft->instance($host)->attachments, $card],
        ]);

        $context->emit('card.attached', ['card' => $card, 'host' => $host], $card);
    }
}
