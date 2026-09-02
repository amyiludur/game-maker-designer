<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\Game;
use App\Services\DeckLegality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Decks and their legality. */
final class DeckController extends Controller
{
    public function index(Game $game): JsonResponse
    {
        $decks = $game->decks()->with('head')->orderBy('name')->get();

        return response()->json([
            'data' => $decks->map(fn (Deck $deck): array => [
                'id' => $deck->id,
                'name' => $deck->name,
                'archetype' => $deck->archetype,
                'cardCount' => array_sum(array_column($deck->head?->document['cards'] ?? [], 'count')),
                'valid' => $deck->head?->isLegal(),
            ])->all(),
        ]);
    }

    public function show(Deck $deck, DeckLegality $legality): JsonResponse
    {
        $head = $deck->head;
        $document = $head?->document ?? [];

        return response()->json(['data' => [
            'id' => $deck->id,
            'name' => $deck->name,
            'archetype' => $deck->archetype,
            'notes' => $deck->notes,
            'document' => $document,
            'legality' => $legality->check($deck->game, $document),
        ]]);
    }

    /** Check a deck without saving it — what the builder calls on every change. */
    public function validateDeck(Request $request, Game $game, DeckLegality $legality): JsonResponse
    {
        return response()->json(['data' => $legality->check($game, $request->input('document', []))]);
    }
}
