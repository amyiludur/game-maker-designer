<?php

declare(strict_types=1);

namespace Gmd\Kernel\Effect;

/**
 * One verb of the effect language.
 *
 * Ops are the only things that change the game. Everything else — abilities, actions,
 * automatic steps, state check responses, an adversary's activation script — is a list of
 * these, which is why they can all be written by a designer in the same JSON and read by
 * the same interpreter.
 */
interface Op
{
    public function id(): string;

    public function params(): OpParamSpec;

    /**
     * Do the thing.
     *
     * An op that needs a decision does not block: it asks, via $context->await(), and is
     * re-run with the answer once one arrives. That is what lets a half-finished effect be
     * parked in Redis and resumed by a different worker.
     *
     * @param  array<string, mixed>  $node
     */
    public function execute(array $node, OpContext $context): void;
}
