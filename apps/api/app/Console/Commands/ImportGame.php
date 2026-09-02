<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BotProfile;
use App\Models\Card;
use App\Models\CardRevision;
use App\Models\CardSet;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\Workspace;
use App\Services\CardValidator;
use App\Services\GameCompiler;
use App\Services\SchemaValidator;
use App\Support\Projectors\CardProjector;
use App\Support\Projectors\DeckProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Load a game from the repository's example directory into the database.
 *
 * The examples are the platform's fixtures — the kernel's conformance target and the data
 * the UI is designed against — so importing them is how the application gets something real
 * to work with on the first run, rather than a hand-written seeder that drifts from what the
 * kernel is tested against.
 */
final class ImportGame extends Command
{
    protected $signature = 'games:import
        {path : A directory under examples/, or a path to one}
        {--workspace=examples : Workspace slug to import into}
        {--fresh : Replace the game if it is already imported}';

    protected $description = 'Import a game system, its sets, cards and decks from JSON';

    public function handle(
        SchemaValidator $validator,
        GameCompiler $compiler,
        CardProjector $projector,
        DeckProjector $deckProjector,
        CardValidator $cards,
    ): int {
        $path = $this->resolve((string) $this->argument('path'));
        if (! is_dir($path)) {
            $this->error("no such directory: {$path}");

            return self::FAILURE;
        }

        $system = $this->json($path . '/game-system.json');

        $violations = $validator->violations($system, 'game-system');
        if ($violations !== []) {
            $this->error('the game system document is not valid:');
            foreach ($violations as $violation) {
                $this->line("  {$violation['pointer']}  {$violation['message']}");
            }

            return self::FAILURE;
        }

        $workspace = $this->workspace();

        $game = DB::transaction(function () use ($path, $system, $workspace, $projector): Game {
            $existing = Game::query()->where('workspace_id', $workspace->id)->where('slug', $system['id'])->first();
            if ($existing !== null) {
                if (! $this->option('fresh')) {
                    $this->warn("{$system['id']} is already imported; pass --fresh to replace it");

                    return $existing;
                }
                $existing->forceDelete();
            }

            $game = Game::create([
                'workspace_id' => $workspace->id,
                'slug' => (string) $system['id'],
                'name' => (string) $system['name'],
                'summary' => $system['summary'] ?? null,
            ]);

            $version = GameVersion::create([
                'game_id' => $game->id,
                'semver' => (string) $system['version'],
                'status' => GameVersion::DRAFT,
                'document' => $system,
            ]);
            $game->update(['current_version_id' => $version->id]);

            $this->importSets($path, $game, $projector);
            $this->importDecks($path, $game, $version);
            $this->importBots($path, $game);

            return $game;
        });

        $version = $game->currentVersion;
        if ($version !== null) {
            $report = $compiler->refresh($version);
            $errors = $report['lint']['errors'] ?? 0;
            $this->line(sprintf(
                '  compiled: %s, %d lint error(s)',
                ($report['lint']['compiled'] ?? false) ? 'yes' : 'no',
                $errors,
            ));

            // After compilation, not inside the transaction above: legality is evaluated
            // against the compiled system, so it cannot be derived until there is one.
            $this->projectDecks($game, $deckProjector);

            // Cards are checked against their card type's compiled schema, which only
            // exists once the system has compiled. Reported rather than fatal: the import
            // has already written the rows, and a designer needs to see the bad card in the
            // editor to fix it.
            $bad = $this->reportCardViolations($game, $cards);
            if ($bad > 0) {
                return self::FAILURE;
            }
        }

        $this->info(sprintf(
            'imported %s — %d card(s) in %d set(s), %d deck(s), %d bot(s)',
            $game->name,
            $game->cards()->count(),
            $game->sets()->count(),
            $game->decks()->count(),
            BotProfile::query()->where('game_id', $game->id)->count(),
        ));

        return self::SUCCESS;
    }

