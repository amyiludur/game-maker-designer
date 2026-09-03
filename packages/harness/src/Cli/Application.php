<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli;

use Gmd\Harness\Cli\Command\CompileCommand;
use Gmd\Harness\Cli\Command\FuzzCommand;
use Gmd\Harness\Cli\Command\LintCommand;
use Gmd\Harness\Cli\Command\PlayCommand;
use Gmd\Harness\Cli\Command\RecordCommand;
use Gmd\Harness\Cli\Command\ReplayCommand;
use Gmd\Kernel\Diagnostics\KernelException;

/** Command dispatch, argument parsing and error reporting for `gmd`. */
final class Application
{
    /** @var array<string, class-string<Command>> */
    private const COMMANDS = [
        'play' => PlayCommand::class,
        'replay' => ReplayCommand::class,
        'record' => RecordCommand::class,
        'fuzz' => FuzzCommand::class,
        'compile' => CompileCommand::class,
        'lint' => LintCommand::class,
    ];

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? '';
        if ($name === '' || $name === '--help' || $name === '-h') {
            $this->usage();

            return $name === '' ? 1 : 0;
        }

        $class = self::COMMANDS[$name] ?? null;
        if ($class === null) {
            fwrite(STDERR, "gmd: unknown command \"{$name}\"\n\n");
            $this->usage();

            return 1;
        }

        try {
            return (new $class)->run(new Arguments(array_slice($argv, 2)));
        } catch (KernelException $e) {
            // A kernel diagnostic already knows which card and which ability; printing it
            // whole is more useful to a designer than a stack trace.
            fwrite(STDERR, "\n  " . $e->diagnostic()->describe() . "\n");
            foreach ($e->diagnostic()->context as $key => $value) {
                fwrite(STDERR, "    {$key}: " . (is_scalar($value) ? (string) $value : json_encode($value)) . "\n");
            }
            fwrite(STDERR, "\n");

            return 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n  " . $e::class . ': ' . $e->getMessage() . "\n\n");

            return 1;
        }
    }

    private function usage(): void
    {
        echo <<<'TEXT'
        gmd — the Game Maker Designer rules kernel

        Usage:
          gmd play <game> [--seed=N] [--players=N] [--scenario=NAME] [--log] [--view=p0]
          gmd record <game> [--seed=N] [--players=N] [--scenario=NAME] [--out=FILE]
          gmd replay <file> [--bless] [--reason="..."]      Verify or record a golden replay
          gmd fuzz <game> [--matches=N] [--seed=N] [--players=N]
          gmd compile <game>                                Compile a game and report on it
          gmd lint <game>                                   Report authoring mistakes

        <game> is a directory under examples/, or a path to one. A cooperative game is
        played against a scenario; without --scenario the first one in the game is used.

        TEXT;
    }
}
