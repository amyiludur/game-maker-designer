<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;
use Gmd\Kernel\State\MatchResult;

/** End the game with a winner. */
final class WinGameOp implements Op
{
    public function id(): string
    {
        return 'win_game';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->optional('player', 'selector')->optional('reason', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $winners = $context->sides($node['player'] ?? null);
        $losers = array_values(array_diff($context->draft->playerSides(), $winners));

        $context->draft->setResult(new MatchResult(
            $winners,
            $losers,
            (string) ($node['reason'] ?? 'win_game'),
            $context->draft->round(),
        ));
    }
}
