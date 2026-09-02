<?php

declare(strict_types=1);

namespace Gmd\Kernel\Expr;

use Gmd\Kernel\Diagnostics\NonDeterministicExpression;
use Gmd\Kernel\Diagnostics\UnknownOp;
use Gmd\Kernel\State\Instance;

/**
 * The one expression language in the platform.
 *
 * The same evaluator serves card abilities, state checks, win conditions, deckbuilding
 * constraints and bot feature weights (doc 09). That is not tidiness for its own sake: it
 * means a designer who has learned to write "count of my Soldiers in play" for a card can
 * write it for a deck rule and for a bot's scoring function, and it means there is one
 * place where "does this card match" can be wrong.
 *
 * Two rules the whole platform depends on:
 *
 *  - Arithmetic is integer-only (ADR-0005). "Half, rounded up" is written out; nothing is
 *    ever silently 2.5.
 *  - `attr` reads the *current* value, after modifiers. That is what makes a branded 2/3
 *    with +2 attack fail a "cost 3 or less attack" filter, which is exactly the kind of
 *    interaction a rules engine is bought to get right.
 */
final class ExpressionEvaluator
{
    public function evaluate(mixed $expression, EvalContext $context): mixed
    {
        if ($expression === null || is_bool($expression) || is_int($expression)) {
            return $expression;
        }

        if (is_float($expression)) {
            // Rules maths is integer-only; a float in an authored document is a mistake
            // that would otherwise propagate silently into a state that cannot be hashed.
            return (int) $expression;
        }

        if (is_string($expression)) {
            return str_starts_with($expression, '$')
                ? $context->runtime->selectors->resolve($expression, $context)
                : $expression;
        }

        if (is_array($expression)) {
            if (array_is_list($expression)) {
                return array_map(fn (mixed $item): mixed => $this->evaluate($item, $context), $expression);
            }

            return $this->node($expression, $context);
        }

        throw UnknownOp::because('cannot evaluate a ' . get_debug_type($expression) . ' as an expression');
    }

    public function evaluateInt(mixed $expression, EvalContext $context): int
    {
        $value = $this->evaluate($expression, $context);

        return match (true) {
            is_int($value) => $value,
            is_bool($value) => $value ? 1 : 0,
            is_numeric($value) => (int) $value,
            is_array($value) => count($value),
            default => 0,
        };
    }

