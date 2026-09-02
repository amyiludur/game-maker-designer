<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect\Ops;

use Gmd\Kernel\Effect\Op;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Effect\OpParamSpec;
use Gmd\Kernel\State\MatchResult;

/** End the game with no winner. */
final class DrawGameOp implements Op
{
    public function id(): string
    {
        return 'draw_game';
    }

    public function params(): OpParamSpec
    {
        return OpParamSpec::make()->optional('reason', 'string');
    }

    public function execute(array $node, OpContext $context): void
    {
        $context->draft->setResult(new MatchResult(
            [],
            [],
            (string) ($node['reason'] ?? 'draw'),
            $context->draft->round(),
            draw: true,
        ));
    }
}
