<?php

declare(strict_types=1);

namespace Gmd\Harness\Loader;

use Gmd\Kernel\Contract\ScenarioSetup;
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

    /** @return list<string> */
    public function scenarioNames(): array
    {
        $names = array_keys($this->scenarios);
        sort($names);

        return $names;
    }

    /**
     * The scenario a match should be played against, or null for a game with no adversary.
     *
     * Named or not, the choice is explicit in the returned setup, so a replay records which
     * scenario was played rather than "whichever file sorted first at the time".
     */
    public function scenarioSetup(?string $name = null): ?ScenarioSetup
    {
        if ($this->system->adversaries === []) {
            return null;
        }

        $names = $this->scenarioNames();
        if ($names === []) {
            throw new \RuntimeException(
                "{$this->path} defines an adversary but has no scenarios to play against it",
            );
        }

        $name ??= $names[0];
        $document = $this->scenarios[$name]
            ?? throw new \RuntimeException("no scenario \"{$name}\" in {$this->path}");

        return ScenarioSetup::fromDocument(
            $document,
            $this->encounterSetsByCode(),
            $this->difficultySets((string) ($document['difficulty'] ?? '')),
        );
    }

    /**
     * Encounter sets keyed by the code a scenario names them with, which is not the file
     * name — `hollow.json` declares `wh-hollow`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function encounterSetsByCode(): array
    {
        $byCode = [];
        foreach ($this->encounterSets as $stem => $set) {
            $byCode[(string) ($set['code'] ?? $stem)] = $set;
        }

        return $byCode;
    }

    /**
     * The extra encounter sets a difficulty adds, which is the whole of what a difficulty is.
     *
     * @return list<string>
     */
    private function difficultySets(string $difficulty): array
    {
        foreach ($this->system->scenarioBuilding['difficulties'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $difficulty) {
                return array_values($candidate['encounterSets'] ?? []);
            }
        }

        return [];
    }
}
