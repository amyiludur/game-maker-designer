<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\CardMovement;
use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;

/** Move a card to a zone. The general form behind draw, discard, destroy and bounce. */
final class MoveCardOp implements Op
{
    public function id(): string
    {
        return 'move_card';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()
            ->required('card', 'selector')
            ->required('to', 'string', 'Zone id.')
            ->optional('controller', 'selector')
            ->optional('position', 'string', '"top", "bottom", or an index.')
            ->optional('facing', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $card = $context->card($node['card']);
        if ($card === null) {
            return;
        }

        CardMovement::move(
            $context,
            $card,
            (string) $node['to'],
            isset($node['controller']) ? $context->side($node['controller']) : null,
            $node['position'] ?? 'bottom',
            isset($node['facing']) ? $node['facing'] === 'down' : null,
        );
    }
}
