<?php

declare(strict_types=1);

namespace Gmd\Kernel\Event;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\State\TriggerRecord;
use Gmd\Kernel\System\SystemDocument;

/**
 * Moves fired triggers onto the stack, in APNAP order.
 *
 * Active player's triggers first, then each other player in turn order (doc 07). The stack
 * resolves last-in-first-out, so they go on in reverse: the first in APNAP order ends up on
 * top and resolves first.
 *
 * Within one controller, triggers resolve in the order they fired. The docs also allow the
 * controller to choose that order, which is a real difference in a few board states; it is
 * not implemented here, and doing it means raising an `order_items` choice, which would put
 * an extra answer into every replay that has two simultaneous triggers on one side.
 */
final class TriggerQueue
{
    public function promote(Draft $draft, SystemDocument $system): bool
    {
        $queued = $draft->triggerQueue();
        if ($queued === []) {
            return false;
        }

        $draft->setTriggerQueue([]);

        $order = $this->apnap($draft);
        usort($queued, static function (TriggerRecord $a, TriggerRecord $b) use ($order, $system): int {
            if ($system->triggerOrdering === 'declaration') {
                return $a->queuedAt <=> $b->queuedAt;
            }

            return [$order[$a->controller] ?? PHP_INT_MAX, $a->queuedAt]
                <=> [$order[$b->controller] ?? PHP_INT_MAX, $b->queuedAt];
        });

        foreach (array_reverse($queued) as $trigger) {
            $draft->pushStack(new StackItem(
                id: $draft->nextId('stack', 's'),
                kind: StackItem::KIND_ABILITY,
                controller: $trigger->controller,
                frames: [new StackFrame($trigger->program)],
                bindings: $trigger->bindings,
                sourceInstance: $trigger->sourceInstance,
                abilityId: $trigger->abilityId,
                depth: $trigger->depth,
            ));
        }

        return true;
    }

    /** @return array<string, int> side id => position, active player first */
    private function apnap(Draft $draft): array
    {
        $sides = $draft->playerSides();
        $at = array_search($draft->activeSide(), $sides, true);
        $rotated = $at === false
            ? $sides
            : [...array_slice($sides, (int) $at), ...array_slice($sides, 0, (int) $at)];

        $order = [];
        foreach ($rotated as $position => $side) {
            $order[$side] = $position;
        }

        // Adversary triggers resolve after every player's, which matches how these games
        // read: the villain reacts to what the heroes did.
        foreach (array_keys($draft->adversaries()) as $adversary) {
            $order[$adversary] = count($order);
        }
        $order[Side::SHARED] = count($order);

        return $order;
    }
}
