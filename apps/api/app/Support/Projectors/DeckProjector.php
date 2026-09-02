<?php

declare(strict_types=1);

namespace App\Support\Projectors;

use App\Models\DeckVersion;
use App\Services\DeckLegality;

/**
 * Derives a deck version's cached legality from its document.
 *
 * Same contract as {@see CardProjector}: the document is the truth, this is the only thing
 * that writes the derived column, and the column can be dropped and rebuilt from the
 * documents (`decks:reproject`). What it buys is a deck list that can show a legality glyph
 * per row without evaluating every game's deckbuilding constraints on every request.
 *
 * The bug this exists to prevent is the one it was written for: the list read the cached
 * column, the deck page recomputed, the importer wrote neither, and the same deck showed as
 * illegal in one place and legal in the other.
 */
final class DeckProjector
{
    public function __construct(private readonly DeckLegality $legality) {}

    /** @return array<string, mixed> the derived columns for a deck version */
    public function project(DeckVersion $version): array
    {
        $game = $version->deck?->game;

        if ($game === null) {
            return ['legality' => null];
        }

        return ['legality' => $this->legality->check($game, $version->document ?? [])];
    }

    public function apply(DeckVersion $version): void
    {
        foreach ($this->project($version) as $column => $value) {
            $version->setAttribute($column, $value);
        }
    }
}
