<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EncounterSet;
use App\Models\Game;
use App\Models\Scenario;
use Illuminate\Http\JsonResponse;

/**
 * What a cooperative table can play against.
 *
 * A competitive game has none of these and answers with an empty list, which is what lets
 * the client ask unconditionally and decide from the answer whether it is setting up a duel
 * or a scenario.
 */
final class ScenarioController extends Controller
{
    public function index(Game $game): JsonResponse
    {
        $scenarios = Scenario::query()
            ->where('game_id', $game->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $scenarios->map($this->summarise(...))->all(),
        ]);
    }

    public function show(Game $game, Scenario $scenario): JsonResponse
    {
        abort_unless($scenario->game_id === $game->id, 404);

        $sets = EncounterSet::query()
            ->where('game_id', $game->id)
            ->whereIn('code', $scenario->encounterSetCodes())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                ...$this->summarise($scenario),
                'document' => $scenario->document,
                'encounterSets' => $sets->map(fn (EncounterSet $set): array => [
                    'code' => $set->code,
                    'name' => $set->name,
                    'kind' => $set->kind,
                    // The size is what a designer actually reads off this screen: a ten-card
                    // deck at four players cycles every two and a half rounds.
                    'cardCount' => array_sum(array_map(
                        static fn (array $entry): int => (int) ($entry['count'] ?? 1),
                        $set->cards(),
                    )),
                ])->all(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function summarise(Scenario $scenario): array
    {
        return [
            'id' => $scenario->id,
            'code' => $scenario->code,
            'name' => $scenario->name,
            'adversary' => $scenario->adversary,
            'difficulty' => $scenario->difficulty,
            'players' => ['min' => $scenario->min_players, 'max' => $scenario->max_players],
            'anchors' => $scenario->anchors(),
            'encounterSets' => $scenario->encounterSetCodes(),
        ];
    }
}
