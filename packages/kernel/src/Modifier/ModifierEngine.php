<?php

declare(strict_types=1);

namespace Gmd\Kernel\Modifier;

use Gmd\Kernel\Budgets;
use Gmd\Kernel\Diagnostics\ModifierCycle;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\Instance;
use Gmd\Kernel\State\ModifierRecord;
use Gmd\Kernel\State\StateView;
use Gmd\Kernel\System\CompiledAbility;
use Gmd\Kernel\System\SystemDocument;

/**
 * The layer system (ADR-0004).
 *
 * Continuous effects do not overwrite each other in the order they happened; they apply in
 * a fixed order of kinds — copy, control, type, abilities, set, add, multiply, counters,
 * permissions — and only within a layer does timestamp order decide. That is what makes
 * "set attack to 1" and "+1 attack" combine to 2 rather than to whichever was played last.
 *
 * Layers:
 *   0 base (printed)   1 copy         2 control      3 type/trait   4 abilities
 *   5 set              6 add          7 multiply     8 counters     9 permissions
 *
 * Two kinds of modifier reach layer 5 and above, and the difference is deliberate:
 * modifiers stored in the state resolved their targets when they were created (Bolster
 * picks its card then), while modifiers derived from a static ability are recomputed every
 * time (an aura must pick up a card played later, and let go of it when its source leaves).
 */
final class ModifierEngine
{
    /**
     * Whole-board results, keyed on the object identity of the position they came from.
     *
     * Keyed on identity rather than on `version`, because version numbers repeat across the
     * ten thousand matches of a simulation batch and a version-keyed cache would serve
     * match 1's board to match 2 — silently, and only in the batch.
     *
     * @var \WeakMap<StateView, array{stamp: int, board: array<string, CharacteristicSet>}>
     */
    private \WeakMap $cache;

    /**
     * The partially-applied board, while a layer walk is in progress.
     *
     * An aura's query filters on traits and types, which are themselves layer results — so
     * resolving "which cards does this modifier apply to" needs to read characteristics,
     * and computing characteristics needs to resolve that query. The cycle is broken the
     * way the layer system intends: a modifier at layer N sees the board as it stands after
     * layer N-1, which is exactly what this holds.
     *
     * @var array<string, CharacteristicSet>|null
     */
    private ?array $computing = null;

    /**
     * Printed characteristics per (card, face, player count).
     *
     * Every instance of Cinder Scout has the same printed attributes, traits and keywords,
     * and a board rebuild happens after nearly every mutation — so working them out once
     * per card rather than once per copy per rebuild is most of the cost of the layer walk.
     *
     * @var array<string, array{0: array<string, mixed>, 1: list<string>, 2: list<string>, 3: string}>
     */
    private array $printedCache = [];

    public function __construct()
    {
        /** @var \WeakMap<StateView, array{stamp: int, board: array<string, CharacteristicSet>}> $cache */
        $cache = new \WeakMap;
        $this->cache = $cache;
    }

    public function characteristics(EvalContext $context, string $instanceId): CharacteristicSet
    {
        if ($this->computing !== null && isset($this->computing[$instanceId])) {
            return $this->computing[$instanceId];
        }

        $board = $this->board($context);

        return $board[$instanceId]
            ?? throw \Gmd\Kernel\Diagnostics\BadDocument::because("no card instance \"{$instanceId}\"");
    }

    public function attribute(EvalContext $context, string $instanceId, string $attribute): mixed
    {
        return $this->characteristics($context, $instanceId)->attribute($attribute);
    }

    /** The printed value, before any continuous effect. */
    public function baseAttribute(EvalContext $context, string $instanceId, string $attribute): mixed
    {
        return $this->printed($context, $context->state->instance($instanceId))[$attribute] ?? null;
    }

    /**
     * Every card's current characteristics, computed together.
     *
     * Whole-board rather than per-card because layer dependencies make a single card's
     * computation touch the others anyway, and because enumerating legal actions reads
     * dozens of cards in a row.
     *
     * @return array<string, CharacteristicSet>
     */
    public function board(EvalContext $context): array
    {
        $state = $context->state;
        $stamp = $state instanceof Draft ? $state->mutationCounter : $state->version();

        $cached = $this->cache[$state] ?? null;
        if ($cached !== null && $cached['stamp'] === $stamp) {
            return $cached['board'];
        }

        $board = $this->compute($context);
        $this->cache[$state] = ['stamp' => $stamp, 'board' => $board];

        return $board;
    }

