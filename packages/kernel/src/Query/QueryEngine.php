<?php

declare(strict_types=1);

namespace Gmd\Kernel\Query;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\State\PlayerState;

/**
 * Declarative selection of cards and players.
 *
 * The one semantic worth stating loudly: **queries read current values, not printed ones**.
 * A 2/3 wearing a +2 attack brand has attack 4 here, so a card that destroys "a character
 * with attack 3 or less" cannot target it. The Emberfall replay fixture calls pinning that
 * behaviour "the point of the fixture", and it is the sort of thing a rules engine exists
 * to get right.
 *
 * Evaluation order is fixed, and documented because a query's result must not depend on
 * how a filter was written: zones, then side filters, then types, then tags, then
 * `exclude`, then `where`, then `order`, then `limit`.
 */
final class QueryEngine
{
    /**
     * @param  array<string, mixed>  $query
     * @return list<string> instance ids
     */
    public function cards(array $query, EvalContext $context): array
    {
        $candidates = $this->candidates($query, $context);

        $matched = [];
        foreach ($candidates as $instanceId) {
            if ($this->passes($instanceId, $query, $context)) {
                $matched[] = $instanceId;
            }
        }

        $matched = $this->order($matched, $query, $context);

        if (isset($query['limit'])) {
            $limit = $context->pure()->evaluateInt($query['limit']);
            if ($limit >= 0) {
                $matched = array_slice($matched, 0, $limit);
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string> side ids
     */
    public function players(array $query, EvalContext $context): array
    {
        $you = $context->bindings->get('you');
        $selection = (string) ($query['players'] ?? 'all');

        $sides = [];
        foreach ($context->state->players() as $player) {
            if (! $player->isPlaying()) {
                continue;
            }
            $side = $player->side();
            $keep = match ($selection) {
                'you' => $side === $you,
                'opponents' => $side !== $you,
                'active' => $side === $context->state->activeSide(),
                default => true,
            };
            if ($keep) {
                $sides[] = $side;
            }
        }

        if (isset($query['where'])) {
            $sides = array_values(array_filter(
                $sides,
                fn (string $side): bool => $context->pure()->bind('player', $side)->evaluateBool($query['where']),
            ));
        }

        return $sides;
    }

    /** @param array<string, mixed> $query */
    public function count(array $query, EvalContext $context): int
    {
        return isset($query['players'])
            ? count($this->players($query, $context))
            : count($this->cards($query, $context));
    }

    /** @param array<string, mixed> $query */
    public function matches(string $instanceId, array $query, EvalContext $context): bool
    {
        if (! $context->state->hasInstance($instanceId)) {
            return false;
        }

        return $this->inQueriedZones($instanceId, $query, $context)
            && $this->passes($instanceId, $query, $context);
    }

    /**
     * Which instances the query could possibly be about, in a defined order.
     *
     * Order matters and is not incidental: it decides which card "the top of your deck"
     * means, and it is the tiebreak that keeps an unordered result reproducible.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function candidates(array $query, EvalContext $context): array
    {
        $zoneKeys = $this->zoneKeys($query, $context);

        if ($zoneKeys === null) {
            // No zone named: every instance, in allocation order. The linter flags this as
            // a probable authoring mistake, but the engine still has to answer it.
            return array_keys($context->state->instances());
        }

        $candidates = [];
        foreach ($zoneKeys as $zoneKey) {
            foreach ($context->state->zone($zoneKey) as $instanceId) {
                $candidates[] = $instanceId;
            }
        }

        return $candidates;
    }

    /**
     * The qualified zone keys a query covers.
     *
     * An unqualified `"zone": "play"` means every side's play area — which is what makes
     * "destroy a character" able to reach the opponent's board. `zonePlayer` narrows it to
     * one side's copy, which is how an enemy engaged with player 2 is addressed.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>|null null when the query names no zone at all
     */
    private function zoneKeys(array $query, EvalContext $context): ?array
    {
        if (! isset($query['zone'])) {
            return null;
        }

        $zoneIds = array_map(strval(...), is_array($query['zone']) ? $query['zone'] : [$query['zone']]);

        $sides = isset($query['zonePlayer'])
            ? $context->runtime->selectors->resolveMany((string) $query['zonePlayer'], $context)
            : $context->state->sides();

        $keys = [];
        foreach ($zoneIds as $zoneId) {
            if (! $context->system->hasZone($zoneId)) {
                continue;
            }
            if ($context->system->zone($zoneId)->isShared()) {
                $keys[Side::zoneKey(Side::SHARED, $zoneId)] = true;

                continue;
            }
            foreach ($sides as $side) {
                $keys[Side::zoneKey($side, $zoneId)] = true;
            }
        }

        return array_keys($keys);
    }

    /** @param array<string, mixed> $query */
    private function inQueriedZones(string $instanceId, array $query, EvalContext $context): bool
    {
        $zoneKeys = $this->zoneKeys($query, $context);

        return $zoneKeys === null || in_array($context->state->instance($instanceId)->zone, $zoneKeys, true);
    }

    /** @param array<string, mixed> $query */
    private function passes(string $instanceId, array $query, EvalContext $context): bool
    {
        $instance = $context->state->instance($instanceId);
        $characteristics = $context->runtime->modifiers->characteristics($context, $instanceId);

        if (isset($query['controller'])
            && ! $this->sideMatches($characteristics->controller, $query['controller'], $context)) {
            return false;
        }

        if (isset($query['owner'])
            && ! $this->sideMatches($instance->owner, $query['owner'], $context)) {
            return false;
        }

        if (isset($query['face']) && $instance->face !== $query['face']) {
            return false;
        }

        if (isset($query['types']) && ! $this->anyOf($characteristics->types, $query['types'])) {
            return false;
        }

        if (isset($query['traits']) && ! $this->tagFilter($characteristics->traits, $query['traits'])) {
            return false;
        }

        if (isset($query['keywords']) && ! $this->tagFilter($characteristics->keywords, $query['keywords'])) {
            return false;
        }

        if (isset($query['exclude'])) {
            foreach ((array) $query['exclude'] as $selector) {
                $excluded = $context->runtime->selectors->resolveMany((string) $selector, $context);
                if (in_array($instanceId, $excluded, true)) {
                    return false;
                }
            }
        }

        if (isset($query['where'])) {
            // The filter is evaluated in a context that cannot draw randomness: a query is
            // a read, and one that consumed the RNG would make legalActions() unrepeatable.
            if (! $context->pure()->bind('card', $instanceId)->evaluateBool($query['where'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $matched
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function order(array $matched, array $query, EvalContext $context): array
    {
        if (! isset($query['order']['by'])) {
            return $matched;
        }

        $descending = ($query['order']['dir'] ?? 'asc') === 'desc';
        $pure = $context->pure();

        // Sorted on the key, then on the instance id, so equal keys never tie: PHP's sort
        // is not stable enough to rely on, and a tie decided differently on two machines
        // would break the conformance suite.
        usort($matched, static function (string $a, string $b) use ($pure, $query, $descending): int {
            $keyA = $pure->bind('card', $a)->evaluateInt($query['order']['by']);
            $keyB = $pure->bind('card', $b)->evaluateInt($query['order']['by']);
            $comparison = $keyA <=> $keyB;

            return ($descending ? -$comparison : $comparison) ?: strcmp($a, $b);
        });

        return $matched;
    }

    private function sideMatches(string $side, mixed $expected, EvalContext $context): bool
    {
        $wanted = is_string($expected) && str_starts_with($expected, '$')
            ? $context->runtime->selectors->resolveMany($expected, $context)
            : array_map(strval(...), is_array($expected) ? $expected : [$expected]);

        return in_array($side, $wanted, true);
    }

    /**
     * @param  list<string>  $actual
     */
    private function anyOf(array $actual, mixed $wanted): bool
    {
        $wanted = array_map(strval(...), is_array($wanted) ? $wanted : [$wanted]);

        return array_intersect($actual, $wanted) !== [];
    }

    /**
     * A tag filter is either a bare list ("has any of these") or {any, all, none}.
     *
     * @param  list<string>  $actual
     */
    private function tagFilter(array $actual, mixed $filter): bool
    {
        if (! is_array($filter)) {
            return in_array((string) $filter, $actual, true);
        }

        if (array_is_list($filter)) {
            return $this->anyOf($actual, $filter);
        }

        if (isset($filter['any']) && ! $this->anyOf($actual, $filter['any'])) {
            return false;
        }
        if (isset($filter['all']) && array_diff((array) $filter['all'], $actual) !== []) {
            return false;
        }
        if (isset($filter['none']) && $this->anyOf($actual, $filter['none'])) {
            return false;
        }

        return true;
    }

    /** Convenience for the many places that need the seat behind a side id. */
    public function player(string $side, EvalContext $context): ?PlayerState
    {
        return $context->runtime->selectors->seatOf($side, $context);
    }
}