    private function importSets(string $path, Game $game, CardProjector $projector): void
    {
        foreach ($this->jsonIn($path . '/sets') as $document) {
            $set = CardSet::create([
                'game_id' => $game->id,
                'code' => (string) $document['code'],
                'name' => (string) ($document['name'] ?? $document['code']),
                'release_order' => (int) ($document['releaseOrder'] ?? 1),
                'status' => (string) ($document['status'] ?? 'draft'),
                'document' => array_diff_key($document, ['cards' => null]),
            ]);

            foreach ($document['cards'] ?? [] as $cardDocument) {
                $card = new Card([
                    'game_id' => $game->id,
                    'set_id' => $set->id,
                    'code' => (string) $cardDocument['code'],
                    'document' => $cardDocument,
                    'status' => (string) ($cardDocument['design']['status'] ?? 'draft'),
                ]);
                $card->id = Str::uuid7()->toString();
                $projector->apply($card);
                $card->save();

                // Revision 1 is the card as imported, so a diff against "how it arrived" is
                // available from the first edit rather than from the second.
                $revision = CardRevision::create([
                    'card_id' => $card->id,
                    'revision' => 1,
                    'document' => $cardDocument,
                    'message' => 'imported',
                ]);
                $card->forceFill(['head_revision_id' => $revision->id])->saveQuietly();
            }
        }
    }

    private function importBots(string $path, Game $game): void
    {
        // The built-in random opponent is not this game's, but every game needs one to be
        // playable, and this is the command that makes a game playable.
        BotProfile::ensureRandom();

        foreach ($this->jsonIn($path . '/bots') as $name => $document) {
            BotProfile::create([
                'game_id' => $game->id,
                'name' => (string) ($document['name'] ?? $name),
                'strategy' => (string) ($document['strategy'] ?? 'random'),
                'config' => $document['config'] ?? [],
            ]);
        }
    }

    private function importDecks(string $path, Game $game, GameVersion $version): void
    {
        foreach ($this->jsonIn($path . '/decks') as $name => $document) {
            $deck = Deck::create([
                'game_id' => $game->id,
                'name' => (string) ($document['name'] ?? $name),
                'archetype' => $document['archetype'] ?? null,
                'notes' => $document['notes'] ?? null,
            ]);

            $deckVersion = DeckVersion::create([
                'deck_id' => $deck->id,
                'version' => 1,
                'game_version_id' => $version->id,
                'document' => $document,
            ]);
            $deck->update(['head_version_id' => $deckVersion->id]);
        }
    }

    /** @return int how many cards do not match their card type's schema */
    private function reportCardViolations(Game $game, CardValidator $validator): int
    {
        $bad = 0;

        foreach ($game->cards()->orderBy('code')->get() as $card) {
            $violations = $validator->violations($game, $card->document ?? []);
            if ($violations === []) {
                continue;
            }

            $bad++;
            $this->error("  {$card->code} is not valid:");
            foreach ($violations as $violation) {
                $this->line("    {$violation['pointer']}  {$violation['message']}");
            }
        }

        return $bad;
    }

    /** Cache each imported deck's legality, so the list and the deck page agree. */
    private function projectDecks(Game $game, DeckProjector $projector): void
    {
        foreach ($game->decks()->with('head')->get() as $deck) {
            $head = $deck->head;
            if ($head === null) {
                continue;
            }

            $projector->apply($head);
            $head->saveQuietly();
        }
    }

    private function workspace(): Workspace
    {
        return Workspace::ensure((string) $this->option('workspace'));
    }

    private function resolve(string $path): string
    {
        return is_dir($path) ? $path : rtrim((string) config('gmd.examples'), '/') . '/' . $path;
    }

    /** @return array<string, mixed> */
    private function json(string $file): array
    {
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, mixed>> */
    private function jsonIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $files = glob($directory . '/*.json') ?: [];
        sort($files);

        $documents = [];
        foreach ($files as $file) {
            $documents[basename($file, '.json')] = $this->json($file);
        }

        return $documents;
    }
}