    public function breakdown(EvalContext $context, string $instanceId, string $attribute): ModifierBreakdown
    {
        $instance = $context->state->instance($instanceId);
        $printed = $this->printed($context, $instance)[$attribute] ?? null;
        $steps = [];
        $value = $printed;

        foreach ($this->activeModifiers($context) as $modifier) {
            if (! in_array($instanceId, $this->targetsOf($modifier, $context), true)) {
                continue;
            }
            foreach ($modifier->changes as $change) {
                if (($change['attr'] ?? null) !== $attribute) {
                    continue;
                }
                $amount = $context->evaluate($change['value'] ?? null);
                $value = $this->applyChange($value, (string) ($change['mode'] ?? 'add'), $amount);
                $steps[] = [
                    'layer' => $modifier->layer,
                    'source' => $modifier->source,
                    'sourceName' => $this->sourceName($context, $modifier->source),
                    'mode' => (string) ($change['mode'] ?? 'add'),
                    'amount' => $amount,
                    'result' => $value,
                    'duration' => $modifier->duration,
                ];
            }
        }

        return new ModifierBreakdown($instanceId, $attribute, $printed, $value, $steps);
    }

    /**
     * @return array<string, CharacteristicSet>
     */
    private function compute(EvalContext $context): array
    {
        $base = $this->baseBoard($context);
        $previous = $this->computing;
        $this->computing = $base;

        try {
            $modifiers = $this->activeModifiers($context);

            // If nothing a modifier computes can depend on the board, one pass is provably
            // enough and the fixed-point loop is pure overhead. Both example games take
            // this path; the loop exists for the games that will not.
            $passes = $this->anyValueDependsOnState($modifiers) ? Budgets::MODIFIER_PASSES : 1;

            $board = $base;
            $fingerprint = null;

            for ($pass = 0; $pass < $passes; $pass++) {
                $board = $this->applyLayers($context, $modifiers, $base);
                $next = $this->fingerprint($board);
                if ($next === $fingerprint) {
                    return $board;
                }
                $fingerprint = $next;
            }

            if ($passes > 1 && $this->fingerprint($this->applyLayers($context, $modifiers, $base)) !== $fingerprint) {
                throw ModifierCycle::because(
                    'continuous effects did not settle within ' . Budgets::MODIFIER_PASSES . ' passes',
                    ['modifiers' => array_map(
                        fn (ModifierRecord $m): string => $m->id . ' from ' . $this->sourceName($context, $m->source),
                        $modifiers,
                    )],
                );
            }

            return $board;
        } finally {
            $this->computing = $previous;
        }
    }

    /**
     * Layer 0: what the card is printed as, before anything touches it.
     *
     * @return array<string, CharacteristicSet>
     */
    private function baseBoard(EvalContext $context): array
    {
        $players = $context->state->playerCount();
        $board = [];

        foreach ($context->state->instances() as $id => $instance) {
            // The digest is in the key because one engine may outlive one game: two systems
            // can have a card with the same code and different printed values.
            $key = $context->system->digest . '|' . $instance->code . '@' . $instance->face . '@' . $players;
            $face = $context->system->cards->get($instance->code)->face($instance->face);

            if (! isset($this->printedCache[$key])) {
                $this->printedCache[$key] = [
                    $this->printed($context, $instance),
                    $face->traits(),
                    array_map(static fn (array $k): string => (string) $k['id'], $face->keywords),
                    $face->name,
                ];
            }
            [$attributes, $traits, $keywords, $name] = $this->printedCache[$key];

            $board[$id] = new CharacteristicSet(
                instanceId: $id,
                name: $name,
                attributes: $attributes,
                types: [$face->type],
                traits: $traits,
                keywords: $keywords,
                permissions: $face->permissions,
                restrictions: $face->restrictions,
                abilities: $face->abilities,
                controller: $instance->controller,
            );
        }

        return $board;
    }

    /**
     * Walk the layers, lowest first.
     *
     * The board is republished into $this->computing after every modifier, so the next
     * one's query sees the results of everything below it. That is the whole point of an
     * ordered layer system: "set attack to 1" has to happen before "+1 attack" no matter
     * which was created first, and a trait granted at layer 3 has to be visible to an aura
     * that filters on traits at layer 6.
     *
     * @param  list<ModifierRecord>  $modifiers  already sorted by (layer, timestamp, id)
     * @param  array<string, CharacteristicSet>  $base
     * @return array<string, CharacteristicSet>
     */
    private function applyLayers(EvalContext $context, array $modifiers, array $base): array
    {
        $working = $base;
        $this->computing = $working;

        foreach ($modifiers as $modifier) {
            foreach ($this->targetsOf($modifier, $context) as $target) {
                if (isset($working[$target])) {
                    $working[$target] = $this->applyModifier($working[$target], $modifier, $context);
                }
            }
            $this->computing = $working;
        }

        return $working;
    }

