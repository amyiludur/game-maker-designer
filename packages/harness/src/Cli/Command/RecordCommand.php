<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli\Command;

use Gmd\Harness\Agent\RandomAgent;
use Gmd\Harness\Agent\RecordingAgent;
use Gmd\Harness\Cli\Arguments;
use Gmd\Harness\Cli\Command;
use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Runner\MatchRunner;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\SeatSetup;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\State\Codec\StateHasher;

/**
 * Play a match and write it out as a replay document.
 *
 * A conformance fixture has to be derived from a match that happened, not typed out from
 * what someone believed the engine does — that is the difference between a test that pins
 * behaviour and one that pins an assumption. The notes are still written by hand
 * afterwards, because "why this line is interesting" is not something a recorder knows.
 *
 * The document comes out blessed: the hashes in it are what this kernel produced on this
 * machine today. Re-recording an existing fixture to make a failing replay pass is
 * therefore exactly the wrong move, and worth saying out loud.
 */
final class RecordCommand extends Command
{
    public function run(Arguments $arguments): int
    {
        $game = $this->game($arguments);
        $kernel = new Kernel($game->system);
        $seed = $arguments->integer('seed', 1);
        $players = $arguments->integer('players', $game->system->minPlayers());
        $scenarioName = $arguments->option('scenario');
        $scenario = $game->scenarioSetup($scenarioName);

        $deckNames = $game->deckNames();
        if ($deckNames === []) {
            throw new \RuntimeException("{$game->path} has no decks to play with");
        }

        /** @var list<array<string, mixed>> $transcript */
        $transcript = [];

        $seats = [];
        $agents = [];
        $seatDocuments = [];
        for ($seat = 0; $seat < $players; $seat++) {
            $name = $deckNames[$seat % count($deckNames)];
            $seats[] = new SeatSetup($seat, $game->deck($name));
            $agents[$seat] = new RecordingAgent(
                new RandomAgent(Pcg64Rng::at($seed * 1000 + $seat)),
                $transcript,
            );
            $seatDocuments[] = [
                'seat' => $seat,
                'deck' => $this->relative($game->path . '/decks/' . $name . '.json'),
                'agent' => 'random',
                'label' => (string) ($game->deck($name)['name'] ?? $name),
            ];
        }

        $cap = $arguments->integer('actions', 2000);
        $outcome = new MatchRunner($kernel, $agents, actionCap: $cap)
            ->run(new MatchSetup($seats, seed: $seed, scenario: $scenario));

        $document = [
            '$schema' => '../../../schemas/replay.schema.json',
            'schemaVersion' => '1.0.0',
            'gameId' => $game->system->id,
            'gameVersion' => $game->system->version,
            'matchId' => null,
            'mode' => 'simulation',
            'seed' => $seed,
            'seats' => $seatDocuments,
            'actions' => $transcript,
            'expected' => [
                'finalStateHash' => StateHasher::hash($outcome->state),
                'rounds' => $outcome->rounds(),
                'reason' => $outcome->reason(),
                'winners' => $outcome->winners(),
            ],
        ];
        if ($scenario !== null) {
            $document['scenario'] = $scenarioName ?? $game->scenarioNames()[0];
        }

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $out = $arguments->option('out');
        if ($out === null) {
            echo $json;

            return 0;
        }

        file_put_contents($out, $json);

        $this->line();
        $this->line("  recorded {$game->system->name} seed {$seed} — " . $outcome->describe());
        $this->line('  ' . count($transcript) . " actions written to {$out}");
        $this->line('  annotate the interesting entries with a "note" before committing it');
        $this->line();

        return $outcome->stalled ? 1 : 0;
    }

    /** Replay documents name their decks relative to the repository root. */
    private function relative(string $path): string
    {
        $root = FixtureLoader::repositoryRoot() . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
