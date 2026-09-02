<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardRevision;
use App\Models\CardSet;
use App\Models\Game;
use App\Services\CardDraft;
use App\Services\CardValidator;
use App\Support\Projectors\CardProjector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        return response()->json(['data' => $this->detail($card)]);
    }

    /**
     * Author a new card.
     *
     * The client sends what it cannot derive — the type, the set, a name — and the server
     * builds the document, because what a blank card of a given type contains is a fact
     * about the game system, not about the editor.
     */
    public function store(
        Request $request,
        Game $game,
        CardDraft $drafts,
        CardValidator $validator,
        CardProjector $projector,
    ): JsonResponse {
        $type = (string) $request->input('type', '');
        $set = $this->resolveSet($game, $request->input('setId'));

        try {
            $document = $drafts->blank(
                $game,
                $type,
                $set,
                trim((string) $request->input('name', '')) ?: 'Untitled',
                $request->input('faction'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'unknown_card_type', 'message' => $e->getMessage()]], 422);
        }

        if (($code = trim((string) $request->input('code', ''))) !== '') {
            $document['code'] = $code;
        }

        return $this->create($game, $set, $document, $validator, $projector, 'created');
    }

    /** Copy a card. The fastest way to author the fourth of something is to copy the third. */
    public function duplicate(
        Request $request,
        Card $card,
        CardDraft $drafts,
        CardValidator $validator,
        CardProjector $projector,
    ): JsonResponse {
        $game = $card->game;
        $set = $request->has('setId') ? $this->resolveSet($game, $request->input('setId')) : $card->set;

        $document = $drafts->copyOf($card->document ?? [], $game, $set, $request->input('name'));

        return $this->create($game, $set, $document, $validator, $projector, "copied from {$card->code}");
    }

    /**
     * Write a new card, or say why not.
     *
     * @param  array<string, mixed>  $document
     */
    private function create(
        Game $game,
        ?CardSet $set,
        array $document,
        CardValidator $validator,
        CardProjector $projector,
        string $message,
    ): JsonResponse {
        $code = (string) $document['code'];

        if (Card::query()->where('game_id', $game->id)->where('code', $code)->exists()) {
            return response()->json([
                'error' => ['code' => 'code_taken', 'message' => "this game already has a card called \"{$code}\""],
            ], 409);
        }

        $violations = $validator->violations($game, $document);
        if ($violations !== []) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_document',
                    'message' => 'the card document is not valid',
                    'details' => ['violations' => $violations],
                ],
            ], 422);
        }

        $card = DB::transaction(function () use ($game, $set, $document, $projector, $message): Card {
            $card = new Card([
                'game_id' => $game->id,
                'set_id' => $set?->id,
                'code' => (string) $document['code'],
                'document' => $document,
                'status' => (string) ($document['design']['status'] ?? 'draft'),
            ]);
            $card->id = Str::uuid7()->toString();
            $projector->apply($card);
            $card->save();

            // Revision 1 is the card as created, so the history starts at "how it began"
            // rather than at the first edit — the same rule the importer follows.
            $revision = CardRevision::create([
                'card_id' => $card->id,
                'revision' => 1,
                'document' => $document,
                'message' => $message,
            ]);
            $card->forceFill(['head_revision_id' => $revision->id])->saveQuietly();

            return $card;
        });

        return response()->json(['data' => $this->detail($card->refresh())], 201);
    }

    /** The named set, or the game's first — a card authored into nothing is hard to find later. */
    private function resolveSet(Game $game, mixed $id): ?CardSet
    {
        $sets = CardSet::query()->where('game_id', $game->id);

        if (is_string($id) && $id !== '') {
            // Postgres rejects a non-UUID string against a uuid column outright rather than
            // simply not matching it, so the id comparison only happens for something that
            // could be one.
            return Str::isUuid($id)
                ? $sets->find($id)
                : $sets->where('code', $id)->first();
        }

        return $sets->orderBy('release_order')->first();
    }

    /**
     * One card in full.
     *
     * Saving returns this too, and not a shorter version of it: the editor renders whatever
     * comes back, so a response missing `revisions` is a response the editor cannot draw.
     *
     * @return array<string, mixed>
     */
    private function detail(Card $card): array
    {
        return [
            ...$this->summarise($card),
            'document' => $card->document,
            'revisions' => $card->revisions()->orderBy('revision')->get()
                ->map(fn (CardRevision $r): array => [
                    'revision' => $r->revision,
                    'message' => $r->message,
                    'createdAt' => $r->created_at,
                ])->all(),
        ];
    }

    /** Save a card. Every save is a revision; nothing is overwritten in place. */
    public function update(
        Request $request,
        Card $card,
        CardValidator $validator,
        CardProjector $projector,
    ): JsonResponse {
        /** @var array<string, mixed> $document */
        $document = $request->input('document', []);

        // Both schemas: the card schema, and the compiled schema for this card's type. The
        // second one is game data, which is what makes "cost is 0-10" Emberfall's rule
        // rather than this application's.
        $violations = $validator->violations($card->game, $document);
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

        return response()->json(['data' => $this->detail($card->refresh())]);
    }

    /** Planned versus authored, per card type — the set completeness view. */
    public function completeness(Game $game, CardSet $set): JsonResponse
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
