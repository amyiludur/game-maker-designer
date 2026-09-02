<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DeckVersion;
use App\Support\Projectors\DeckProjector;
use Illuminate\Console\Command;

/**
 * Rebuild every deck version's cached legality from its document.
 *
 * Needed whenever the thing legality depends on moves and the deck did not: a deckbuilding
 * constraint edited in the system document, or a card's faction changed. The deck rows are
 * untouched in both cases, so nothing else would notice.
 */
final class ReprojectDecks extends Command
{
    protected $signature = 'decks:reproject {--game= : Limit to one game id or slug}';

    protected $description = 'Rebuild deck legality from deck documents';

    public function handle(DeckProjector $projector): int
    {
        $query = DeckVersion::query()->with('deck.game');

        $game = $this->option('game');
        if ($game !== null) {
            $ids = \App\Models\Game::query()->where('slug', $game)->pluck('id');
            $query->whereIn('deck_id', \App\Models\Deck::query()->whereIn('game_id', $ids)->pluck('id'));
        }

        $changed = 0;
        $total = 0;

        $query->orderBy('id')->chunkById(200, function ($versions) use ($projector, &$changed, &$total): void {
            foreach ($versions as $version) {
                $total++;
                $projected = $projector->project($version);

                // Compared through a canonical encoding, because Postgres jsonb does not
                // preserve object key order: the value read back is equal to the value
                // written but not identical to it, and a naive comparison would report a
                // change on every run — which would make this command's idempotence, and
                // therefore the claim that the column is droppable, meaningless.
                if ($this->canonical($version->legality) === $this->canonical($projected['legality'])) {
                    continue;
                }

                $projector->apply($version);
                $version->saveQuietly();
                $changed++;

                $valid = ($projected['legality']['valid'] ?? false) === true ? 'legal' : 'illegal';
                $this->line("  {$version->deck?->name}: {$valid}");
            }
        });

        $this->info("reprojected {$total} deck version(s), {$changed} changed");

        return self::SUCCESS;
    }

    /** Key-sorted JSON, so two equal documents encode identically. */
    private function canonical(mixed $value): string
    {
        return (string) json_encode($this->sorted($value));
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(fn (mixed $item): mixed => $this->sorted($item), $value);

        // List order is data (the violations are ordered); object key order is not.
        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
