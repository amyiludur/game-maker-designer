<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardRevision;
use App\Models\Game;
use App\Services\SchemaValidator;
use App\Support\Projectors\CardProjector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cards.
 *
 * Filtering reads the index columns, which exist for exactly this and are never consulted
 * to make a game decision (ADR-0001). Writes go to the document, and the projector brings
 * the columns along inside the same transaction.
 */
final class CardController extends Controller
{
    public function index(Request $request, Game $game): JsonResponse
    {
        $query = Card::query()->where('game_id', $game->id);

        if ($type = $request->query('type')) {
            $query->whereIn('card_type', (array) $type);
        }
        if ($faction = $request->query('faction')) {
            $query->whereIn('faction', (array) $faction);
        }
        if ($status = $request->query('status')) {
            $query->whereIn('status', (array) $status);
        }
        if ($set = $request->query('set')) {
            $query->whereIn('set_id', (array) $set);
        }
        if (($min = $request->query('costMin')) !== null) {
            $query->where('cost', '>=', (int) $min);
        }
        if (($max = $request->query('costMax')) !== null) {
            $query->where('cost', '<=', (int) $max);
        }
        foreach ((array) $request->query('traits', []) as $trait) {
            $query->whereJsonContains('traits', $trait);
        }
        foreach ((array) $request->query('keywords', []) as $keyword) {
            $query->whereJsonContains('keywords', $keyword);
        }
        if ($search = $request->query('q')) {
            $query->whereRaw("to_tsvector('english', coalesce(search, '')) @@ plainto_tsquery('english', ?)", [$search]);
        }

        $perPage = min(200, max(1, (int) $request->query('perPage', 60)));
        $cards = $query->orderBy('code')->paginate($perPage);

        return response()->json([
            'data' => collect($cards->items())->map($this->summarise(...))->all(),
            'meta' => [
                'page' => $cards->currentPage(),
                'perPage' => $cards->perPage(),
                'total' => $cards->total(),
            ],
        ]);
    }

    public function show(Card $card): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->summarise($card),
            'document' => $card->document,
            'revisions' => $card->revisions()->get()
                ->map(fn (CardRevision $r): array => [
                    'revision' => $r->revision,
                    'message' => $r->message,
                    'createdAt' => $r->created_at,
                ])->all(),
        ]]);
    }

    /** Save a card. Every save is a revision; nothing is overwritten in place. */
    public function update(
        Request $request,
        Card $card,
        SchemaValidator $validator,
        CardProjector $projector,
    ): JsonResponse {
        /** @var array<string, mixed> $document */
        $document = $request->input('document', []);

        $violations = $validator->violations($document, 'card');
        if ($violations !== []) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_document',
                    'message' => 'the card document is not valid',
                    'details' => ['violations' => $violations],
                ],
            ], 422);
        }

        DB::transaction(function () use ($card, $document, $projector, $request): void {
            $revision = CardRevision::create([
                'card_id' => $card->id,
                'revision' => (int) $card->revisions()->max('revision') + 1,
                'document' => $document,
                'message' => $request->input('message'),
            ]);

            $card->document = $document;
            $card->status = (string) ($document['design']['status'] ?? $card->status);
            $card->head_revision_id = $revision->id;
            $projector->apply($card);
            $card->save();
        });

        return response()->json(['data' => [...$this->summarise($card->refresh()), 'document' => $card->document]]);
    }

    /** Planned versus authored, per card type — the set completeness view. */
    public function completeness(Game $game, \App\Models\CardSet $set): JsonResponse
    {
        $authored = $set->cards()
            ->selectRaw('card_type, count(*) as total')
            ->groupBy('card_type')
            ->pluck('total', 'card_type');

        $budget = $set->budget();
        $rows = [];
        foreach ($budget as $type => $planned) {
            $rows[] = [
                'type' => $type,
                'planned' => (int) $planned,
                'authored' => (int) ($authored[$type] ?? 0),
            ];
        }
        foreach ($authored as $type => $total) {
            if (! array_key_exists($type, $budget)) {
                $rows[] = ['type' => $type, 'planned' => null, 'authored' => (int) $total];
            }
        }

        return response()->json(['data' => [
            'set' => ['id' => $set->id, 'code' => $set->code, 'name' => $set->name],
            'byType' => $rows,
            'byCost' => $set->cards()
                ->selectRaw('cost, count(*) as total')
                ->whereNotNull('cost')
                ->groupBy('cost')
                ->orderBy('cost')
                ->pluck('total', 'cost'),
            'goals' => $set->document['design']['goals'] ?? [],
        ]]);
    }

    /** @return array<string, mixed> */
    private function summarise(Card $card): array
    {
        return [
            'id' => $card->id,
            'code' => $card->code,
            'name' => $card->name,
            'type' => $card->card_type,
            'faction' => $card->faction,
            'cost' => $card->cost,
            'traits' => $card->traits,
            'keywords' => $card->keywords,
            'status' => $card->status,
            'setId' => $card->set_id,
            'abilityCount' => count($card->document['abilities'] ?? []),
        ];
    }
}
