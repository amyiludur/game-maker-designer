<?php

declare(strict_types=1);

namespace Gmd\Harness\Loader;

use Gmd\Kernel\System\SystemDocument;

/** A game loaded from disk: the compiled rules plus the content authored against them. */
final readonly class GameFixture
{
    /**
     * @param  array<string, array<string, mixed>>  $decks  file stem => deck document
     * @param  array<string, array<string, mixed>>  $scenarios
     * @param  array<string, array<string, mixed>>  $encounterSets
     * @param  array<string, array<string, mixed>>  $bots
     */
    public function __construct(
        public string $path,
        public SystemDocument $system,
        public array $decks = [],
        public array $scenarios = [],
        public array $encounterSets = [],
        public array $bots = [],
    ) {}

    /** @return array<string, mixed> */
    public function deck(string $name): array
    {
        return $this->decks[$name]
            ?? throw new \RuntimeException("no deck \"{$name}\" in {$this->path}");
    }

    /** @return list<string> */
    public function deckNames(): array
    {
        $names = array_keys($this->decks);
        sort($names);

        return $names;
    }
}
