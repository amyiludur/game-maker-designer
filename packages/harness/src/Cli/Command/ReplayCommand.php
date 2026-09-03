<?php

declare(strict_types=1);

namespace Gmd\Harness\Cli\Command;

use Gmd\Harness\Cli\Arguments;
use Gmd\Harness\Cli\Command;
use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Loader\ReplayFile;
use Gmd\Harness\Runner\ReplayRunner;

/**
 * Verify a golden replay, or record one.
 *
 * Blessing an already-blessed replay needs `--reason`, because that is the moment a
 * deliberate rules change gets written down. Doc 13 asks for exactly that: when a golden
 * replay breaks, either the change was a mistake or it was intended, and re-blessing
 * without saying which loses the only record of the difference.
 */
final class ReplayCommand extends Command
{
    public function run(Arguments $arguments): int
    {
        $path = $arguments->at(0) ?? throw new \RuntimeException('which replay file?');
        $loader = new FixtureLoader;
        $replay = ReplayFile::fromArray($path, $loader->readJson($path));

        $this->line();
        $this->line("  {$replay->gameId} v{$replay->gameVersion}  ·  seed {$replay->seed}  ·  " . count($replay->actions) . ' actions');
        $this->line('  ' . $path);
        $this->line();

        $result = new ReplayRunner($loader)->verify($replay);

        foreach ($result->problems as $problem) {
            $this->line('  ✗ ' . $problem);
        }
        foreach ($result->divergences as $divergence) {
            $this->line('  ✗ ' . $divergence);
        }

        if ($result->problems !== []) {
            $this->line();
            $this->line('  the replay could not be run to the end');
            $this->line();

            return 1;
        }

        $this->line(sprintf('  ran %d/%d actions', count($result->checkpoints), count($replay->actions)));
        $this->line('  final state ' . $result->finalHash());

        if ($arguments->flag('bless')) {
            return $this->bless($arguments, $replay, $result, $path);
        }

        if (! $replay->isBlessed()) {
            $this->line();
            $this->line('  this replay carries no expected hashes yet — run again with --bless to record them');
            $this->line();

            return 0;
        }

        $this->line();
        $this->line($result->isClean() ? '  ✓ reproduces exactly' : '  ✗ diverged');
        $this->line();

        return $result->isClean() ? 0 : 1;
    }

    private function bless(
        Arguments $arguments,
        ReplayFile $replay,
        \Gmd\Harness\Runner\ReplayResult $result,
        string $path,
    ): int {
        $reason = $arguments->option('reason');

        if ($replay->isBlessed() && $reason === null) {
            $this->line();
            $this->line('  this replay is already blessed. Re-blessing it records a deliberate rules');
            $this->line('  change, so it needs --reason="..." saying what changed and why.');
            $this->line();

            return 1;
        }

        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $document['expected'] = $result->expectedBlock($arguments->integer('checkpoint-every', 1));

        if ($reason !== null) {
            $document['provenance'] = [...($document['provenance'] ?? []), 'reason' => $reason];
        }

        file_put_contents($path, json_encode($this->writable($document), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->line();
        $this->line('  ✓ recorded ' . count($result->checkpoints) . ' checkpoints');
        $this->line();

        return 0;
    }

    /**
     * Drop the empty object-typed fields before writing.
     *
     * Blessing rewrites the whole document, and PHP cannot tell `{}` from `[]` across a
     * decode — so an empty `config` written by a recorder came back as an array and stopped
     * validating against a schema that says object. Omitting it says the same thing and
     * cannot be ambiguous.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function writable(array $document): array
    {
        foreach (['config', 'provenance'] as $key) {
            if (($document[$key] ?? null) === []) {
                unset($document[$key]);
            }
        }

        return $document;
    }
}
