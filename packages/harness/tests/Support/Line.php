<?php

declare(strict_types=1);

namespace Gmd\Harness\Tests\Support;

use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Loader\ReplayFile;
use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\SeatSetup;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\State\GameState;

/**
 * Plays a recorded replay up to a given point.
 *
 * Behaviour tests assert about a real position part-way through the conformance line rather
 * than reconstructing an approximation of it, so they cannot drift away from the fixture
 * they are supposed to be explaining.
 */
final class Line
{
    public function __construct(
        public readonly Kernel $kernel,
        public readonly GameState $state,
        public readonly ReplayFile $replay,
    ) {}

    /** Play the conformance replay up to and including `$throughSeq` (0 for the opening). */
    public static function emberfall(int $throughSeq): self
    {
        $loader = new FixtureLoader;
        $path = FixtureLoader::repositoryRoot() . '/examples/emberfall/replays/round-one-opening.json';
        $replay = ReplayFile::fromArray($path, $loader->readJson($path));

        $game = Examples::emberfall();
        $kernel = new Kernel($game->system);
        $state = $kernel->settle($kernel->begin(new MatchSetup(
            [new SeatSetup(0, $game->deck('ember-aggro')), new SeatSetup(1, $game->deck('ash-control'))],
            seed: $replay->seed,
        )))->state;

        foreach ($replay->actions as $entry) {
            if ((int) $entry['seq'] > $throughSeq) {
                break;
            }

            $state = $kernel->settle($kernel->apply(
                $state,
                new Action((string) $entry['actionId'], Side::player((int) $entry['seat']), $entry['params'] ?? []),
            )->state)->state;

            $guard = 0;
            while ($state->pendingChoice() !== null && $guard++ < 16) {
                $choice = $state->pendingChoice();
                $answers = $entry['choice'] ?? [];
                $selection = $answers[$choice->key()] ?? $answers[$choice->id] ?? null;
                if ($selection === null) {
                    throw new \RuntimeException("no scripted answer for choice \"{$choice->key()}\" at seq {$entry['seq']}");
                }
                $state = $kernel->settle($kernel->answer(
                    $state,
                    new ChoiceResponse($choice->id, is_array($selection) ? array_values($selection) : [$selection]),
                )->state)->state;
            }
        }

        return new self($kernel, $state, $replay);
    }

    /** The card named by one of the replay's action params, e.g. seq 1's `card`. */
    public function cardAt(int $seq, string $param = 'card'): string
    {
        foreach ($this->replay->actions as $entry) {
            if ((int) $entry['seq'] === $seq) {
                return (string) ($entry['params'][$param] ?? throw new \RuntimeException("seq {$seq} has no {$param}"));
            }
        }

        throw new \RuntimeException("no action at seq {$seq}");
    }

    public function attack(string $instanceId): int
    {
        $runtime = \Gmd\Kernel\Expr\Runtime::make();
        $context = new \Gmd\Kernel\Expr\EvalContext(
            $this->state,
            $this->kernel->system(),
            $runtime,
            new \Gmd\Kernel\Expr\Bindings,
        );

        return (int) $runtime->modifiers->attribute($context, $instanceId, 'attack');
    }
}
