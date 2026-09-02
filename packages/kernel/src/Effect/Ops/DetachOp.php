<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Detach a card from its host, leaving it where it is. */
final class DetachOp implements Op
{
    public function id(): string
    {
        return 'detach';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->required('card', 'selector');
    }

    public function execute(array $node, OpContext $context): void
    {
        $card = $context->card($node['card']);
        if ($card === null) {
            return;
        }

        CardMovement::detachFrom($context, $card);
        $context->draft->mutateInstance($card, ['attachedTo' => null]);
    }
}
