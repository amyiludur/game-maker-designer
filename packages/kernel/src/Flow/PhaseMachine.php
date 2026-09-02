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
 */
final class PhaseMachine
{
    /** Where a step is in its life: just entered, its script running, or open for actions. */
    public const STATE = '__stepState';

    public const ENTERED = 'entered';

    public const RUNNING = 'running';

    public const OPEN = 'open';

    public function currentStep(Draft $draft, SystemDocument $system): StepDefinition
    {
        return $system->step($draft->phase() . '.' . $draft->step())
            ?? $system->firstStep();
    }

    /** Begin the step the state currently names. */
    public function enter(Draft $draft, SystemDocument $system, OpContext $context): void
    {
        $step = $this->currentStep($draft, $system);
        $draft->setVar(self::STATE, self::ENTERED);
        $context->emit('step.began', ['phase' => $step->phaseId, 'step' => $step->id]);
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
            bindings: ['you' => $draft->activeSide()],
        ));
    }

    /** Open a decision window: priority to the active player, nobody has passed yet. */
    public function openWindow(Draft $draft, StepDefinition $step): void
    {
        $draft->setVar(self::STATE, self::OPEN);
        $draft->setConsecutivePasses(0);
        $draft->setPriority($this->firstToAct($draft, $step));
    }

    /**
     * Move to the next step, or the next phase, or the next round.
     *
     * Rotating the first player at the round boundary is what `alternate` and `rotate` mean;
     * doing it here rather than in a script keeps a game from having to remember.
     */
    public function advance(Draft $draft, SystemDocument $system, OpContext $context): void
    {
        $step = $this->currentStep($draft, $system);
        $context->emit('step.ended', ['phase' => $step->phaseId, 'step' => $step->id]);

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
        $draft->setVar(self::STATE, self::ENTERED);
        $context->emit('step.began', ['phase' => $next->phaseId, 'step' => $next->id]);
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
        $draft->setVar(self::STATE, self::ENTERED);

        $context->emit('round.began', ['round' => $draft->round()]);
        $context->emit('phase.began', ['phase' => $first->phaseId]);
        $context->emit('step.began', ['phase' => $first->phaseId, 'step' => $first->id]);
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

        $active = count(array_filter($draft->players(), static fn ($p): bool => $p->isPlaying()));

        return match ($window->endOn ?? \Gmd\Kernel\System\WindowDefinition::defaultEndOn($window->type)) {
            'consecutive_passes' => $draft->consecutivePasses() >= max(2, $active),
            'all_submitted' => $draft->consecutivePasses() >= $active,
            default => $draft->consecutivePasses() >= 1,
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
