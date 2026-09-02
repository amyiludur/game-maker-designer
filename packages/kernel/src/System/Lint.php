<?php

declare(strict_types=1);

namespace Gmd\Kernel\System;

use Gmd\Kernel\Effect\OpRegistry;
use Gmd\Kernel\Event\EventCatalogue;

/**
 * The checks a JSON Schema cannot make.
 *
 * A schema can say an ability has a trigger; it cannot say the event that trigger names is
 * one the engine ever emits, or that the `$target.victim` an effect reads was actually
 * declared, or that a game without a round cap will hang a simulation batch forever. Those
 * are the mistakes that cost a designer an afternoon, and they are all findable at save
 * time.
 *
 * Deliberately does not repeat what `scripts/validate-examples.mjs` already checks in CI —
 * card types, vocabularies, deck legality, adversary zones. Two implementations of the same
 * rule is how they come to disagree.
 */
final class Lint
{
    public function __construct(private readonly OpRegistry $ops = new OpRegistry) {}

    public static function standard(): self
    {
        return new self(OpRegistry::standard());
    }

    /** @return list<LintFinding> */
    public function check(SystemDocument $system): array
    {
        return [
            ...$this->everyOpIsImplemented($system),
            ...$this->everySelectorIsDeclared($system),
            ...$this->everyTriggerEventExists($system),
            ...$this->modifiersStayInsideTheirGuardrails($system),
            ...$this->theGameCanEnd($system),
            ...$this->everythingDeclaredIsUsed($system),
        ];
    }

    /**
     * An op the kernel does not implement would be an UnknownOp mid-match, which is a
     * terrible time to find out.
     *
     * @return list<LintFinding>
     */
    private function everyOpIsImplemented(SystemDocument $system): array
    {
        $findings = [];
        foreach ($system->programs->refs() as $ref) {
            foreach ($this->effectOps($system->programs->root($ref)) as $op) {
                if (! $this->ops->has($op)) {
                    $findings[] = LintFinding::error(
                        'unknown-op',
                        "the kernel does not implement the op \"{$op}\"",
                        $ref,
                        'either the op name is a typo, or this game needs a primitive the engine has not grown yet',
                    );
                }
            }
        }

        return $this->unique($findings);
    }

    /**
     * A `$target.x` that no `targets` block declares can never resolve.
     *
     * @return list<LintFinding>
     */
    private function everySelectorIsDeclared(SystemDocument $system): array
    {
        $findings = [];

        foreach ($system->actions as $action) {
            $declared = array_map(static fn (array $t): string => (string) $t['id'], $action->targets);
            foreach ([$action->effectProgram(), $action->costProgram()] as $ref) {
                if ($system->programs->has($ref)) {
                    $findings = [...$findings, ...$this->undeclaredTargets(
                        $system->programs->root($ref),
                        $declared,
                        (string) $ref,
                    )];
                }
            }
        }

        foreach ($system->cards->cards as $card) {
            foreach ($card->faces as $face) {
                foreach ($face->abilities as $ability) {
                    $declared = array_map(static fn (array $t): string => (string) $t['id'], $ability->targets);
                    $ref = $ability->bodyProgram();
                    if ($system->programs->has($ref)) {
                        $findings = [...$findings, ...$this->undeclaredTargets(
                            $system->programs->root($ref),
                            $declared,
                            (string) $ref,
                        )];
                    }
                }
            }
        }

        return $this->unique($findings);
    }

    /**
     * @param  list<string>  $declared
     * @return list<LintFinding>
     */
    private function undeclaredTargets(mixed $node, array $declared, string $where): array
    {
        $findings = [];
        foreach ($this->selectors($node) as $selector) {
            if (! str_starts_with($selector, '$target.')) {
                continue;
            }
            $id = substr($selector, 8);
            if (! in_array($id, $declared, true)) {
                $findings[] = LintFinding::error(
                    'undeclared-target',
                    "\"{$selector}\" is used but no target with id \"{$id}\" is declared",
                    $where,
                );
            }
        }

        return $findings;
    }

    /**
     * A trigger on an event nothing emits is a card that silently never does anything.
     *
     * @return list<LintFinding>
     */
    private function everyTriggerEventExists(SystemDocument $system): array
    {
        $emitted = [];
        foreach ($system->actions as $action) {
            foreach ($action->emits as $event) {
                $emitted[$event] = true;
            }
        }

        $findings = [];
        foreach (array_keys($system->triggerIndex) as $event) {
            if (EventCatalogue::isCore($event)
                || isset($emitted[$event])
                || in_array($event, $system->declaredEvents, true)) {
                continue;
            }

            $findings[] = LintFinding::error(
                'unknown-event',
                "abilities trigger on \"{$event}\", which is not a core event and which nothing in this game emits",
                null,
                'declare it in the system document, or emit it from an action',
            );
        }

        return $findings;
    }

