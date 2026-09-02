<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotProfile;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\Workspace;
use App\Services\GameCompiler;
use App\Services\GameTemplates;
use App\Services\SchemaValidator;
use App\Services\SystemImpact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Games and their versions. */
final class GameController extends Controller
{
    public function index(): JsonResponse
    {
        $games = Game::query()->with('currentVersion')->orderBy('name')->get();

        return response()->json([
            'data' => $games->map(fn (Game $game): array => $this->summarise($game))->all(),
            'meta' => ['total' => $games->count()],
        ]);
    }

    /** The starter systems a new game can be built on. */
    public function templates(GameTemplates $templates): JsonResponse
    {
        return response()->json(['data' => $templates->all()]);
    }

    /**
     * Create a game from a starter template.
     *
     * The template supplies a system that compiles and lints clean, so the game is playable
     * shape from its first minute and the designer edits rather than assembles. A first set
     * comes with it, because a card has to be authored into something.
     */
    public function store(
        Request $request,
        GameTemplates $templates,
        SchemaValidator $validator,
        GameCompiler $compiler,
    ): JsonResponse {
        $name = trim((string) $request->input('name', ''));
        $template = (string) $request->input('template', 'blank');
        $slug = Str::slug((string) $request->input('slug', $name));

        if ($name === '') {
            return $this->refuse('missing_name', 'a game needs a name', 422);
        }
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $slug)) {
            return $this->refuse(
                'invalid_slug',
                "\"{$slug}\" cannot be a game id; it must start with a letter and hold only letters, digits and hyphens",
                422,
            );
        }
        if (! $templates->has($template)) {
            return $this->refuse('unknown_template', "there is no \"{$template}\" template", 422);
        }
        if (Game::query()->where('slug', $slug)->exists()) {
            return $this->refuse('slug_taken', "a game called \"{$slug}\" already exists", 409);
        }

        $document = $templates->instantiate($template, $slug, $name, $request->input('summary'));

        // The template is validated on the way in as well as in CI: this is the one place a
        // bad template would become a broken game rather than a red build.
        $violations = $validator->violations($document, 'game-system');
        if ($violations !== []) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_document',
                    'message' => "the \"{$template}\" template is not a valid game system",
                    'details' => ['violations' => $violations],
                ],
            ], 422);
        }

        $game = DB::transaction(function () use ($document, $name, $slug, $request): Game {
            $game = Game::create([
                'workspace_id' => Workspace::ensure((string) $request->input('workspace', 'examples'))->id,
                'slug' => $slug,
                'name' => $name,
                'summary' => $document['summary'] ?? null,
            ]);

            $version = GameVersion::create([
                'game_id' => $game->id,
                'semver' => (string) $document['version'],
                'status' => GameVersion::DRAFT,
                'document' => $document,
            ]);
            $game->update(['current_version_id' => $version->id]);

            CardSet::create([
                'game_id' => $game->id,
                'code' => 'core',
                'name' => 'Core Set',
                'release_order' => 1,
                'status' => 'draft',
                'document' => [
                    'schemaVersion' => '1.0.0',
                    'code' => 'core',
                    'gameId' => $slug,
                    'name' => 'Core Set',
                    'releaseOrder' => 1,
                    'status' => 'draft',
                ],
            ]);

            // Every game needs an opponent to be playable at all, and the random one is
            // game-agnostic — it plays whatever `legalActions` offers.
            BotProfile::ensureRandom();

            return $game;
        });

        $compiler->refresh($game->currentVersion);

        return response()->json(['data' => $this->summarise($game->refresh()->load('currentVersion'))], 201);
    }

    public function show(Game $game): JsonResponse
    {
        $game->load('currentVersion');

        return response()->json(['data' => [
            ...$this->summarise($game),
            'sets' => $game->sets()->orderBy('release_order')->get()
                ->map(fn ($set): array => [
                    'id' => $set->id,
                    'code' => $set->code,
                    'name' => $set->name,
                    'cardCount' => $set->cards()->count(),
                    'budget' => $set->budget(),
                ])->all(),
        ]]);
    }

    /** The system document itself. */
    public function version(Game $game, GameVersion $version): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $version->id,
            'semver' => $version->semver,
            'status' => $version->status,
            'document' => $version->document,
        ]]);
    }

    /**
     * The compiled bundle: per-card-type schemas, form descriptors, the phase graph.
     *
     * This is what makes the card editor build its own forms — a game with completely
     * different attributes needs no frontend change, because the frontend renders what this
     * says the game's cards have.
     */
    public function compiled(Game $game, GameVersion $version): JsonResponse
    {
        if ($version->compiled === null) {
            app(GameCompiler::class)->refresh($version);
            $version->refresh();
        }

        return response()->json(['data' => $version->compiled ?? []]);
    }

    public function lint(Game $game, GameVersion $version): JsonResponse
    {
        return response()->json(['data' => $version->lint ?? ['compiled' => false, 'findings' => []]]);
    }

    /**
     * What a proposed system document would break — asked before it is saved.
     *
     * A POST that writes nothing, because the question is about a document that does not
     * exist yet: the editor holds the edit, asks what it would cost, and only then offers to
     * commit it.
     */
    public function impact(Request $request, Game $game, GameVersion $version, SystemImpact $impact): JsonResponse
    {
        /** @var array<string, mixed> $document */
        $document = $request->input('document', []);

        return response()->json(['data' => $impact->of($version, $document)]);
    }

    /** Replace a draft's system document. Published versions are frozen. */
    public function updateVersion(
        Request $request,
        Game $game,
        GameVersion $version,
        SchemaValidator $validator,
        GameCompiler $compiler,
    ): JsonResponse {
        if (! $version->isEditable()) {
            return response()->json([
                'error' => [
                    'code' => 'version_published',
                    'message' => 'this version is published and cannot be edited; branch a new draft from it',
                ],
            ], 409);
        }

        /** @var array<string, mixed> $document */
        $document = $request->input('document', []);

        $violations = $validator->violations($document, 'game-system');
        if ($violations !== []) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_document',
                    'message' => 'the game system document is not valid',
                    'details' => ['violations' => $violations],
                ],
            ], 422);
        }

        // A game's id is its identity: every card, deck and replay in the database names it,
        // and a rename here would orphan all of them silently.
        if (($document['id'] ?? $game->slug) !== $game->slug) {
            return $this->refuse(
                'id_is_immutable',
                "this game's id is \"{$game->slug}\"; every card and replay names it, so it cannot be renamed here",
                409,
            );
        }

        $semver = (string) ($document['version'] ?? $version->semver);
        $taken = $game->versions()
            ->where('semver', $semver)
            ->whereKeyNot($version->id)
            ->exists();
        if ($taken) {
            return $this->refuse('semver_taken', "this game already has a version {$semver}", 409);
        }

        $version->document = $document;
        // The columns follow the document rather than the other way round (ADR-0001) — so a
        // designer who bumps the version in the editor gets a version that is actually
        // called that, and a renamed game is renamed everywhere.
        $version->semver = $semver;
        $version->save();

        $game->update([
            'name' => (string) ($document['name'] ?? $game->name),
            'summary' => $document['summary'] ?? $game->summary,
        ]);

        $report = $compiler->refresh($version);

        return response()->json(['data' => [
            'id' => $version->id,
            'semver' => $version->semver,
            'lint' => $report['lint'],
        ]]);
    }

    private function refuse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @return array<string, mixed> */
    private function summarise(Game $game): array
    {
        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'summary' => $game->summary,
            'cardCount' => $game->cards()->count(),
            'version' => $game->currentVersion === null ? null : [
                'id' => $game->currentVersion->id,
                'semver' => $game->currentVersion->semver,
                'status' => $game->currentVersion->status,
                'lintErrors' => $game->currentVersion->lint['errors'] ?? 0,
            ],
        ];
    }
}
