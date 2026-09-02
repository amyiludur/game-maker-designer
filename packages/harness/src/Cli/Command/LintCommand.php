<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli\Command;

use Gmd\Harness\Cli\Arguments;
use Gmd\Harness\Cli\Command;
use Gmd\Kernel\System\Lint;
use Gmd\Kernel\System\LintFinding;

/** Report the authoring mistakes a JSON Schema cannot catch. */
final class LintCommand extends Command
{
    public function run(Arguments $arguments): int
    {
        $game = $this->game($arguments);
        $findings = Lint::standard()->check($game->system);

        $this->line();
        $this->line("  linting {$game->system->name} v{$game->system->version}");
        $this->line();

        if ($findings === []) {
            $this->line('  ✓ nothing to report');
            $this->line();

            return 0;
        }

        $errors = 0;
        foreach ($findings as $finding) {
            $this->line('  ' . $finding->describe());
            if ($finding->fix !== null) {
                $this->line('          ' . $finding->fix);
            }
            if ($finding->severity === LintFinding::ERROR) {
                $errors++;
            }
        }

        $this->line();
        $this->line(sprintf('  %d finding(s), %d error(s)', count($findings), $errors));
        $this->line();

        return $errors > 0 ? 1 : 0;
    }
}