    /**
     * `modifiableAttributes` exists to catch "why is this card's *cost* being changed by a
     * +1 attack effect" at save time rather than in a playtest.
     *
     * @return list<LintFinding>
     */
    private function modifiersStayInsideTheirGuardrails(SystemDocument $system): array
    {
        $findings = [];

        foreach ($system->cards->cards as $card) {
            foreach ($card->faces as $face) {
                $type = $system->hasCardType($face->type) ? $system->cardType($face->type) : null;

                foreach ($face->abilities as $ability) {
                    $ref = $ability->bodyProgram();
                    if (! $system->programs->has($ref)) {
                        continue;
                    }
                    foreach ($this->modifyChanges($system->programs->root($ref)) as $attribute) {
                        if (in_array($attribute, ['controller', 'type', 'traits', 'keywords'], true)
                            || str_contains($attribute, '.')) {
                            continue;
                        }
                        // The guardrail is the *target's* type, which the linter cannot know
                        // in general — so this only flags a card modifying an attribute its
                        // own type declares but does not allow to be modified.
                        if ($type !== null
                            && $type->attribute($attribute) !== null
                            && ! $type->isModifiable($attribute)) {
                            $findings[] = LintFinding::warning(
                                'unmodifiable-attribute',
                                "modifies \"{$attribute}\", which {$type->id} does not list in modifiableAttributes",
                                $card->code . ' ' . $ability->id,
                            );
                        }
                    }
                }
            }
        }

        return $this->unique($findings);
    }

    /**
     * A game with no way to end is a simulation batch that never returns.
     *
     * @return list<LintFinding>
     */
    private function theGameCanEnd(SystemDocument $system): array
    {
        $findings = [];

        if ($system->winConditions === []) {
            $findings[] = LintFinding::error(
                'no-win-condition',
                'this game declares no win conditions, so no match can ever end',
            );
        }

        $hasCap = false;
        foreach ($system->winConditions as $condition) {
            if ($this->readsRound($condition->check)) {
                $hasCap = true;
            }
        }

        if (! $hasCap) {
            $findings[] = LintFinding::error(
                'no-round-cap',
                'no win condition reads the round number, so a stalled match would run forever',
                null,
                'add a condition on { "op": "round" } that ends the game in a draw',
            );
        }

        return $findings;
    }

    /** @return list<LintFinding> */
    private function everythingDeclaredIsUsed(SystemDocument $system): array
    {
        $mentioned = [];
        foreach ($system->programs->refs() as $ref) {
            foreach ($this->strings($system->programs->root($ref)) as $value) {
                $mentioned[$value] = true;
            }
        }

        $findings = [];
        foreach ($system->resources as $resource) {
            if (! isset($mentioned[$resource->id])) {
                $findings[] = LintFinding::warning(
                    'unused-resource',
                    "\"{$resource->id}\" is declared but nothing ever gains or spends it",
                );
            }
        }
        foreach ($system->counters as $counter) {
            if (! isset($mentioned[$counter->id])) {
                $findings[] = LintFinding::warning(
                    'unused-counter',
                    "\"{$counter->id}\" is declared but nothing ever puts one on a card",
                );
            }
        }
        foreach ($system->keywords as $keyword) {
            $carried = false;
            foreach ($system->cards->cards as $card) {
                foreach ($card->faces as $face) {
                    if ($face->hasKeyword($keyword->id)) {
                        $carried = true;
                    }
                }
            }
            if (! $carried) {
                $findings[] = LintFinding::info(
                    'unused-keyword',
                    "\"{$keyword->id}\" is defined but no card carries it",
                );
            }
        }

        return $findings;
    }

    /** @return list<string> */
    private function effectOps(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        if (isset($node['op']) && is_string($node['op'])) {
            $found[] = $node['op'];
        }
        foreach (['do', 'then', 'else', 'effect'] as $key) {
            foreach ($node[$key] ?? [] as $child) {
                $found = [...$found, ...$this->effectOps($child)];
            }
        }
        foreach ($node as $key => $child) {
            if (is_int($key)) {
                $found = [...$found, ...$this->effectOps($child)];
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function selectors(mixed $node): array
    {
        if (is_string($node)) {
            return str_starts_with($node, '$') ? [$node] : [];
        }
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $child) {
            $found = [...$found, ...$this->selectors($child)];
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> attribute ids touched by a `modify` op */
    private function modifyChanges(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        if (($node['op'] ?? null) === 'modify') {
            foreach ($node['changes'] ?? [] as $change) {
                if (isset($change['attr'])) {
                    $found[] = (string) $change['attr'];
                }
            }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = [...$found, ...$this->modifyChanges($child)];
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function strings(mixed $node): array
    {
        if (is_string($node)) {
            return [$node];
        }
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $child) {
            $found = [...$found, ...$this->strings($child)];
        }

        return array_values(array_unique($found));
    }

    private function readsRound(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        if (($node['op'] ?? null) === 'round') {
            return true;
        }
        foreach ($node as $child) {
            if ($this->readsRound($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<LintFinding>  $findings
     * @return list<LintFinding>
     */
    private function unique(array $findings): array
    {
        $seen = [];
        $out = [];
        foreach ($findings as $finding) {
            $key = $finding->rule . '|' . $finding->message . '|' . $finding->where;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $finding;
            }
        }

        return $out;
    }
}