    private function applyModifier(CharacteristicSet $set, ModifierRecord $modifier, EvalContext $context): CharacteristicSet
    {
        $attributes = $set->attributes;
        $types = $set->types;
        $traits = $set->traits;
        $keywords = $set->keywords;
        $permissions = $set->permissions;
        $restrictions = $set->restrictions;
        $controller = $set->controller;

        foreach ($modifier->changes as $change) {
            $attribute = (string) ($change['attr'] ?? '');
            $mode = (string) ($change['mode'] ?? 'add');
            $value = $context->bind('self', $modifier->source)->evaluate($change['value'] ?? null);

            if (str_starts_with($attribute, 'permission.')) {
                $permissions[substr($attribute, 11)] = (bool) $value;

                continue;
            }
            if (str_starts_with($attribute, 'restriction.')) {
                $restrictions[substr($attribute, 12)] = (bool) $value;

                continue;
            }

            match ($attribute) {
                'controller' => $controller = (string) $value,
                'type' => $types = array_values(array_map(strval(...), is_array($value) ? $value : [$value])),
                'traits' => $traits = $this->applyList($traits, $mode, $value),
                'keywords' => $keywords = $this->applyList($keywords, $mode, $value),
                default => $attributes[$attribute] = $this->applyChange($attributes[$attribute] ?? 0, $mode, $value),
            };
        }

        return new CharacteristicSet(
            $set->instanceId,
            $set->name,
            $attributes,
            $types,
            $traits,
            $keywords,
            $permissions,
            $restrictions,
            $set->abilities,
            $controller,
        );
    }

    /**
     * @param  list<string>  $current
     * @return list<string>
     */
    private function applyList(array $current, string $mode, mixed $value): array
    {
        $values = array_map(strval(...), is_array($value) ? $value : [$value]);

        return match ($mode) {
            'set' => array_values($values),
            'remove' => array_values(array_diff($current, $values)),
            default => array_values(array_unique([...$current, ...$values])),
        };
    }

    private function applyChange(mixed $current, string $mode, mixed $amount): mixed
    {
        // Rules maths is integer-only (ADR-0005): "half, rounded up" has to be written out,
        // and a division that silently produced 2.5 attack would be a bug we never saw.
        return match ($mode) {
            'set' => $amount,
            'multiply' => is_numeric($current) && is_numeric($amount)
                ? intdiv((int) $current * (int) $amount, 1)
                : $current,
            default => is_numeric($current) && is_numeric($amount)
                ? (int) $current + (int) $amount
                : $current,
        };
    }

    /**
     * Every modifier in force: those stored in the state, plus those derived from static
     * abilities on cards that are currently where their `activeWhile` says they must be.
     *
     * @return list<ModifierRecord>
     */
    private function activeModifiers(EvalContext $context): array
    {
        $modifiers = $context->state->modifiers();

        foreach ($this->derivedModifiers($context) as $derived) {
            $modifiers[] = $derived;
        }

        usort($modifiers, static fn (ModifierRecord $a, ModifierRecord $b): int => [$a->layer, $a->timestamp, $a->id] <=> [$b->layer, $b->timestamp, $b->id]);

        return $modifiers;
    }

    /**
     * Modifiers that exist because a card is on the table, not because an effect happened.
     *
     * These are never stored. Warhorn Bearer's "your Soldiers get +1" has to notice a
     * Soldier played three turns later and has to stop applying the moment Warhorn leaves
     * play; a stored modifier could do neither without bookkeeping that would drift.
     *
     * @return list<ModifierRecord>
     */
    private function derivedModifiers(EvalContext $context): array
    {
        $derived = [];
        $continuous = $context->system->continuousAbilityCodes();

        foreach ($context->state->instances() as $id => $instance) {
            // Most cards on the table have no continuous ability at all; the compiler
            // already knows which do, so the rest are skipped without touching their faces.
            if (! isset($continuous[$instance->code])) {
                continue;
            }

            $face = $context->system->cards->get($instance->code)->face($instance->face);
            foreach ($face->abilities as $ability) {
                if (! $ability->isContinuous() || ! $ability->hasEffect) {
                    continue;
                }
                $abilityContext = $context->bindAll([
                    'self' => $id,
                    'you' => $instance->controller,
                    'param' => $ability->params,
                ])->pure();

                if ($ability->activeWhile !== null && ! $abilityContext->evaluateBool($ability->activeWhile)) {
                    continue;
                }
                foreach ($this->staticChanges($context->system, $ability) as $index => $node) {
                    $derived[] = new ModifierRecord(
                        id: 'derived:' . $id . '#' . $ability->id . '/' . $index,
                        source: $id,
                        layer: $this->layerFor($node),
                        timestamp: $this->entryTimestamp($context->state, $id),
                        changes: $node['changes'] ?? [],
                        targets: isset($node['target'])
                            ? $context->runtime->selectors->resolveMany((string) $node['target'], $abilityContext)
                            : null,
                        query: $node['query'] ?? null,
                        duration: ModifierRecord::DURATION_WHILE_SOURCE_IN_PLAY,
                        abilityId: $ability->id,
                    );
                }
            }
        }

        return $derived;
    }

