<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli;

use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Loader\GameFixture;

/** One `gmd` subcommand. */
abstract class Command
{
    abstract public function run(Arguments $arguments): int;

    /** Resolve a game name or path into a compiled fixture. */
    protected function game(Arguments $arguments, int $position = 0): GameFixture
    {
        $name = $arguments->at($position)
            ?? throw new \RuntimeException('which game? give a name under examples/, or a path');

        $path = is_dir($name) ? $name : FixtureLoader::examplePath($name);

        return (new FixtureLoader)->load($path);
    }

    protected function line(string $text = ''): void
    {
        echo $text, "\n";
    }
}