    public function evaluateBool(mixed $expression, EvalContext $context): bool
    {
        $value = $this->evaluate($expression, $context);

        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_array($value) => $value !== [],
            is_string($value) => $value !== '',
            default => false,
        };
    }

    /** @param array<string, mixed> $node */
    private function node(array $node, EvalContext $context): mixed
    {
        $op = $node['op'] ?? null;
        if (! is_string($op)) {
            throw UnknownOp::because('expression node has no op', ['node' => array_keys($node)]);
        }

        return match ($op) {
            // --- reading the position -------------------------------------------------
            'constant' => $node['value'] ?? null,
            'round' => $context->state->round(),
            'phase' => $context->state->phase(),
            'step' => $context->state->step(),
            'player_count' => $context->state->playerCount(),
            'var' => $context->state->var((string) $node['id']),
            'param' => $context->bindings->get('param')[(string) $node['id']] ?? null,
            'random_int' => $this->randomInt($node, $context),

            // --- reading a card -------------------------------------------------------
            'attr' => $this->attribute($node, $context, current: true),
            'baseAttr' => $this->attribute($node, $context, current: false),
            'counter' => $this->counter($node, $context),
            'face' => $this->instance($node, $context, 'card')?->face,
            'side_of' => $this->instance($node, $context, 'card')?->controller,
            'resource' => $this->resource($node, $context),
            'zone_size' => count($this->zoneOf($node, $context)),

            // --- arithmetic, integer only ---------------------------------------------
            'add' => $this->evaluateInt($node['left'] ?? 0, $context) + $this->evaluateInt($node['right'] ?? 0, $context),
            'sub' => $this->evaluateInt($node['left'] ?? 0, $context) - $this->evaluateInt($node['right'] ?? 0, $context),
            'mul' => $this->evaluateInt($node['left'] ?? 0, $context) * $this->evaluateInt($node['right'] ?? 0, $context),
            'div' => $this->divide($node, $context),
            'mod' => $this->modulo($node, $context),
            'abs' => abs($this->evaluateInt($node['of'] ?? 0, $context)),
            'min' => $this->fold($node, $context, min(...)),
            'max' => $this->fold($node, $context, max(...)),
            'clamp' => max(
                $this->evaluateInt($node['min'] ?? 0, $context),
                min($this->evaluateInt($node['max'] ?? 0, $context), $this->evaluateInt($node['of'] ?? 0, $context)),
            ),
            'if' => $this->evaluateBool($node['cond'] ?? false, $context)
                ? $this->evaluate($node['then'] ?? null, $context)
                : $this->evaluate($node['else'] ?? null, $context),

            // --- comparison and logic --------------------------------------------------
            'eq' => $this->same($this->evaluate($node['left'] ?? null, $context), $this->evaluate($node['right'] ?? null, $context)),
            'ne' => ! $this->same($this->evaluate($node['left'] ?? null, $context), $this->evaluate($node['right'] ?? null, $context)),
            'lt' => $this->evaluateInt($node['left'] ?? 0, $context) < $this->evaluateInt($node['right'] ?? 0, $context),
            'lte' => $this->evaluateInt($node['left'] ?? 0, $context) <= $this->evaluateInt($node['right'] ?? 0, $context),
            'gt' => $this->evaluateInt($node['left'] ?? 0, $context) > $this->evaluateInt($node['right'] ?? 0, $context),
            'gte' => $this->evaluateInt($node['left'] ?? 0, $context) >= $this->evaluateInt($node['right'] ?? 0, $context),
            'and' => $this->every($node, $context, expected: true),
            'or' => $this->some($node, $context),
            'not' => ! $this->evaluateBool($node['of'] ?? false, $context),

            // --- card predicates -------------------------------------------------------
            'is_type' => $this->characteristics($node, $context)?->isType((string) $node['type']) ?? false,
            'has_trait' => $this->characteristics($node, $context)?->hasTrait((string) $node['trait']) ?? false,
            'has_keyword' => $this->characteristics($node, $context)?->hasKeyword((string) $node['keyword']) ?? false,
            'has_permission' => $this->characteristics($node, $context)?->permits((string) $node['permission']) ?? false,
            'has_restriction' => $this->characteristics($node, $context)?->restricted((string) $node['restriction']) ?? false,
            'is_exhausted' => $this->instance($node, $context, 'card')?->exhausted ?? false,
            'is_face_down' => $this->instance($node, $context, 'card')?->faceDown ?? false,
            'is_attached' => $this->instance($node, $context, 'card')?->isAttached() ?? false,
            'entered_this_round' => $this->enteredThisRound($node, $context),
            'can_enter_play' => $this->canEnterPlay($node, $context),
            'in_zone' => $this->inZone($node, $context),
            'controlled_by' => $this->same($this->instance($node, $context, 'card')?->controller, $this->evaluate($node['player'] ?? null, $context)),
            'owned_by' => $this->same($this->instance($node, $context, 'card')?->owner, $this->evaluate($node['player'] ?? null, $context)),
            'can_pay' => $this->canPay($node, $context),
            'matches' => $this->matches($node, $context),

            // --- querying the board ----------------------------------------------------
            'count' => $context->runtime->queries->count($this->queryOf($node), $context),
            'exists' => $context->runtime->queries->count($this->queryOf($node), $context) > 0,
            'all' => $this->quantify($node, $context, 'all'),
            'any' => $this->quantify($node, $context, 'any'),
            'none' => $this->quantify($node, $context, 'none'),

            default => throw UnknownOp::because(
                "the kernel does not implement the expression op \"{$op}\"",
                ['op' => $op],
            ),
        };
    }

    /** @param array<string, mixed> $node */
    private function attribute(array $node, EvalContext $context, bool $current): mixed
    {
        $instanceId = $this->instanceId($node, $context, 'of');
        if ($instanceId === null) {
            return null;
        }
        $attribute = (string) $node['attr'];

        return $current
            ? $context->runtime->modifiers->attribute($context, $instanceId, $attribute)
            : $context->runtime->modifiers->baseAttribute($context, $instanceId, $attribute);
    }

    /** @param array<string, mixed> $node */
    private function counter(array $node, EvalContext $context): int
    {
        return $this->instance($node, $context, 'of')?->counter((string) $node['counter']) ?? 0;
    }

    /** @param array<string, mixed> $node */
    private function resource(array $node, EvalContext $context): int
    {
        $side = $this->evaluate($node['player'] ?? null, $context);
        $player = $context->runtime->selectors->seatOf($side, $context);

        return $player?->resource((string) $node['resource']) ?? 0;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function zoneOf(array $node, EvalContext $context): array
    {
        $side = $this->evaluate($node['player'] ?? null, $context);
        $zoneId = (string) $node['zone'];

        if (is_array($side)) {
            $ids = [];
            foreach ($side as $one) {
                $ids = [...$ids, ...$context->state->zone($context->system->qualifiedZone((string) $one, $zoneId))];
            }

            return $ids;
        }

        return $context->state->zone($context->system->qualifiedZone(
            is_string($side) ? $side : $context->state->activeSide(),
            $zoneId,
        ));
    }

    /** @param array<string, mixed> $node */
    private function enteredThisRound(array $node, EvalContext $context): bool
    {
        $instance = $this->instance($node, $context, 'card');

        return $instance !== null && $instance->enteredOnRound === $context->state->round();
    }

    /** @param array<string, mixed> $node */
    /**
     * Could this card legally arrive in that zone right now?
     *
     * A real primitive rather than a convenience: zone capacity, uniqueness and
     * "this cannot enter play" are rules every game has, and without this each one would
     * re-express them as a hand-written conjunction in every action that puts a card
     * somewhere — and get one of them wrong.
     *
     * The zone defaults to the first entry in the card type's `playableTo`, so
     * `{ "op": "can_enter_play", "card": "$target.card" }` means what it reads as.
     *
     * @param  array<string, mixed>  $node
     */
    private function canEnterPlay(array $node, EvalContext $context): bool
    {
        $instance = $this->instance($node, $context, 'card');
        $characteristics = $this->characteristics($node, $context);
        if ($instance === null || $characteristics === null) {
            return false;
        }

        if ($characteristics->restricted('cannot_enter_play')) {
            return false;
        }

        $type = $characteristics->types[0] ?? null;
        $definition = $type !== null && $context->system->hasCardType($type)
            ? $context->system->cardType($type)
            : null;

        $zoneId = isset($node['zone'])
            ? (string) $context->evaluate($node['zone'])
            : ($definition?->playableTo[0] ?? 'play');

        if (! $context->system->hasZone($zoneId)) {
            return false;
        }

        $zone = $context->system->zone($zoneId);
        $key = $context->system->qualifiedZone($instance->controller, $zoneId);

        if ($zone->maxSize !== null && count($context->state->zone($key)) >= $zone->maxSize) {
            return false;
        }

        // Uniqueness is per controller and by card code: a second copy of a unique card
        // cannot arrive while the first is still there.
        if ($definition?->unique === true) {
            foreach ($context->state->zone($key) as $occupantId) {
                $occupant = $context->state->instance($occupantId);
                if ($occupant->code === $instance->code && $occupant->id !== $instance->id) {
                    return false;
                }
            }
        }

        return true;
    }

    private function inZone(array $node, EvalContext $context): bool
    {
        $instance = $this->instance($node, $context, 'card');
        if ($instance === null) {
            return false;
        }

        $wanted = $node['zone'];
        $zoneIds = array_map(strval(...), is_array($wanted) ? $wanted : [$wanted]);

        // Card zones are qualified by side (`p0.play`); a card text saying "in play" means
        // the bare zone id, whoever's copy of it.
        [, $bare] = \Gmd\Kernel\Contract\Side::splitZoneKey($instance->zone);

        return in_array($bare, $zoneIds, true) || in_array($instance->zone, $zoneIds, true);
    }

    /** @param array<string, mixed> $node */
    private function canPay(array $node, EvalContext $context): bool
    {
        $side = $this->evaluate($node['player'] ?? '$you', $context);
        $player = $context->runtime->selectors->seatOf($side, $context);

        return $player !== null
            && $player->resource((string) $node['resource']) >= $this->evaluateInt($node['amount'] ?? 0, $context);
    }

    /** @param array<string, mixed> $node */
    private function matches(array $node, EvalContext $context): bool
    {
        $instanceId = $this->instanceId($node, $context, 'card');

        return $instanceId !== null
            && $context->runtime->queries->matches($instanceId, $this->queryOf($node), $context);
    }

    /**
     * `all`, `any` and `none` take a query in `of` and a predicate in `match`, with `$card`
     * bound to each candidate.
     *
     * @param  array<string, mixed>  $node
     */
    private function quantify(array $node, EvalContext $context, string $mode): bool
    {
        $candidates = $context->runtime->queries->cards($this->queryOf($node), $context);
        $predicate = $node['match'] ?? true;

        $matched = 0;
        foreach ($candidates as $candidate) {
            if ($this->evaluateBool($predicate, $context->bind('card', $candidate))) {
                $matched++;
            } elseif ($mode === 'all') {
                return false;
            }
        }

        return match ($mode) {
            'all' => true,
            'any' => $matched > 0,
            default => $matched === 0,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function queryOf(array $node): array
    {
        $query = $node['query'] ?? $node['of'] ?? [];

        return is_array($query) ? $query : [];
    }

    /** @param array<string, mixed> $node */
    private function instanceId(array $node, EvalContext $context, string $key): ?string
    {
        $value = $this->evaluate($node[$key] ?? null, $context);
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $context->state->hasInstance($value) ? $value : null;
    }

    /** @param array<string, mixed> $node */
    private function instance(array $node, EvalContext $context, string $key): ?Instance
    {
        $id = $this->instanceId($node, $context, $key);

        return $id === null ? null : $context->state->instance($id);
    }

    /** @param array<string, mixed> $node */
    private function characteristics(array $node, EvalContext $context): ?\Gmd\Kernel\Modifier\CharacteristicSet
    {
        $id = $this->instanceId($node, $context, 'card') ?? $this->instanceId($node, $context, 'of');

        return $id === null ? null : $context->runtime->modifiers->characteristics($context, $id);
    }

    /** @param array<string, mixed> $node */
    private function divide(array $node, EvalContext $context): int
    {
        $right = $this->evaluateInt($node['right'] ?? 0, $context);

        // Division by zero is a design error in a card, not a crash worth taking down a
        // ten-thousand-match batch for.
        return $right === 0 ? 0 : intdiv($this->evaluateInt($node['left'] ?? 0, $context), $right);
    }

    /** @param array<string, mixed> $node */
    private function modulo(array $node, EvalContext $context): int
    {
        $right = $this->evaluateInt($node['right'] ?? 0, $context);

        return $right === 0 ? 0 : $this->evaluateInt($node['left'] ?? 0, $context) % $right;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(int, int): int  $fold
     */
    private function fold(array $node, EvalContext $context, callable $fold): int
    {
        $values = array_map(
            fn (mixed $item): int => $this->evaluateInt($item, $context),
            is_array($node['of'] ?? null) ? $node['of'] : [$node['of'] ?? 0],
        );

        return $values === [] ? 0 : array_reduce($values, $fold, array_shift($values));
    }

    /** @param array<string, mixed> $node */
    private function every(array $node, EvalContext $context, bool $expected): bool
    {
        foreach ($this->operands($node) as $operand) {
            if ($this->evaluateBool($operand, $context) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $node */
    private function some(array $node, EvalContext $context): bool
    {
        foreach ($this->operands($node) as $operand) {
            if ($this->evaluateBool($operand, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `and`/`or` take a list in `of`, but a single node is also accepted, because `not`
     * takes one and authors reasonably expect the family to be consistent.
     *
     * @param  array<string, mixed>  $node
     * @return list<mixed>
     */
    private function operands(array $node): array
    {
        $of = $node['of'] ?? [];
        if (is_array($of) && array_is_list($of)) {
            return $of;
        }

        return [$of];
    }

    /** @param array<string, mixed> $node */
    private function randomInt(array $node, EvalContext $context): int
    {
        if ($context->rng === null) {
            // A random draw inside a query filter or a legality check would make
            // legalActions() mutate the state it is inspecting, and stop it being cacheable
            // or safe to call twice.
            throw NonDeterministicExpression::because(
                'random_int is not allowed here: this expression must be a pure read',
            );
        }

        return $context->rng->nextInt(
            $this->evaluateInt($node['min'] ?? 0, $context),
            $this->evaluateInt($node['max'] ?? 0, $context),
        );
    }

    /** Equality that treats 3 and "3" as the same number but keeps null distinct from 0. */
    private function same(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (is_bool($left) || is_bool($right)) {
            return (bool) $left === (bool) $right;
        }
        if (is_numeric($left) && is_numeric($right)) {
            return (int) $left === (int) $right;
        }
        if (is_array($left) || is_array($right)) {
            return $left == $right;
        }

        return (string) $left === (string) $right;
    }
}