    /**
     * The `modify` nodes in a static ability's effect.
     *
     * A static ability's body is a description of a state of affairs rather than a sequence
     * of steps, so only the modify nodes at its top level are meaningful here.
     *
     * @return list<array<string, mixed>>
     */
    private function staticChanges(SystemDocument $system, CompiledAbility $ability): array
    {
        $ref = $ability->bodyProgram();
        if (! $system->programs->has($ref)) {
            return [];
        }

        $nodes = [];
        foreach ($system->programs->root($ref) as $node) {
            if (is_array($node) && ($node['op'] ?? null) === 'modify') {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** @param array<string, mixed> $node */
    private function layerFor(array $node): int
    {
        if (isset($node['layer'])) {
            return (int) $node['layer'];
        }

        $modes = array_map(
            static fn (array $change): string => (string) ($change['mode'] ?? 'add'),
            $node['changes'] ?? [],
        );

        // A modifier lands in the layer its kind of change belongs to, so that "set to 1"
        // always resolves before "+1" no matter which was created first.
        if (in_array('multiply', $modes, true)) {
            return 7;
        }
        if (in_array('set', $modes, true)) {
            return 5;
        }

        return 6;
    }

    private function entryTimestamp(StateView $state, string $instanceId): int
    {
        /** @var array<string, int> $entries */
        $entries = $state->var('__enteredAt', []);

        return $entries[$instanceId] ?? 0;
    }

    /**
     * @param  list<ModifierRecord>  $modifiers
     * @return list<string>
     */
    private function targetsOf(ModifierRecord $modifier, EvalContext $context): array
    {
        if ($modifier->targets !== null) {
            return $modifier->targets;
        }
        if ($modifier->query === null) {
            return [];
        }

        $sourceContext = $context->bindAll([
            'self' => $modifier->source,
            'you' => $context->state->hasInstance($modifier->source)
                ? $context->state->instance($modifier->source)->controller
                : $context->state->activeSide(),
        ])->pure();

        return $context->runtime->queries->cards($modifier->query, $sourceContext);
    }

    /** @param list<ModifierRecord> $modifiers */
    private function anyValueDependsOnState(array $modifiers): bool
    {
        foreach ($modifiers as $modifier) {
            foreach ($modifier->changes as $change) {
                if (is_array($change['value'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Printed attributes, with per-player scaling applied.
     *
     * A villain printed at 15 health has 30 in a two-player game (doc 16 §5). Doing it here
     * rather than at setup keeps the card face honest — the printed number really is 15 —
     * and makes the scaling recompute if a player is eliminated.
     *
     * @return array<string, mixed>
     */
    private function printed(EvalContext $context, Instance $instance): array
    {
        $face = $context->system->cards->get($instance->code)->face($instance->face);
        $type = $context->system->cardType($face->type);
        $attributes = $face->attributes;

        foreach ($type->attributes as $id => $definition) {
            if ($definition->perPlayer && isset($attributes[$id]) && is_numeric($attributes[$id])) {
                $attributes[$id] = (int) $attributes[$id] * $context->state->playerCount();
            }
        }

        return $attributes;
    }

    private function sourceName(EvalContext $context, string $source): string
    {
        if (! $context->state->hasInstance($source)) {
            return $source;
        }
        $instance = $context->state->instance($source);

        return $context->system->cards->get($instance->code)->name($instance->face);
    }

    /** @param array<string, CharacteristicSet> $board */
    private function fingerprint(array $board): string
    {
        $parts = [];
        foreach ($board as $id => $set) {
            $parts[] = $id . '=' . $set->fingerprint();
        }
        sort($parts);

        return implode('|', $parts);
    }
}
