<?php

declare(strict_types=1);

namespace Gmd\Harness\Runner;

use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\Agent;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\State\EventRecord;
use Gmd\Kernel\State\GameState;

/**
 * Plays a match to its end, headlessly.
 *
 * The loop is deliberately the same one the live table runs: legal actions, choose, apply,
 * settle. That is what makes a simulation batch and a playtest the same game — a bot and a
 * human take the same path through the same engine.
 */
final class MatchRunner
{
    /**
     * @param  array<int, Agent>  $agents  keyed by seat
     * @param  (callable(GameState): void)|null  $observe  called after every settled action,
     *                                                     which is where the fuzz harness
     *                                                     asserts the state's invariants
     */
    public function __construct(
        private readonly Kernel $kernel,
        private readonly array $agents,
        private readonly int $actionCap = 2000,
        private readonly mixed $observe = null,
    ) {}

    public function run(MatchSetup $setup): MatchOutcome
    {
        return $this->continueFrom($this->kernel->settle($this->kernel->begin($setup))->state);
    }

    public function continueFrom(GameState $state): MatchOutcome
    {
        /** @var list<Action> $log */
        $log = [];
        /** @var list<EventRecord> $events */
        $events = [];
        $actions = 0;

        while (! $state->isOver()) {
            if ($actions++ > $this->actionCap) {
                return new MatchOutcome($state, $log, $events, stalled: true);
            }

            $choice = $state->pendingChoice();
            if ($choice !== null) {
                $agent = $this->agentFor($choice->side);
                $response = $agent->resolveChoice($this->kernel->view($state, $choice->side), $choice);
                $result = $this->kernel->answer($state, $response);
                $state = $this->kernel->settle($result->state)->state;
                $events = [...$events, ...$result->events];
                $this->observe($state);

                continue;
            }

            $side = $this->sideToAct($state);
            if ($side === null) {
                // Nothing to decide and nothing settling: the game cannot move, which is a
                // kernel bug rather than a legitimate end state.
                return new MatchOutcome($state, $log, $events, stalled: true);
            }

            $legal = $this->kernel->legalActions($state, $side);
            $action = $this->agentFor($side)->chooseAction($this->kernel->view($state, $side), $legal);

            $applied = $this->kernel->apply($state, $action);
            $settled = $this->kernel->settle($applied->state);

            $log[] = $action;
            $events = [...$events, ...$applied->events, ...$settled->events];
            $state = $settled->state;
            $this->observe($state);
        }

        return new MatchOutcome($state, $log, $events);
    }

    private function observe(GameState $state): void
    {
        if ($this->observe !== null) {
            ($this->observe)($state);
        }
    }

    private function sideToAct(GameState $state): ?string
    {
        if ($state->priority === null) {
            return null;
        }

        $side = Side::player($state->priority);

        return $this->kernel->legalActions($state, $side)->isEmpty() ? null : $side;
    }

    private function agentFor(string $side): Agent
    {
        $seat = Side::seatOf($side);

        return $this->agents[$seat]
            ?? throw new \RuntimeException("no agent for side {$side}");
    }
}
