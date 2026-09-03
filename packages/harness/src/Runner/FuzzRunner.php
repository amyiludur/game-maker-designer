<?php

declare(strict_types=1);

namespace Gmd\Harness\Runner;

use Gmd\Harness\Agent\RandomAgent;
use Gmd\Harness\Loader\GameFixture;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\SeatSetup;
use Gmd\Kernel\Diagnostics\InvariantChecker;
use Gmd\Kernel\Diagnostics\InvariantViolation;
use Gmd\Kernel\Diagnostics\KernelException;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\State\GameState;

/**
 * Random bots, many matches, every invariant checked after every action.
 *
 * Random play is the point: it reaches board positions no heuristic ever would, and it does
 * not need to be good at the game to prove the engine never breaks. A failing seed is worth
 * more than a passing thousand, so it is recorded in full rather than counted.
 *
 * A game that seats a range of players is fuzzed across the whole range rather than at its
 * minimum. Half the things that break in a cooperative game only exist at four seats — turn
 * order wrapping, per-player scaling, an activation that reveals once per player — and a run
 * that only ever plays solo would report them all clean.
 */
final class FuzzRunner
{
    /** @var list<string> */
    private array $deckNames;

    public function __construct(
        private readonly GameFixture $game,
        private readonly Kernel $kernel,
        private readonly InvariantChecker $invariants = new InvariantChecker,
        /** Seats per match, or null to sweep the range the game allows. */
        private readonly ?int $players = null,
    ) {
        $this->deckNames = $this->game->deckNames();
    }

    /**
     * @param  callable(int, FuzzFailure|null): void|null  $onMatch
     */
    public function run(int $matches, int $firstSeed = 1, ?callable $onMatch = null): FuzzReport
    {
        $failures = [];
        $rounds = [];
        $reasons = [];
        $stalls = 0;

        for ($i = 0; $i < $matches; $i++) {
            $seed = $firstSeed + $i;
            $failure = null;

            try {
                $outcome = $this->play($seed, $this->seats($i));
                $rounds[] = $outcome->rounds();
                $reasons[$outcome->reason()] = ($reasons[$outcome->reason()] ?? 0) + 1;
                if ($outcome->stalled) {
                    $stalls++;
                    $failure = new FuzzFailure($seed, 'the match stalled: no side could act and nothing was resolving', $outcome->state);
                }
            } catch (KernelException $e) {
                $failure = new FuzzFailure($seed, $e->diagnostic()->describe(), null, $e->diagnostic());
            } catch (\Throwable $e) {
                $failure = new FuzzFailure($seed, $e::class . ': ' . $e->getMessage());
            }

            if ($failure !== null) {
                $failures[] = $failure;
            }
            if ($onMatch !== null) {
                $onMatch($seed, $failure);
            }
        }

        return new FuzzReport($matches, $rounds, $reasons, $failures, $stalls);
    }

    /**
     * How many seats match number $index plays at.
     *
     * Cycling rather than randomising keeps a run reproducible and its coverage even: 200
     * matches of a 1-4 player game are 50 at each size, whatever the seed.
     */
    private function seats(int $index): int
    {
        if ($this->players !== null) {
            return $this->players;
        }

        $min = $this->game->system->minPlayers();
        $max = $this->game->system->maxPlayers();

        return $min + $index % max(1, $max - $min + 1);
    }

    private function play(int $seed, int $players): MatchOutcome
    {
        $agents = [];
        $seats = [];
        foreach (range(0, $players - 1) as $seat) {
            $agents[$seat] = new RandomAgent(Pcg64Rng::at($seed * 1000 + $seat));
            $seats[] = new SeatSetup($seat, $this->game->deck($this->deckNames[$seat % count($this->deckNames)]));
        }

        $setup = new MatchSetup($seats, seed: $seed, scenario: $this->game->scenarioSetup());

        $runner = new MatchRunner(
            $this->kernel,
            $agents,
            observe: fn (GameState $state) => $this->assertInvariants($state, $seed),
        );

        $state = $this->kernel->settle($this->kernel->begin($setup))->state;
        $this->assertInvariants($state, $seed);

        return $runner->continueFrom($state);
    }

    /**
     * Doc 13's invariants, after every action.
     *
     * Invariant 7 — legalActions never offers something apply() rejects — is checked for
     * free by construction: the agent picks from the list and apply() re-validates against
     * it, so a divergence surfaces as an IllegalAction and fails the seed. Invariant 8 is
     * checked here: an open window must give the player something to send back.
     */
    private function assertInvariants(GameState $state, int $seed): void
    {
        if (! $state->isOver() && $state->pendingChoice() === null && $state->priority() !== null) {
            $side = \Gmd\Kernel\Contract\Side::player($state->priority());
            if ($this->kernel->legalActions($state, $side)->isEmpty()) {
                throw InvariantViolation::because(
                    "a window is open for {$side} in {$state->qualifiedStep()} with nothing to send back",
                    ['seed' => $seed],
                );
            }
        }

        $violations = $this->invariants->check($state, $this->kernel->system());
        if ($violations !== []) {
            throw InvariantViolation::because(
                implode('; ', $violations),
                ['seed' => $seed],
            );
        }
    }
}
