<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardSet;
use App\Models\Game;
use App\Services\SchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sets — the release a designer plans and budgets in.
 *
 * A set is a document like everything else here, so its planned counts live in the document
 * and the completeness view reads them; the columns beside it are there to sort and join on.
 */
final class SetController extends Controller
{
    public function index(Game $game): JsonResponse
    {
        $sets = $game->sets()->orderBy('release_order')->get();

        return response()->json([
            'data' => $sets->map(fn (CardSet $set): array => $this->summarise($set))->all(),
            'meta' => ['total' => $sets->count()],
        ]);
    }

    public function store(Request $request, Game $game, SchemaValidator $validator): JsonResponse
    {
        $code = strtolower(trim((string) $request->input('code', '')));
        $name = trim((string) $request->input('name', ''));

        if (! preg_match('/^[a-z0-9]+$/', $code)) {
            return $this->refuse(
                'invalid_code',
                'a set code is lowercase letters and digits only — it becomes the prefix of every card code in it',
                422,
            );
        }
        if ($game->sets()->where('code', $code)->exists()) {
            return $this->refuse('code_taken', "this game already has a set called \"{$code}\"", 409);
        }

        $document = array_filter([
            'schemaVersion' => '1.0.0',
            'code' => $code,
            'gameId' => $game->slug,
            'name' => $name === '' ? strtoupper($code) : $name,
            'releaseOrder' => (int) $request->input('releaseOrder', $game->sets()->max('release_order') + 1),
            'status' => (string) $request->input('status', 'draft'),
            'summary' => $request->input('summary'),
            'design' => array_filter([
                'goals' => $request->input('goals'),
                'budget' => $request->input('budget'),
            ], static fn (mixed $v): bool => $v !== null && $v !== []),
        ], static fn (mixed $v): bool => $v !== null && $v !== []);

        $violations = $validator->violations($document, 'set');
        if ($violations !== []) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_document',
                    'message' => 'the set document is not valid',
                    'details' => ['violations' => $violations],
                ],
            ], 422);
        }

        $set = CardSet::create([
            'game_id' => $game->id,
            'code' => $code,
            'name' => (string) $document['name'],
            'release_order' => (int) $document['releaseOrder'],
            'status' => (string) $document['status'],
            'document' => $document,
        ]);

        return response()->json(['data' => $this->summarise($set)], 201);
    }

    /**
     * Rename a set, move it in the release order, or restate its budget.
     *
     * The budget is the whole reason a designer edits a set: it is what the completeness
     * view measures the authored cards against, and until now it could only be changed by
     * re-importing the game.
     */
    public function update(Request $request, CardSet $set, SchemaValidator $validator): JsonResponse
    {
        $document = $set->document ?? [];

        foreach (['name' => 'name', 'summary' => 'summary', 'status' => 'status'] as $input => $key) {
            if ($request->has($input)) {
                $document[$key] = $request->input($input);
            }
        }
        if ($request->has('releaseOrder')) {
            $document['releaseOrder'] = (int) $request->input('releaseOrder');
        }
        foreach (['goals', 'budget'] as $key) {
            if ($request->has($key)) {
                $design = $document['design'] ?? [];
                $design[$key] = $request->input($key);
                $document['design'] = array_filter($design, static fn (mixed $v): bool => $v !== null && $v !== []);
            }
        }

        $violations = $validator->violations($document, 'set');
        if ($violations !== []) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_document',
                    'message' => 'the set document is not valid',
                    'details' => ['violations' => $violations],
                ],
            ], 422);
        }

        $set->update([
            'name' => (string) ($document['name'] ?? $set->name),
            'release_order' => (int) ($document['releaseOrder'] ?? $set->release_order),
            'status' => (string) ($document['status'] ?? $set->status),
            'document' => $document,
        ]);

        return response()->json(['data' => $this->summarise($set->refresh())]);
    }

    private function refuse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @return array<string, mixed> */
    private function summarise(CardSet $set): array
    {
        return [
            'id' => $set->id,
            'code' => $set->code,
            'name' => $set->name,
            'releaseOrder' => $set->release_order,
            'status' => $set->status,
            'summary' => $set->document['summary'] ?? null,
            'cardCount' => $set->cards()->count(),
            'budget' => $set->budget(),
            'goals' => $set->document['design']['goals'] ?? [],
        ];
    }
}
