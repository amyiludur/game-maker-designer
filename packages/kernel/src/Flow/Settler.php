<?php

declare(strict_types=1);

namespace Gmd\Kernel\Flow;

use Gmd\Kernel\Budgets;
use Gmd\Kernel\Diagnostics\BudgetExceeded;
use Gmd\Kernel\Diagnostics\StateCheckLoop;
use Gmd\Kernel\Effect\EffectInterpreter;
use Gmd\Kernel\Effect\OpContext;
use Gmd\Kernel\Event\EventBus;
use Gmd\Kernel\Event\TriggerQueue;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\Runtime;
use Gmd\Kernel\Legality\LegalActionEnumerator;
use Gmd\Kernel\State\Draft;
use Gmd\Kernel\State\ProgramRef;
use Gmd\Kernel\State\StackFrame;
use Gmd\Kernel\State\StackItem;
use Gmd\Kernel\System\SystemDocument;

/**
 * Run the game forward until it needs a human.
 *
 * This is doc 07's loop, and the order of its clauses is the game's timing rules:
 * resolution before triggers, triggers before state checks, state checks before the game
 * moves on. Getting the order wrong does not crash anything — it just produces a game where
 * a creature that should have died gets to attack first.
 *
 * Every loop has a budget. A rules engine that can spin forever is worse than one that is
 * wrong: an unattended ten-thousand-match batch would hang instead of naming the two cards
 * that trigger each other.
 */
final class Settler
{
    public function __construct(
        private readonly EffectInterpreter $interpreter,
        private readonly EventBus $events,
        private readonly TriggerQueue $triggers,
        private readonly PhaseMachine $phases,
        private readonly StateCheckRunner $stateChecks,
        private readonly WinConditionRunner $winConditions,
        private readonly Runtime $runtime,
        private readonly LegalActionEnumerator $legality,
    ) {}

    public function settle(Draft $draft, SystemDocument $system): void
    {
        $checkPasses = 0;

        for ($iteration = 0; $iteration < Budgets::SETTLE_STEPS; $iteration++) {
            if ($draft->result() !== null || $draft->pendingChoice() !== null) {
                return;
            }

            // 1. Something is resolving. Finish it before anything else gets a say.
            if ($draft->stack() !== []) {
                if ($this->interpreter->step($draft, $system)) {
                    continue;
                }

                return;
            }

            // 2. Triggers that fired during that resolution go on the stack now.
            if ($this->triggers->promote($draft, $system)) {
                $checkPasses = 0;

                continue;
            }

            // 3. State checks, repeatedly, until the board is stable.
            if ($this->stateChecks->run($draft, $system, $this->context($draft, $system))) {
                if (++$checkPasses > Budgets::STATE_CHECK_ITERATIONS) {
                    throw StateCheckLoop::because(
                        'state checks kept firing past ' . Budgets::STATE_CHECK_ITERATIONS . ' passes',
                        ['phase' => $draft->phase(), 'step' => $draft->step()],
                    );
                }

                continue;
            }
            $checkPasses = 0;

            // 4. Has anyone won?
            $result = $this->winConditions->evaluate($draft, $system, $this->context($draft, $system));
            if ($result !== null) {
                $this->finish($draft, $system, $result);

                return;
            }

            // 5. Move the round along.
            if ($this->advanceFlow($draft, $system)) {
                continue;
            }

            return;
        }

        throw BudgetExceeded::because(
            'settle did not reach a stable position within ' . Budgets::SETTLE_STEPS . ' steps',
            ['phase' => $draft->phase(), 'step' => $draft->step(), 'round' => $draft->round()],
        );
    }

    /**
     * @return bool whether the flow moved; false means the game is waiting on a player
     */
    private function advanceFlow(Draft $draft, SystemDocument $system): bool
    {
        $context = $this->context($draft, $system);
        $step = $this->phases->currentStep($draft, $system);
        $state = (string) $draft->var(PhaseMachine::STATE, PhaseMachine::ENTERED);

        if ($draft->var('__endPhase') === true) {
            $draft->setVar('__endPhase', null);
            $this->skipToNextPhase($draft, $system, $context);

            return true;
        }

        if ($draft->var('__endStep') === true) {
            $draft->setVar('__endStep', null);
            $this->phases->advance($draft, $system, $context);

            return true;
        }

        if ($state === PhaseMachine::ENTERED) {
            if ($this->phases->needsBeginning($draft)) {
                $this->phases->enter($draft, $system, $context);

                return true;
            }

            if ($step->hasAuto) {
                $this->phases->runAuto($draft, $step);

                return true;
            }

            // A window nobody can do anything in is skipped rather than opened. Otherwise a
            // board with no characters would ask both players to pass through the whole
            // combat phase, every round, for the entire game.
            $priority = $this->phases->firstToAct($draft, $step);
            $skippable = $step->window?->skipIfNoActions ?? true;
            if ($priority === null
                || ($skippable && ! $this->legality->windowHasSomethingToDo($draft, $system, $priority))) {
                $this->phases->advance($draft, $system, $context);

                return true;
            }

            $this->phases->openWindow($draft, $step);

            return true;
        }

        if ($state === PhaseMachine::RUNNING) {
            $this->phases->advance($draft, $system, $context);

            return true;
        }

        // The window is open. It ends when the game says so; otherwise a player must act.
        if ($this->phases->windowIsOver($draft, $step)) {
            $this->phases->advance($draft, $system, $context);

            return true;
        }

        return false;
    }

    private function skipToNextPhase(Draft $draft, SystemDocument $system, OpContext $context): void
    {
        $phase = $draft->phase();
        do {
            $this->phases->advance($draft, $system, $context);
        } while ($draft->phase() === $phase && $draft->stack() === []);
    }

    private function finish(Draft $draft, SystemDocument $system, \Gmd\Kernel\State\MatchResult $result): void
    {
        $draft->setResult($result);
        $this->context($draft, $system)->emit('game.ended', [
            'winners' => $result->winners,
            'losers' => $result->losers,
            'reason' => $result->reason,
        ]);
    }

    /** A context for the engine's own actions, which belong to nobody in particular. */
    private function context(Draft $draft, SystemDocument $system): OpContext
    {
        return new OpContext(
            $draft,
            $system,
            $this->runtime,
            $this->events,
            new StackItem('engine', StackItem::KIND_STEP, $draft->activeSide(), []),
            new StackFrame(ProgramRef::system('engine')),
            new Bindings(['you' => $draft->activeSide()]),
        );
    }
}
