<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Card;
use App\Support\Projectors\CardProjector;
use Illuminate\Console\Command;

/**
 * Rebuild every card's index columns from its document.
 *
 * This command is what makes "the document is the truth" a fact rather than an aspiration
 * (ADR-0001): if the index columns can be dropped and rebuilt, then nothing important can
 * be living in them. It must be idempotent, and a test asserts that running it changes
 * nothing when the columns are already correct.
 */
final class ReprojectCards extends Command
{
    protected $signature = 'cards:reproject {--game= : Limit to one game id or slug}';

    protected $description = 'Rebuild card index columns from their documents';

    public function handle(CardProjector $projector): int
    {
        $query = Card::query();

        $game = $this->option('game');
        if ($game !== null) {
            $query->whereIn('game_id', \App\Models\Game::query()
                ->where('id', $game)->orWhere('slug', $game)->pluck('id'));
        }

        $changed = 0;
        $total = 0;

        $query->orderBy('id')->chunkById(500, function ($cards) use ($projector, &$changed, &$total): void {
            foreach ($cards as $card) {
                $total++;
                $projected = $projector->project($card);

                // Compared field by field rather than with isDirty(): a jsonb column read
                // back through an array cast is not identical to the array that was written,
                // so isDirty() reports a change where the value is the same. That would make
                // this command look non-idempotent when it is not, which is exactly the
                // claim the projection test is supposed to be checking.
                $differences = [];
                foreach ($projected as $column => $value) {
                    if ($this->differs($card->getAttribute($column), $value)) {
                        $differences[] = $column;
                    }
                }

                if ($differences === []) {
                    continue;
                }

                $projector->apply($card);
                $card->saveQuietly();
                $changed++;
                $this->line("  {$card->code}: " . implode(', ', $differences));
            }
        });

        $this->info("reprojected {$total} card(s), {$changed} changed");

        return self::SUCCESS;
    }

    private function differs(mixed $stored, mixed $projected): bool
    {
        if (is_array($stored) || is_array($projected)) {
            return json_encode($stored ?? []) !== json_encode($projected ?? []);
        }

        return $stored !== $projected;
    }
}
