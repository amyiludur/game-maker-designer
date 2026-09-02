<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameVersion;
use App\Services\GameCompiler;
use App\Services\SchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $version->document = $document;
        $version->save();

        $report = $compiler->refresh($version);

        return response()->json(['data' => [
            'id' => $version->id,
            'semver' => $version->semver,
            'lint' => $report['lint'],
        ]]);
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
