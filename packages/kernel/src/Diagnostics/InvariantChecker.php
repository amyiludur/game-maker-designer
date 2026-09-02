<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\System\SystemDocument;

/**
 * Structural invariants that must hold after every action (doc 13).
 *
 * These are the things that cannot be wrong without the game being nonsense: a card in two
 * places at once, a zone that disagrees with the cards claiming to be in it, a negative
 * counter, an attachment only one of the two cards knows about.
 *
 * They live in the kernel rather than the test suite so a fuzz run — or a live table under
 * a strict flag — can assert them continuously. Random play is very good at reaching states
 * that break these, and very bad at telling you it has, unless something is looking.
 */
final class InvariantChecker
{
    /** @return list<string> violations, empty when the state is sound */
    public function check(GameState $state, ?SystemDocument $system = null): array
    {
        return [
            ...$this->zonesAgreeWithInstances($state),
            ...$this->countersAreSane($state),
            ...$this->attachmentsAreMutual($state),
            ...($system === null ? [] : $this->resourcesAreInRange($state, $system)),
        ];
    }

    /**
     * Invariants 1 and 2: every card is in exactly one zone, once, and every zone's list
     * matches the cards that claim to be in it.
     *
     * @return list<string>
     */
    private function zonesAgreeWithInstances(GameState $state): array
    {
        $violations = [];
        $seen = [];

        foreach ($state->zones as $zoneKey => $instanceIds) {
            $counts = array_count_values($instanceIds);
            foreach ($counts as $instanceId => $count) {
                if ($count > 1) {
                    $violations[] = "{$instanceId} appears {$count} times in {$zoneKey}";
                }
                if (isset($seen[$instanceId])) {
                    $violations[] = "{$instanceId} is in both {$seen[$instanceId]} and {$zoneKey}";
                }
                $seen[(string) $instanceId] = $zoneKey;

                if (! $state->hasInstance((string) $instanceId)) {
                    $violations[] = "{$zoneKey} lists {$instanceId}, which is not a card in this match";
                }
            }
        }

        foreach ($state->instances as $instanceId => $instance) {
            if (! isset($seen[$instanceId])) {
                $violations[] = "{$instanceId} claims to be in {$instance->zone} but that zone does not list it";

                continue;
            }
            if ($seen[$instanceId] !== $instance->zone) {
                $violations[] = "{$instanceId} claims {$instance->zone} but is listed in {$seen[$instanceId]}";
            }
        }

        return $violations;
    }

    /**
     * Invariant 4: no counter is negative.
     *
     * @return list<string>
     */
    private function countersAreSane(GameState $state): array
    {
        $violations = [];
        foreach ($state->instances as $instanceId => $instance) {
            foreach ($instance->counters as $counter => $amount) {
                if ($amount < 0) {
                    $violations[] = "{$instanceId} has {$amount} {$counter} counters";
                }
            }
        }

        return $violations;
    }

    /**
     * Invariant 5: attachments are mutual.
     *
     * A card that thinks it is attached to something that does not think it is holding it
     * produces a buff nobody can find the source of.
     *
     * @return list<string>
     */
    private function attachmentsAreMutual(GameState $state): array
    {
        $violations = [];

        foreach ($state->instances as $instanceId => $instance) {
            $host = $instance->attachedTo;
            if ($host !== null) {
                if (! $state->hasInstance($host)) {
                    $violations[] = "{$instanceId} is attached to {$host}, which is not in the match";
                } elseif (! in_array($instanceId, $state->instance($host)->attachments, true)) {
                    $violations[] = "{$instanceId} is attached to {$host}, which does not list it";
                }
            }

            foreach ($instance->attachments as $attachment) {
                if (! $state->hasInstance($attachment)) {
                    $violations[] = "{$instanceId} lists attachment {$attachment}, which is not in the match";
                } elseif ($state->instance($attachment)->attachedTo !== $instanceId) {
                    $violations[] = "{$instanceId} lists {$attachment} as attached, but it is not";
                }
            }
        }

        return $violations;
    }

    /**
     * Invariant 3: resources stay inside the bounds the game declared.
     *
     * @return list<string>
     */
    private function resourcesAreInRange(GameState $state, SystemDocument $system): array
    {
        $violations = [];

        foreach ($state->players as $player) {
            foreach ($player->resources as $id => $amount) {
                $definition = $system->resources[$id] ?? null;
                if ($definition === null) {
                    $violations[] = Side::player($player->seat) . " holds an undeclared resource \"{$id}\"";

                    continue;
                }
                if ($amount < $definition->min) {
                    $violations[] = Side::player($player->seat) . " has {$amount} {$id}, below the minimum {$definition->min}";
                }
                if ($definition->max !== null && $amount > $definition->max) {
                    $violations[] = Side::player($player->seat) . " has {$amount} {$id}, above the maximum {$definition->max}";
                }
            }
        }

        return $violations;
    }
}
