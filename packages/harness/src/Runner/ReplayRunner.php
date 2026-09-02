<?php

declare(strict_types=1);

namespace Gmd\Harness\Runner;

use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Loader\GameFixture;
use Gmd\Harness\Loader\ReplayFile;
use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\SeatSetup;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\State\Codec\StateHasher;
use Gmd\Kernel\State\GameState;

/**
 * Replays a recorded match and checks it still happens the same way.
 *
 * This is the conformance suite (ADR-0002). It proves three things at once: that the engine
 * is deterministic across machines and PHP versions, that a refactor did not quietly change
 * a rule, and — if a second kernel is ever written in another language — that it agrees.
 *
 * It rebuilds the opening position from the game version, the decks and the seed rather
 * than from a stored state, so the replay also proves the setup script still produces the
 * same board.
 */
final class ReplayRunner
{
    public function __construct(private readonly FixtureLoader $loader = new FixtureLoader) {}

    public function verify(ReplayFile $replay): ReplayResult
    {
        $game = $this->gameFor($replay);
        $kernel = new Kernel($game->system);

        if ($game->system->version !== $replay->gameVersion) {
            return ReplayResult::mismatch(sprintf(
                'replay was recorded against %s v%s, but the game is now v%s',
                $replay->gameId,
                $replay->gameVersion,
                $game->system->version,
            ));
        }

        $state = $kernel->settle($kernel->begin($this->setup($replay)))->state;

        $checkpoints = [];
        $problems = [];

        foreach ($replay->actions as $entry) {
            $seq = (int) $entry['seq'];
            $side = $replay->sideOf((int) $entry['seat']);

            if ($state->pendingChoice() !== null) {
                $problems[] = "seq {$seq}: the game is waiting on \"{$state->pendingChoice()?->key()}\", "
                    . 'which no earlier entry answered';
                break;
            }

            $action = new Action((string) $entry['actionId'], $side, $entry['params'] ?? []);
            $legal = $kernel->legalActions($state, $side);

            if ($legal->find($action) === null) {
                $problems[] = sprintf(
                    'seq %d: %s %s is not legal for %s in %s (on offer: %s)',
                    $seq,
                    $action->actionId,
                    json_encode($action->params),
                    $side,
                    $state->qualifiedStep(),
                    implode(', ', $legal->actionIds()),
                );
                break;
            }

            $state = $kernel->settle($kernel->apply($state, $action)->state)->state;
            $state = $this->answerChoices($kernel, $state, $entry, $seq, $problems);

            if ($problems !== []) {
                break;
            }

            $checkpoints[] = ['seq' => $seq, 'stateHash' => StateHasher::hash($state)];
        }

        return new ReplayResult($replay, $state, $checkpoints, $problems, $this->compare($replay, $state, $checkpoints));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $problems
     */
    private function answerChoices(Kernel $kernel, GameState $state, array $entry, int $seq, array &$problems): GameState
    {
        $answers = $entry['choice'] ?? [];
        $guard = 0;

        while ($state->pendingChoice() !== null && $guard++ < 32) {
            $choice = $state->pendingChoice();
            $selection = $answers[$choice->key()] ?? $answers[$choice->id] ?? null;

            if ($selection === null) {
                $problems[] = "seq {$seq}: the game asked \"{$choice->key()}\" ({$choice->prompt}) "
                    . 'and the replay has no answer for it';

                return $state;
            }

            $state = $kernel->settle($kernel->answer(
                $state,
                new ChoiceResponse($choice->id, is_array($selection) ? array_values($selection) : [$selection]),
            )->state)->state;
        }

        return $state;
    }

    /**
     * @param  list<array{seq: int, stateHash: string}>  $checkpoints
     * @return list<string>
     */
    private function compare(ReplayFile $replay, GameState $state, array $checkpoints): array
    {
        if (! $replay->isBlessed()) {
            return [];
        }

        $divergences = [];
        $expected = $replay->expected ?? [];

        $bySeq = [];
        foreach ($expected['checkpoints'] ?? [] as $checkpoint) {
            $bySeq[(int) $checkpoint['seq']] = (string) $checkpoint['stateHash'];
        }
        foreach ($checkpoints as $checkpoint) {
            $want = $bySeq[$checkpoint['seq']] ?? null;
            if ($want !== null && $want !== $checkpoint['stateHash']) {
                $divergences[] = sprintf(
                    'seq %d: expected %s but reached %s',
                    $checkpoint['seq'],
                    $want,
                    $checkpoint['stateHash'],
                );
                // The first divergence is the one that matters; everything after it is a
                // consequence, and printing fifty of them buries the cause.
                break;
            }
        }

        $finalHash = StateHasher::hash($state);
        if ($divergences === [] && isset($expected['finalStateHash']) && $expected['finalStateHash'] !== $finalHash) {
            $divergences[] = "final state: expected {$expected['finalStateHash']} but reached {$finalHash}";
        }

        return $divergences;
    }

    private function setup(ReplayFile $replay): MatchSetup
    {
        $seats = [];
        foreach ($replay->seats as $seat) {
            $seats[] = new SeatSetup(
                (int) $seat['seat'],
                $this->loader->readJson($this->resolve((string) $seat['deck'])),
                $seat['label'] ?? null,
                $seat['agent'] ?? null,
            );
        }

        return new MatchSetup($seats, $replay->seed, $replay->config);
    }

    private function gameFor(ReplayFile $replay): GameFixture
    {
        return $this->loader->load(FixtureLoader::examplePath($replay->gameId));
    }

    /** Deck paths in a replay are written relative to the repository root. */
    private function resolve(string $path): string
    {
        return str_starts_with($path, '/') ? $path : FixtureLoader::repositoryRoot() . '/' . $path;
    }
}
