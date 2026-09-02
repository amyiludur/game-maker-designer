<?php

declare(strict_types=1);

namespace Gmd\Kernel\Query;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\UnresolvedSelector;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\State\PlayerState;

/**
 * Turns a `$...` selector into the thing it names.
 *
 * Most selectors are simply bound — `$self`, `$each`, `$target.victim`. The rest are
 * derived from the position: `$opponent` depends on how many seats there are,
 * `$you.identity` on which card that seat's identity is, `$adversary.boss` on which card is
 * filling that anchor in this scenario. That last one is what lets a player card say "deal
 * 2 damage to the villain" without knowing which villain it is playing against.
 */
final class SelectorResolver
{
    public function resolve(string $selector, EvalContext $context): mixed
    {
        $bound = $context->bindings->resolvePrefix($selector);
        if ($bound !== null) {
            [$value, $rest] = $bound;

            return $rest === [] ? $value : $this->walk($value, $rest, $selector, $context);
        }

        $parts = explode('.', ltrim($selector, '$'));
        $head = array_shift($parts);

        $value = match ($head) {
            'shared' => Side::SHARED,
            'opponent' => $this->opponents($context),
            'active' => $context->state->activeSide(),
            'adversary' => $this->soleAdversary($context),
            // An attachment's host is always derivable from $self, so a card never has to
            // be handed it explicitly to say "the character I am attached to".
            'host' => $this->hostOf($context),
            default => throw UnresolvedSelector::because(
                "selector \"{$selector}\" is not bound in this context",
                ['selector' => $selector, 'bound' => array_keys($context->bindings->all())],
            ),
        };

        return $parts === [] ? $value : $this->walk($value, $parts, $selector, $context);
    }

    /** Resolve to exactly one value, failing loudly rather than silently picking one. */
    public function resolveOne(string $selector, EvalContext $context): mixed
    {
        $value = $this->resolve($selector, $context);
        if (is_array($value)) {
            if (count($value) === 1) {
                return array_values($value)[0];
            }

            throw UnresolvedSelector::because(
                "selector \"{$selector}\" names " . count($value) . ' things where one was needed',
                ['selector' => $selector],
            );
        }

        return $value;
    }

    /** @return list<string> */
    public function resolveMany(string $selector, EvalContext $context): array
    {
        $value = $this->resolve($selector, $context);
        if ($value === null) {
            return [];
        }

        return array_values(array_map(strval(...), is_array($value) ? $value : [$value]));
    }

    /**
     * @param  list<string>  $path
     */
    private function walk(mixed $value, array $path, string $selector, EvalContext $context): mixed
    {
        foreach ($path as $step) {
            if (is_array($value)) {
                // A path applied to a list distributes over it, so $opponent.identity in a
                // three-player game names all of their identities rather than failing.
                $value = array_values(array_filter(array_map(
                    fn (mixed $item): mixed => $this->step($item, $step, $selector, $context),
                    $value,
                ), static fn (mixed $v): bool => $v !== null));

                continue;
            }
            $value = $this->step($value, $step, $selector, $context);
        }

        return $value;
    }

    private function step(mixed $value, string $step, string $selector, EvalContext $context): mixed
    {
        if (! is_string($value)) {
            throw UnresolvedSelector::because(
                "selector \"{$selector}\" cannot resolve \"{$step}\" on a " . get_debug_type($value),
                ['selector' => $selector],
            );
        }

        // Anchors: $adversary.boss, or the anchor of a named adversary side.
        $adversary = $context->state->adversary($value);
        if ($adversary !== null) {
            return $adversary->anchors[$step]
                ?? throw UnresolvedSelector::because(
                    "adversary \"{$value}\" has no anchor \"{$step}\"",
                    ['selector' => $selector],
                );
        }

        if ($step === 'identity') {
            return $this->identityOf($value, $context);
        }

        if ($step === 'host') {
            return $context->state->hasInstance($value) ? $context->state->instance($value)->attachedTo : null;
        }

        if ($step === 'controller' && $context->state->hasInstance($value)) {
            return $context->state->instance($value)->controller;
        }

        if ($step === 'owner' && $context->state->hasInstance($value)) {
            return $context->state->instance($value)->owner;
        }

        throw UnresolvedSelector::because(
            "selector \"{$selector}\" cannot resolve \"{$step}\" on \"{$value}\"",
            ['selector' => $selector],
        );
    }

    private function identityOf(string $side, EvalContext $context): ?string
    {
        $seat = Side::seatOf($side);
        if ($seat === null) {
            return null;
        }

        foreach ($context->state->players() as $player) {
            if ($player->seat === $seat) {
                return $player->identityInstance;
            }
        }

        return null;
    }

    /**
     * Everyone who is not `$you`.
     *
     * A duel resolves to a single side id, which is what every two-player card text means.
     * More seats resolve to a list, so a query filtering on `$opponent` still reads
     * naturally at a four-player table.
     */
    private function opponents(EvalContext $context): string|array
    {
        $you = $context->bindings->get('you');
        $opponents = [];
        foreach ($context->state->players() as $player) {
            if ($player->side() !== $you && $player->isPlaying()) {
                $opponents[] = $player->side();
            }
        }

        return count($opponents) === 1 ? $opponents[0] : $opponents;
    }

    private function hostOf(EvalContext $context): ?string
    {
        $self = $context->bindings->get('self');
        if (! is_string($self) || ! $context->state->hasInstance($self)) {
            throw UnresolvedSelector::because('$host was used where $self is not a card');
        }

        return $context->state->instance($self)->attachedTo;
    }

    private function soleAdversary(EvalContext $context): string
    {
        $ids = array_keys($context->state->adversaries());
        if (count($ids) === 1) {
            return $ids[0];
        }

        throw UnresolvedSelector::because(
            $ids === []
                ? '$adversary was used in a game with no adversary'
                : '$adversary is ambiguous: this game has ' . count($ids) . ' adversaries',
        );
    }

    /** Convenience for the common "which seat is this" question. */
    public function seatOf(mixed $side, EvalContext $context): ?PlayerState
    {
        if (! is_string($side)) {
            return null;
        }
        $seat = Side::seatOf($side);
        if ($seat === null) {
            return null;
        }

        foreach ($context->state->players() as $player) {
            if ($player->seat === $seat) {
                return $player;
            }
        }

        return null;
    }
}
