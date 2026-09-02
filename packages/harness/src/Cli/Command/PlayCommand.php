<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli\Command;

use Gmd\Harness\Agent\RandomAgent;
use Gmd\Harness\Cli\Arguments;
use Gmd\Harness\Cli\Command;
use Gmd\Harness\Runner\MatchRunner;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\SeatSetup;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\State\Codec\StateHasher;

/** Play one headless match and report what happened. */
final class PlayCommand extends Command
{
    public function run(Arguments $arguments): int
    {
        $game = $this->game($arguments);
        $kernel = new Kernel($game->system);
        $seed = $arguments->integer('seed', 1);

        $deckNames = $game->deckNames();
        if ($deckNames === []) {
            throw new \RuntimeException("{$game->path} has no decks to play with");
        }

        $seats = [];
        $agents = [];
        for ($seat = 0; $seat < $game->system->minPlayers(); $seat++) {
            $seats[] = new SeatSetup($seat, $game->deck($deckNames[$seat % count($deckNames)]));
            $agents[$seat] = new RandomAgent(Pcg64Rng::at($seed * 1000 + $seat));
        }

        $started = hrtime(true);
        $outcome = (new MatchRunner($kernel, $agents))->run(new MatchSetup($seats, seed: $seed));
        $elapsed = (hrtime(true) - $started) / 1e6;

        $this->line();
        $this->line("  {$game->system->name} v{$game->system->version}  ·  seed {$seed}");
        $this->line('  ' . implode('  vs  ', array_map(
            fn (SeatSetup $s): string => (string) ($s->deck['name'] ?? 'deck'),
            $seats,
        )));
        $this->line();
        $this->line('  ' . $outcome->describe());
        $this->line(sprintf('  %d events in %.0fms', count($outcome->events), $elapsed));
        $this->line('  final state ' . StateHasher::hash($outcome->state));
        $this->line();

        if ($arguments->flag('log')) {
            foreach ($outcome->events as $event) {
                $this->line(sprintf('    %4d  %-22s %s', $event->seq, $event->type, json_encode($event->payload)));
            }
            $this->line();
        }

        $side = $arguments->option('view');
        if ($side !== null) {
            $this->line('  ' . json_encode($kernel->view($outcome->state, $side)->toArray(), JSON_PRETTY_PRINT));
        }

        return $outcome->stalled ? 1 : 0;
    }
}
