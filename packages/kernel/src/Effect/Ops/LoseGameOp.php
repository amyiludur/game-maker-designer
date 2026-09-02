<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;
use Gmd\Kernel\State\MatchResult;

/** End the game with a loser. Everyone else wins. */
final class LoseGameOp implements Op
{
    public function id(): string
    {
        return 'lose_game';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->optional('player', 'selector')->optional('reason', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $losers = $context->sides($node['player'] ?? null);
        $winners = array_values(array_diff($context->draft->playerSides(), $losers));

        $context->draft->setResult(new MatchResult(
            $winners,
            $losers,
            (string) ($node['reason'] ?? 'lose_game'),
            $context->draft->round(),
        ));
    }
}
