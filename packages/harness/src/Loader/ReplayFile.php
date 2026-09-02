<?php

declare(strict_types=1);

namespace Gmd\Harness\Loader;

use Gmd\Kernel\Contract\Side;

/**
 * A recorded match: the inputs it was played from, and the actions taken.
 *
 * Deliberately not a state dump. A replay carries the game version, the decks and the seed,
 * and the initial position is rebuilt from them — so a replay from six months ago
 * reproduces against the definition it was played under rather than against whatever the
 * cards say today.
 */
final readonly class ReplayFile
{
    /**
     * @param  list<array{seat: int, deck: string, agent?: string, label?: string}>  $seats
     * @param  list<array{seq: int, seat: int, actionId: string, params?: array<string, mixed>, choice?: array<string, mixed>, note?: string}>  $actions
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $expected
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public string $path,
        public string $gameId,
        public string $gameVersion,
        public int $seed,
        public array $seats,
        public array $actions,
        public array $config = [],
        public ?array $expected = null,
        public array $provenance = [],
        public string $mode = 'simulation',
        public ?string $matchId = null,
    ) {}

    /** @param array<string, mixed> $document */
    public static function fromArray(string $path, array $document): self
    {
        return new self(
            $path,
            (string) $document['gameId'],
            (string) $document['gameVersion'],
            (int) $document['seed'],
            $document['seats'] ?? [],
            $document['actions'] ?? [],
            $document['config'] ?? [],
            $document['expected'] ?? null,
            $document['provenance'] ?? [],
            (string) ($document['mode'] ?? 'simulation'),
            $document['matchId'] ?? null,
        );
    }

    public function isBlessed(): bool
    {
        return isset($this->expected['finalStateHash']);
    }

    /** @return list<string> deck file paths, in seat order */
    public function deckPaths(): array
    {
        $paths = [];
        foreach ($this->seats as $seat) {
            $paths[(int) $seat['seat']] = (string) $seat['deck'];
        }
        ksort($paths);

        return array_values($paths);
    }

    public function sideOf(int $seat): string
    {
        return Side::player($seat);
    }
}
