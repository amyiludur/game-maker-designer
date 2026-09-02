<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli\Command;

use Gmd\Harness\Cli\Arguments;
use Gmd\Harness\Cli\Command;
use Gmd\Harness\Runner\FuzzFailure;
use Gmd\Harness\Runner\FuzzRunner;
use Gmd\Kernel\Kernel;

/**
 * Run random bots over many matches, asserting the state's invariants throughout.
 *
 * The milestone this is for: ten thousand Emberfall matches with zero violations. A clean
 * run does not prove the rules are right, but a dirty one proves they are wrong, and it
 * names the seed that shows it.
 */
final class FuzzCommand extends Command
{
    public function run(Arguments $arguments): int
    {
        $game = $this->game($arguments);
        $matches = $arguments->integer('matches', 200);
        $firstSeed = $arguments->integer('seed', 1);

        $this->line();
        $this->line("  fuzzing {$game->system->name} — {$matches} matches from seed {$firstSeed}");
        $this->line();

        $started = hrtime(true);
        $done = 0;

        $report = new FuzzRunner($game, new Kernel($game->system))->run(
            $matches,
            $firstSeed,
            function (int $seed, ?FuzzFailure $failure) use (&$done, $matches, $started): void {
                $done++;
                if ($failure !== null) {
                    $this->line('  ✗ ' . $failure->describe());
                }
                if ($done % 100 === 0 || $done === $matches) {
                    $rate = $done / ((hrtime(true) - $started) / 1e9) * 60;
                    $this->line(sprintf('    %d/%d  (%.0f matches/min)', $done, $matches, $rate));
                }
            },
        );

        $this->line();
        $this->line('  ' . str_replace("\n", "\n  ", $report->describe()));

        if ($report->roundCapRate() > 0.05) {
            // Doc 09's STALL threshold. It is a statement about competent play, and random
            // bots are not that — so this is a note rather than a failure. It becomes a real
            // finding once the heuristic bot is driving.
            $this->line(sprintf(
                '  note: %.0f%% of matches hit the round cap (random play, so expected; worth watching once bots play properly)',
                $report->roundCapRate() * 100,
            ));
        }
        $this->line();

        return $report->isClean() ? 0 : 1;
    }
}
