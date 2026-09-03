<?php

declare(strict_types=1);

namespace Gmd\Kernel\Flow;

use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\System\StepDefinition;
use Gmd\Kernel\System\SystemDocument;

/**
 * The round: phases, steps, and what happens between them.
 *
 * A step is either an automatic script or a decision window, never both. The distinction is
 * the whole state machine: automatic steps run and move on, windows stop and wait for a
 * player, and everything the phase rail on the play table shows is generated from this
 * structure rather than drawn per game.
 *
 * A `repeatPerPlayer` step is the third shape a round takes. A duel interleaves priority, so
 * one window serves the whole table; a cooperative game gives each player a turn in
 * sequence, and the step runs once per player with `$player` bound and the active seat
 * moved to them (doc 16 §8). It is the same step either way — the flag decides how many
 * times it happens, not what it does.
 */
final class PhaseMachine
{
    /** Where a step is in its life: just entered, its script running, or open for actions. */
    public const STATE = '__stepState';

    public const ENTERED = 'entered';

    public const RUNNING = 'running';

    public const OPEN = 'open';

    /** How many actions — passes included — have been taken in the open window. */
    public const WINDOW_ACTIONS = '__windowActions';

    /** How far a `repeatPerPlayer` step has got round the table. */
    public const STEP_TURN = '__stepTurn';

    public function currentStep(Draft $draft, SystemDocument $system): StepDefinition
    {
        return $system->step($draft->phase() . '.' . $draft->step())
            ?? $system->firstStep();
    }

    /** Begin the step the state currently names. */
    public function enter(Draft $draft, SystemDocument $system, OpContext $context): void
    {
        $this->beginStep($draft, $this->currentStep($draft, $system), $context);
    }

