<?php

declare(strict_types=1);

namespace Gmd\Kernel\Legality;

use Gmd\Kernel\Budgets;
use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ActionList;
use Gmd\Kernel\Contract\LegalAction;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\Flow\PhaseMachine;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\StateView;
use Gmd\Kernel\System\ActionTemplate;
use Gmd\Kernel\System\SystemDocument;
use Gmd\Kernel\System\WindowDefinition;

/**
 * Everything a side may legally do, with targets resolved.
 *
 * This is the contract the whole UI rests on: the client greys out an unplayable card
 * because it is not in this list, not because it worked anything out. So the list has to be
 * exactly right in both directions — nothing offered that `apply()` would reject (doc 13's
 * invariant 7), and nothing legal left out.
 *
 * Costs are checked by dry-running the cost ops against a throwaway draft and seeing
 * whether they throw. That is deliberately the same code that will pay them for real: a
 * separate "can you afford this" implementation is a second opinion, and second opinions
 * about the rules diverge.
 */
final class LegalActionEnumerator
{
    /**
     * Results, keyed on the identity of the position they came from.
     *
     * Enumeration is the most-called expensive thing in the kernel: the driver asks for the
     * legal actions, `apply()` asks again to validate what came back, and the settle loop
     * asks a third time to decide whether a window is worth opening. Keyed on object
     * identity rather than on `version`, because version numbers repeat across the matches
     * of a simulation batch and a version-keyed cache would answer one match with another
     * match's board.
     *
     * @var \WeakMap<StateView, array<string, array{stamp: int, list: ActionList}>>
     */
    private \WeakMap $cache;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly PhaseMachine $phases,
        private readonly CostChecker $costs,
    ) {
        /** @var \WeakMap<StateView, array<string, array{stamp: int, list: ActionList}>> $cache */
        $cache = new \WeakMap;
        $this->cache = $cache;
    }

    public function legalActions(StateView $state, SystemDocument $system, string $side): ActionList
    {
        $stamp = $state instanceof Draft ? $state->mutationCounter : $state->version();
        $cached = $this->cache[$state][$side] ?? null;
        if ($cached !== null && $cached['stamp'] === $stamp) {
            return $cached['list'];
        }

        $list = $this->compute($state, $system, $side);

        $entry = $this->cache[$state] ?? [];
        $entry[$side] = ['stamp' => $stamp, 'list' => $list];
        $this->cache[$state] = $entry;

        return $list;
    }

    private function compute(StateView $state, SystemDocument $system, string $side): ActionList
    {
        if ($state->isOver() || $state->pendingChoice() !== null) {
            return new ActionList;
        }

        $step = $system->step($state->qualifiedStep());
        if ($step?->window === null) {
            return new ActionList;
        }

        if (! $this->mayAct($state, $system, $side)) {
            return new ActionList;
        }

        $actions = [];
        $truncated = false;

        foreach ($system->actionsForWindow($state->qualifiedStep()) as $template) {
            [$legal, $overflowed] = $this->enumerate($template, $state, $system, $side);
            $actions = [...$actions, ...$legal];
            $truncated = $truncated || $overflowed;
        }

        // Passing is always available. A window a player cannot leave is a deadlock, and
        // declining is a real decision in most of these games anyway.
        $actions[] = new LegalAction(Action::PASS, $side, [], 'Pass');

        return new ActionList($actions, $truncated);
    }

    /**
     * Could this window produce anything but a pass, from anyone who may act in it?
     *
     * A window where nobody has a real option is skipped rather than opened: `combat` in a
     * game with nothing on the board would otherwise ask two players to pass, twice, every
     * round.
     */
    public function windowHasSomethingToDo(StateView $state, SystemDocument $system, ?int $priority): bool
    {
        $step = $system->step($state->qualifiedStep());
        $window = $step?->window;
        if ($window === null) {
            return false;
        }

        $sides = $window->type === WindowDefinition::ALTERNATING || $window->type === WindowDefinition::SIMULTANEOUS
            ? array_map(static fn ($p): string => $p->side(), array_filter($state->players(), static fn ($p): bool => $p->isPlaying()))
            : ($priority === null ? [] : [Side::player($priority)]);

        foreach ($sides as $side) {
            foreach ($system->actionsForWindow($state->qualifiedStep()) as $template) {
                [$legal] = $this->enumerate($template, $state, $system, $side);
                if ($legal !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whose turn it is to act in the current window. */
    public function mayAct(StateView $state, SystemDocument $system, string $side): bool
    {
        $step = $system->step($state->qualifiedStep());
        $window = $step?->window;
        if ($window === null) {
            return false;
        }

        $seat = Side::seatOf($side);
        if ($seat === null || ! $state->playerBySide($side)->isPlaying()) {
            return false;
        }

        return match ($window->type) {
            WindowDefinition::SIMULTANEOUS => true,
            default => $state->priority() === $seat,
        };
    }

    /**
     * @return array{0: list<LegalAction>, 1: bool}
     */
    private function enumerate(
        ActionTemplate $template,
        StateView $state,
        SystemDocument $system,
        string $side,
    ): array {
        $base = $this->context($state, $system, $side);

        $combinations = [[]];
        $truncated = false;

        foreach ($template->targets as $target) {
            $id = (string) $target['id'];
            $next = [];

            foreach ($combinations as $chosen) {
                $scoped = $base->bindAll($this->targetBindings($chosen));
                $candidates = $this->runtime->queries->cards($target['query'] ?? [], $scoped);

                foreach ($candidates as $candidate) {
                    if (count($next) >= Budgets::TARGET_COMBINATIONS) {
                        $truncated = true;
                        break 2;
                    }
                    $next[] = [...$chosen, $id => $candidate];
                }
            }

            $combinations = $next;
            if ($combinations === []) {
                // A required target with no legal choice means the action cannot be taken.
                return [[], $truncated];
            }
        }

        $legal = [];
        foreach ($combinations as $params) {
            if (! $this->satisfiesRequirements($template, $params, $base)) {
                continue;
            }
            if ($template->hasCost && ! $this->costs->canPay($template, $params, $state, $system, $side)) {
                continue;
            }
            $legal[] = new LegalAction($template->id, $side, $params, $template->name);
        }

        return [$legal, $truncated];
    }

    /**
     * @param  array<string, string>  $params
     */
    private function satisfiesRequirements(ActionTemplate $template, array $params, EvalContext $base): bool
    {
        if ($template->requirements === []) {
            return true;
        }

        // Requirements are evaluated per combination, not once: Emberfall's declare_attack
        // asks whether *this* attacker may attack, which is unanswerable before a target is
        // chosen.
        $scoped = $base->bindAll($this->targetBindings($params));

        foreach ($template->requirements as $requirement) {
            if (! $scoped->evaluateBool($requirement)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function targetBindings(array $params): array
    {
        $bindings = [];
        foreach ($params as $id => $value) {
            $bindings['target.' . $id] = $value;
        }

        return $bindings;
    }

    private function context(StateView $state, SystemDocument $system, string $side): EvalContext
    {
        return new EvalContext(
            $state,
            $system,
            $this->runtime,
            new Bindings(['you' => $side]),
        );
    }
}
