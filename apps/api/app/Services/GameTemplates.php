<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * The starter systems a new game can be created from.
 *
 * A template is a game system document like any other — `templates/*.json`, validated by the
 * same schema and compiled by the same compiler — not a code path that knows how to build a
 * game. That is the difference between "new game" being data and being a feature: adding a
 * starter shape is adding a file.
 *
 * Instantiating one rewrites exactly three fields. Everything else is the designer's to
 * change in the system editor, which is the point of starting from one at all.
 */
final class GameTemplates
{
    public function __construct(private readonly string $directory) {}

    /**
     * Every template, as the "new game" picker needs it.
     *
     * @return list<array{id: string, name: string, summary: ?string, cardTypes: int, phases: int}>
     */
    public function all(): array
    {
        $templates = [];

        foreach ($this->files() as $id => $document) {
            $templates[] = [
                'id' => $id,
                'name' => (string) ($document['name'] ?? Str::headline($id)),
                'summary' => $document['summary'] ?? null,
                // Enough shape for the picker to say what you are choosing between without
                // opening the file: "1 card type, 3 phases" versus "3 card types, 4 phases".
                'cardTypes' => count($document['cardTypes'] ?? []),
                'phases' => count($document['round']['phases'] ?? []),
            ];
        }

        return $templates;
    }

    public function has(string $id): bool
    {
        return $this->path($id) !== null;
    }

    /**
     * A template as the system document of a game called `$name` at `$slug`.
     *
     * @return array<string, mixed>
     */
    public function instantiate(string $id, string $slug, string $name, ?string $summary = null): array
    {
        $path = $this->path($id);
        if ($path === null) {
            throw new \InvalidArgumentException("no such template: {$id}");
        }

        $document = $this->read($path);

        $document['id'] = $slug;
        $document['name'] = $name;
        $document['version'] = '0.1.0';

        if ($summary !== null && $summary !== '') {
            $document['summary'] = $summary;
        }

        // The relative `$schema` pointer is true of a file sitting in templates/ and false of
        // a document in a jsonb column, so it does not travel.
        unset($document['$schema']);

        return $document;
    }

    /** @return array<string, array<string, mixed>> keyed by template id */
    private function files(): array
    {
        $paths = glob(rtrim($this->directory, '/') . '/*.json') ?: [];
        sort($paths);

        $documents = [];
        foreach ($paths as $path) {
            $documents[basename($path, '.json')] = $this->read($path);
        }

        return $documents;
    }

    private function path(string $id): ?string
    {
        // A template id is a file name, so it must not be able to become a path.
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            return null;
        }

        $path = rtrim($this->directory, '/') . '/' . $id . '.json';

        return is_file($path) ? $path : null;
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $document;
    }
}