    /**
     * Enter a step: reset its state, seat whoever takes it first, and announce it.
     *
     * Every path into a step goes through here — the next step, the next round, the next
     * player of a repeating step — so a step cannot be entered without its per-player
     * counter being right.
     */
    private function beginStep(Draft $draft, StepDefinition $step, OpContext $context): void
    {
        $draft->setVar(self::STATE, self::ENTERED);

        if ($step->repeatPerPlayer) {
            $draft->setVar(self::STEP_TURN, 0);
            $order = $this->turnOrder($draft);
            if ($order !== []) {
                $draft->setActiveSeat($order[0]);
            }
        } else {
            $draft->setVar(self::STEP_TURN, null);
        }

        $context->emit('step.began', array_filter([
            'phase' => $step->phaseId,
            'step' => $step->id,
            'player' => $step->repeatPerPlayer ? $draft->activeSide() : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * The seats in turn order, from the first player round the table.
     *
     * The same order `for_each_player` uses, and for the same reason: a script written for
     * two players has to behave at four without being rewritten.
     *
     * @return list<int>
     */
    private function turnOrder(Draft $draft): array
    {
        $seats = array_values(array_map(
            static fn ($player): int => $player->seat,
            array_filter($draft->players(), static fn ($p): bool => $p->isPlaying()),
        ));

        $at = array_search(Side::seatOf($draft->firstSide()), $seats, true);
        if ($at === false) {
            return $seats;
        }

        return [...array_slice($seats, (int) $at), ...array_slice($seats, 0, (int) $at)];
    }

    /** Start an automatic step's script as its own stack item. */
    public function runAuto(Draft $draft, StepDefinition $step): void
    {
        $draft->setVar(self::STATE, self::RUNNING);
        $draft->pushStack(new StackItem(
            id: $draft->nextId('stack', 's'),
            kind: StackItem::KIND_STEP,
            controller: $draft->activeSide(),
            frames: [new StackFrame($step->autoProgram())],
            bindings: $step->repeatPerPlayer
                ? ['you' => $draft->activeSide(), 'player' => $draft->activeSide()]
                : ['you' => $draft->activeSide()],
        ));
    }

    /** Open a decision window: priority to the active player, nobody has passed yet. */
    public function openWindow(Draft $draft, StepDefinition $step): void
    {
        $draft->setVar(self::STATE, self::OPEN);
        $draft->setVar(self::WINDOW_ACTIONS, 0);
        $draft->setConsecutivePasses(0);
        $draft->setPriority($this->firstToAct($draft, $step));
    }

    /**
     * Record that someone acted, and hand priority on.
     *
     * Both halves matter. Counting actions is how a single-action window knows it is done —
     * "declare an attacker" is one beat, not an open-ended conversation. Passing priority is
     * how an alternating window alternates; without it a player would keep the floor until
     * they chose to pass, which is not what "priority passes back and forth" means.
     */
    public function recordAction(Draft $draft, StepDefinition $step, bool $wasPass): void
    {
        $draft->setVar(self::WINDOW_ACTIONS, (int) $draft->var(self::WINDOW_ACTIONS, 0) + 1);
        $draft->setConsecutivePasses($wasPass ? $draft->consecutivePasses() + 1 : 0);

        if (($step->window?->type ?? null) === \Gmd\Kernel\System\WindowDefinition::ALTERNATING) {
            $this->passPriority($draft);
        }
    }

    /**
     * Move to the next player of this step, the next step, the next phase, or the next round.
     *
     * Rotating the first player at the round boundary is what `alternate` and `rotate` mean;
     * doing it here rather than in a script keeps a game from having to remember.
     */
    public function advance(Draft $draft, SystemDocument $system, OpContext $context): void
    {
        $step = $this->currentStep($draft, $system);
        $context->emit('step.ended', array_filter([
            'phase' => $step->phaseId,
            'step' => $step->id,
            'player' => $step->repeatPerPlayer ? $draft->activeSide() : null,
        ], static fn (mixed $v): bool => $v !== null));

        if ($this->handOnToNextPlayer($draft, $step, $context)) {
            return;
        }

        $next = $system->stepAfter($step->qualifiedId());

        if ($next === null) {
            $this->endRound($draft, $system, $context);

            return;
        }

        if ($next->phaseId !== $step->phaseId) {
            $context->emit('phase.ended', ['phase' => $step->phaseId]);
            $context->emit('phase.began', ['phase' => $next->phaseId]);
        }

        $draft->setPhaseStep($next->phaseId, $next->id);
        $this->beginStep($draft, $next, $context);
    }

    /**
     * Give a repeating step to the next player, or hand the table back when it has been round.
     *
     * The active seat is restored to the first player on the way out, so the steps that
     * follow — the villain's activation, cleanup — do not silently inherit whoever happened
     * to take the last turn.
     *
     * @return bool whether the step is repeating rather than ending
     */
    private function handOnToNextPlayer(Draft $draft, StepDefinition $step, OpContext $context): bool
    {
        if (! $step->repeatPerPlayer) {
            return false;
        }

        $order = $this->turnOrder($draft);
        $taken = (int) $draft->var(self::STEP_TURN, 0) + 1;

        if ($taken >= count($order)) {
            $draft->setVar(self::STEP_TURN, null);
            $draft->setActiveSeat(Side::seatOrFail($draft->firstSide()));

            return false;
        }

        $draft->setVar(self::STEP_TURN, $taken);
        $draft->setVar(self::STATE, self::ENTERED);
        $draft->setActiveSeat($order[$taken]);
        $context->emit('step.began', [
            'phase' => $step->phaseId,
            'step' => $step->id,
            'player' => $draft->activeSide(),
        ]);

        return true;
    }

    private function endRound(Draft $draft, SystemDocument $system, OpContext $context): void
    {
        $context->emit('phase.ended', ['phase' => $draft->phase()]);
        $context->emit('round.ended', ['round' => $draft->round()]);

        if (in_array($system->firstPlayerRule, ['alternate', 'rotate'], true)) {
            $seats = array_map(static fn ($player): int => $player->seat, $draft->players());
            $at = array_search(Side::seatOf($draft->firstSide()), $seats, true);
            $draft->setFirstSeat($seats[(($at === false ? 0 : (int) $at) + 1) % count($seats)]);
        }

        $draft->setActiveSeat(Side::seatOrFail($draft->firstSide()));
        $draft->setRound($draft->round() + 1);

        $first = $system->firstStep();
        $draft->setPhaseStep($first->phaseId, $first->id);

        $context->emit('round.began', ['round' => $draft->round()]);
        $context->emit('phase.began', ['phase' => $first->phaseId]);
        $this->beginStep($draft, $first, $context);
    }

    /**
     * Whether an open window has run its course.
     *
     * Two consecutive passes end an alternating window; anything else ends after a single
     * action, which is what makes "declare an attacker" a discrete beat rather than an
     * open-ended conversation.
     */
    public function windowIsOver(Draft $draft, StepDefinition $step): bool
    {
        $window = $step->window;
        if ($window === null) {
            return true;
        }

        // A repeating step is one player's turn, so only that player is in the window: it
        // ends when they pass once, not when the whole table has declined in succession.
        $active = $step->repeatPerPlayer
            ? 1
            : count(array_filter($draft->players(), static fn ($p): bool => $p->isPlaying()));
        $taken = (int) $draft->var(self::WINDOW_ACTIONS, 0);

        return match ($window->endOn ?? \Gmd\Kernel\System\WindowDefinition::defaultEndOn($window->type)) {
            'consecutive_passes' => $draft->consecutivePasses() >= max($step->repeatPerPlayer ? 1 : 2, $active),
            'all_submitted' => $taken >= $active,
            default => $taken >= 1,
        };
    }

    /** Which seat may act right now, or null when the window admits nobody. */
    public function firstToAct(Draft $draft, StepDefinition $step): ?int
    {
        $window = $step->window;
        if ($window === null) {
            return null;
        }

        return match ($window->type) {
            \Gmd\Kernel\System\WindowDefinition::DEFENDING_PLAYER => $this->defendingSeat($draft),
            default => Side::seatOf($draft->activeSide()),
        };
    }

    /** Pass priority to the next seat still playing. */
    public function passPriority(Draft $draft): void
    {
        $seats = array_values(array_map(
            static fn ($player): int => $player->seat,
            array_filter($draft->players(), static fn ($p): bool => $p->isPlaying()),
        ));
        if ($seats === []) {
            return;
        }

        $at = array_search($draft->priority(), $seats, true);
        $draft->setPriority($seats[(($at === false ? -1 : (int) $at) + 1) % count($seats)]);
    }

    /**
     * Whoever is being attacked.
     *
     * Read from the attacks declared this combat, so a defending-player window addresses the
     * right seat even at a table with more than two.
     */
    private function defendingSeat(Draft $draft): ?int
    {
        /** @var list<array{attacker: string, defender: string, blocker: ?string}> $attacks */
        $attacks = $draft->var(\Gmd\Kernel\Effect\Ops\DeclareAttackOp::ATTACKS, []);
        foreach ($attacks as $attack) {
            return Side::seatOf($attack['defender']);
        }

        return null;
    }
}
