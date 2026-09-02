<?php

declare(strict_types=1);

namespace Gmd\Harness\Loader;

use Gmd\Kernel\System\SystemCompiler;

/**
 * Reads a game from the filesystem and compiles it.
 *
 * All the I/O in the stack lives on this side of the boundary. The kernel is handed parsed
 * arrays, which is what lets the same kernel serve a CLI, an HTTP request, a queue worker
 * and a test with no bootstrapping — and, later, lets the application pass documents read
 * from jsonb columns instead of files with nothing else changing.
 */
final class FixtureLoader
{
    public function __construct(private readonly SystemCompiler $compiler = new SystemCompiler) {}

    public function load(string $gameDirectory): GameFixture
    {
        $path = rtrim($gameDirectory, '/');
        if (! is_dir($path)) {
            throw new \RuntimeException("no such game directory: {$path}");
        }

        $system = $this->readJson($path . '/game-system.json');
        $sets = array_values($this->readDirectory($path . '/sets'));

        return new GameFixture(
            $path,
            $this->compiler->compile($system, $sets),
            $this->readDirectory($path . '/decks'),
            $this->readDirectory($path . '/scenarios'),
            $this->readDirectory($path . '/encounter-sets'),
            $this->readDirectory($path . '/bots'),
        );
    }

    /** @return array<string, mixed> */
    public function readJson(string $file): array
    {
        if (! is_file($file)) {
            throw new \RuntimeException("no such file: {$file}");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$file} is not valid JSON: " . $e->getMessage(), previous: $e);
        }

        return $decoded;
    }

    /** @return array<string, array<string, mixed>> file stem => document, in name order */
    public function readDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '/*.json') ?: [];
        sort($files);

        $documents = [];
        foreach ($files as $file) {
            $documents[basename($file, '.json')] = $this->readJson($file);
        }

        return $documents;
    }

    /** The repository root, found by walking up from this package. */
    public static function repositoryRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    public static function examplePath(string $game): string
    {
        return self::repositoryRoot() . '/examples/' . $game;
    }
}
