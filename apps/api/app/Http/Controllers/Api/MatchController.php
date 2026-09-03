<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotProfile;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameVersion;
use App\Models\Scenario;
use App\Services\MatchService;
use App\Services\StaleMatchVersion;
use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\KernelException;
use Gmd\Kernel\State\Codec\StateHasher;
use Gmd\Kernel\State\EventRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Playing a match over HTTP.
 *
 * Every response carries the redacted view for the asking side and the events that produced
 * it. The client renders both and decides nothing: the legal action list is computed here,
 * so a client that is wrong about the rules cannot make the game wrong (ADR-0002).
 */
final class MatchController extends Controller
{
    public function __construct(private readonly MatchService $matches) {}

    /**
     * The opponents available for this game.
     *
     * The game's own profiles plus the built-in game-agnostic ones, so a game nobody has
     * tuned a bot for still has something to play against.
     */
    public function botProfiles(Game $game): JsonResponse
    {
        $profiles = BotProfile::query()
            ->where('game_id', $game->id)
            ->orWhereNull('game_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $profiles->map(fn (BotProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'strategy' => $profile->strategy,
                // Only `random` is implemented in this pass; the rest are authored and
                // waiting for the bot they describe (doc 09), and the UI says so rather than
                // offering an opponent that would throw.
                'implemented' => $profile->strategy === 'random',
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $version = GameVersion::query()->findOrFail($request->input('gameVersionId'));

        $scenarioId = $request->input('scenarioId');
        $scenario = $scenarioId === null ? null : Scenario::query()->findOrFail($scenarioId);

        try {
            $match = $this->matches->create(
                $version,
                $request->input('seats', []),
                $request->input('seed') === null ? null : (int) $request->input('seed'),
                $request->input('config', []),
                (string) $request->input('mode', 'solo'),
                $scenario,
            );

            $this->matches->start($match);
        } catch (KernelException $e) {
            // A match that cannot be dealt is a bad request, not a server fault: too many
            // seats, a deck naming a card the game does not have, or — the co-op one — a
            // game played against an adversary with no scenario saying which.
            return response()->json([
                'error' => [
                    'code' => $e->diagnostic()->code->value,
                    'message' => $e->diagnostic()->message,
                    'details' => $e->diagnostic()->jsonSerialize(),
                ],
            ], 422);
        }

        return response()->json(['data' => $this->envelope($match, $this->side($request, $match))], 201);
    }

    public function show(Request $request, GameMatch $match): JsonResponse
    {
        return response()->json(['data' => $this->envelope($match, $this->side($request, $match))]);
    }

    /** Take an action. */
    public function act(Request $request, GameMatch $match): JsonResponse
    {
        $side = (string) $request->input('side', $this->side($request, $match));

        try {
            $result = $this->matches->act(
                $match,
                new Action((string) $request->input('actionId'), $side, $request->input('params', [])),
                $request->input('expectedVersion') === null ? null : (int) $request->input('expectedVersion'),
            );
        } catch (StaleMatchVersion $e) {
            // 409 with a fresh view, per doc 10 — a resync rather than a silent overwrite.
            return response()->json([
                'error' => ['code' => 'stale_version', 'message' => $e->getMessage()],
                'data' => $this->envelope($match, $side),
            ], 409);
        } catch (KernelException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->diagnostic()->code->value,
                    'message' => $e->diagnostic()->message,
                    'details' => $e->diagnostic()->jsonSerialize(),
                ],
            ], 422);
        }

        return response()->json(['data' => $this->envelope($match->refresh(), $side, $result->events)]);
    }

    /** Answer a pending choice. */
    public function choose(Request $request, GameMatch $match): JsonResponse
    {
        $side = (string) $request->input('side', $this->side($request, $match));

        try {
            $result = $this->matches->answer(
                $match,
                new ChoiceResponse(
                    (string) $request->input('choiceId'),
                    $request->input('selection', []),
                    $request->input('number') === null ? null : (int) $request->input('number'),
                    $request->input('yes'),
                ),
                $request->input('expectedVersion') === null ? null : (int) $request->input('expectedVersion'),
            );
        } catch (StaleMatchVersion $e) {
            return response()->json([
                'error' => ['code' => 'stale_version', 'message' => $e->getMessage()],
                'data' => $this->envelope($match, $side),
            ], 409);
        }

        return response()->json(['data' => $this->envelope($match->refresh(), $side, $result->events)]);
    }

    public function undo(Request $request, GameMatch $match): JsonResponse
    {
        $this->matches->undo($match, (int) $request->input('toSequence', max(0, $match->action_count - 1)));

        return response()->json(['data' => $this->envelope($match->refresh(), $this->side($request, $match))]);
    }

    public function log(GameMatch $match): JsonResponse
    {
        return response()->json([
            'data' => $match->actions()->get()->map(fn ($row): array => [
                'sequence' => $row->sequence,
                'seat' => $row->seat,
                'action' => $row->action,
            ])->all(),
        ]);
    }

    /** Export as a replay document, which is also the bug-report format. */
    public function replay(GameMatch $match): JsonResponse
    {
        $actions = [];
        foreach ($this->matches->effectiveActions($match) as $sequence => $entry) {
            if (($entry['op'] ?? null) === 'choice') {
                // A choice belongs to the action that raised it, so it is folded into that
                // entry rather than becoming an action of its own.
                $last = array_key_last($actions);
                if ($last !== null) {
                    $actions[$last]['choice'] = [(string) $entry['choiceId'] => $entry['selection']];
                }

                continue;
            }
            $actions[] = array_filter([
                'seq' => count($actions) + 1,
                'seat' => Side::seatOf((string) $entry['side']),
                'actionId' => $entry['actionId'],
                'params' => $entry['params'] ?: null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return response()->json(['data' => [
            'schemaVersion' => '1.0.0',
            'gameId' => $match->game->slug,
            'gameVersion' => $match->gameVersion->semver,
            'matchId' => $match->id,
            'mode' => $match->mode,
            'seed' => $match->seed,
            'config' => $match->config,
            'actions' => array_values($actions),
        ]]);
    }

    /**
     * @param  list<EventRecord>  $events
     * @return array<string, mixed>
     */
    private function envelope(GameMatch $match, string $side, array $events = []): array
    {
        $state = $this->matches->state($match);
        $kernel = $this->matches->kernel($match->gameVersion);

        return [
            'match' => [
                'id' => $match->id,
                'mode' => $match->mode,
                'status' => $match->status,
                'seed' => $match->seed,
                'actionCount' => $match->action_count,
                'result' => $match->result,
            ],
            // Whose turn it is has never been a secret, and a view cannot answer it: a
            // choice addressed to another seat is stripped out of it by the projector. A
            // hotseat table needs this to know which chair to move to.
            'waitingOn' => $state->pendingChoice()?->side ?? ($state->isOver() ? null : $state->activeSide()),
            'version' => $state->version,
            'stateHash' => StateHasher::hash($state),
            'view' => $kernel->view($state, $side)->toArray(),
            'legalActions' => array_map(
                static fn ($legal): array => [
                    'key' => $legal->key(),
                    'actionId' => $legal->actionId,
                    'params' => $legal->params,
                    'label' => $legal->label,
                ],
                $kernel->legalActions($state, $side)->actions,
            ),
            'events' => array_map(
                static fn (EventRecord $e): array => ['seq' => $e->seq, 'type' => $e->type, 'payload' => $e->payload],
                $events,
            ),
        ];
    }

    private function side(Request $request, GameMatch $match): string
    {
        $side = $request->query('side') ?? $request->input('side');
        if (is_string($side) && $side !== '') {
            return $side;
        }

        return Side::player((int) ($match->players()->first()?->seat ?? 0));
    }
}
