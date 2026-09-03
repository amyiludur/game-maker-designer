<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

use Gmd\Kernel\Diagnostics\BadDocument;

/**
 * What a cooperative table is playing against.
 *
 * A duel is fully described by its seats: two decks and a seed. A scenario is not — the
 * same four decks face a different game depending on which villain is on the table and
 * which encounter sets are shuffled together (doc 16 §3). This is that second half of the
 * setup, and like a deck it is pinned into the match so a replay from three months ago
 * still reproduces.
 *
 * Encounter cards arrive already expanded into a flat list, in document order, because
 * instance ids are allocated from that order before anything is shuffled — the same reason
 * `SeatSetup::cardCodes()` expands a deck.
 */
final readonly class ScenarioSetup
{
    /**
     * @param  array<string, string>  $anchors  anchor id => card code
     * @param  list<string>  $encounterCards  card codes, expanded by count, in document order
     */
    public function __construct(
        public string $adversary,
        public array $anchors,
        public array $encounterCards = [],
        public ?string $id = null,
        public ?string $difficulty = null,
    ) {}

    /**
     * Read a scenario document, resolving its encounter set codes against the sets given.
     *
     * The difficulty's own sets are appended to the scenario's, which is the whole of what
     * "expert" means in this format: the same scenario with more in the deck.
     *
     * @param  array<string, mixed>  $scenario
     * @param  array<string, array<string, mixed>>  $encounterSets  set code => set document
     * @param  list<string>  $difficultySets  extra set codes the chosen difficulty adds
     */
    public static function fromDocument(array $scenario, array $encounterSets, array $difficultySets = []): self
    {
        /** @var array<string, string> $anchors */
        $anchors = $scenario['anchors'] ?? [];

        /** @var list<string> $codes */
        $codes = [...($scenario['encounterSets'] ?? []), ...$difficultySets];

        $cards = [];
        foreach ($codes as $code) {
            $set = $encounterSets[$code] ?? throw BadDocument::because(
                "scenario \"{$scenario['id']}\" names encounter set \"{$code}\", which was not supplied",
                ['encounterSet' => $code, 'available' => array_keys($encounterSets)],
            );
            foreach ($set['cards'] ?? [] as $entry) {
                for ($i = 0, $n = (int) ($entry['count'] ?? 1); $i < $n; $i++) {
                    $cards[] = (string) $entry['code'];
                }
            }
        }

        return new self(
            (string) $scenario['adversary'],
            $anchors,
            $cards,
            isset($scenario['id']) ? (string) $scenario['id'] : null,
            isset($scenario['difficulty']) ? (string) $scenario['difficulty'] : null,
        );
    }
}
