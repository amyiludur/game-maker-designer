<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotProfile;
use App\Models\DeckVersion;
use App\Models\GameMatch;
use App\Models\GameVersion;
use App\Models\MatchAction;
use App\Models\MatchPlayer;
use App\Models\MatchSnapshot;
use Gmd\Harness\Agent\RandomAgent;
use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\Contract\Agent;
use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\SeatSetup;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Contract\StepResult;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\State\Codec\StateCodec;
use Gmd\Kernel\State\Codec\StateHasher;
use Gmd\Kernel\State\GameState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Running a match.
 *
 * The durable record is the initial state plus the append-only action log; the cached state
 * is an optimisation and the snapshots are another. Losing both is a slower request, not a
 * lost game — which is what makes recovery transparent and undo exact.
 *
 * On undo: doc 08 describes it as truncating the log and replaying, and doc 03 forbids
 * deleting from that log. Both are right about what they are protecting, so an undo is
 * *recorded* rather than performed — an `undo` entry naming the sequence to rewind to, and
 * reconstruction folds it away. The log stays complete and append-only, replay stays exact,
 * and a playtest note can still see that an undo happened, which is often the interesting
 * part.
 *
 * Bot seats are driven here, not in the browser. Doc 08 puts it as "solo and simulation
 * differ only in whether a human sits in one of the seats" — so a bot's move is an ordinary
 * row in the action log, taken by the same kernel, and undo, replay and reconstruction need
 * to know nothing about it.
 */
final class MatchService
{
    /**
     * How many moves bots may make in one request.
     *
     * A cap, not a budget: a solo turn is a handful of moves, and reaching a hundred means
     * the game is not progressing. Failing loudly beats holding the connection open while a
     * loop that cannot end tries to end.
     */
    private const BOT_STEP_CAP = 100;

    public function __construct(private readonly GameCompiler $compiler) {}

    /**
     * @param  list<array{seat: int, deckVersionId?: string, botProfileId?: string, userId?: int, label?: string}>  $seats
     * @param  array<string, mixed>  $config
     */
    public function create(GameVersion $version, array $seats, ?int $seed = null, array $config = [], string $mode = 'solo'): GameMatch
    {
        $kernel = $this->kernel($version);

        $seatSetups = [];
        foreach ($seats as $seat) {
            $deck = isset($seat['deckVersionId'])
                ? DeckVersion::query()->findOrFail($seat['deckVersionId'])
                : null;

            $seatSetups[] = new SeatSetup(
                (int) $seat['seat'],
                $deck?->document ?? [],
                $seat['label'] ?? null,
            );
        }

        // A seed is always recorded, generated when none was given, because "reproduce that
        // match" has to be answerable afterwards and nobody thinks to ask for a seed first.
        $seed ??= random_int(1, PHP_INT_MAX);

        $initial = $kernel->begin(new MatchSetup($seatSetups, $seed, $config));

        return DB::transaction(function () use ($version, $seats, $seed, $config, $mode, $initial): GameMatch {
            $match = GameMatch::create([
                'game_id' => $version->game_id,
                'game_version_id' => $version->id,
                'mode' => $mode,
                'status' => GameMatch::ACTIVE,
                'seed' => $seed,
                'config' => $config,
                // Written rather than left to the column default, because Eloquent does not
                // read defaults back and the API would answer `actionCount: null` for a
                // match that has taken no actions.
                'action_count' => 0,
                'initial_state' => StateCodec::encode($initial),
            ]);

            foreach ($seats as $seat) {
                MatchPlayer::create([
                    'match_id' => $match->id,
                    'seat' => (int) $seat['seat'],
                    'user_id' => $seat['userId'] ?? null,
                    'bot_profile_id' => $seat['botProfileId'] ?? null,
                    'deck_version_id' => $seat['deckVersionId'] ?? null,
                    'label' => $seat['label'] ?? null,
                ]);
            }

            return $match;
        });
    }

    /** Settle the opening position, running the game's setup script. */
    public function start(GameMatch $match): GameState
    {
        $state = $this->kernel($match->gameVersion)->settle($this->initialState($match))->state;
        $this->cache($match, $state);

        // Emberfall's setup ends with `set_first_player {"rule": "random"}`, so about half
        // of all matches open with the bot to move. Without this the board would simply sit
        // there, which is exactly what it used to do.
        return $this->driveBots($match, new StepResult($state))->state;
    }

    /** The current position, from the cache, a snapshot, or the log. */
    public function state(GameMatch $match): GameState
    {
        $cached = Cache::get($this->cacheKey($match));
        if (is_array($cached)) {
            return $this->decode($match, $cached);
        }

        return $this->rebuild($match);
    }

    /**
     * @throws \RuntimeException on a stale expectedVersion
     */
    public function act(GameMatch $match, Action $action, ?int $expectedVersion = null): StepResult
    {
        return $this->driveBots($match, $this->advance(
            $match,
            $expectedVersion,
            fn (Kernel $kernel, GameState $state): StepResult => $kernel->apply($state, $action),
            $action,
        ));
    }

    public function answer(GameMatch $match, ChoiceResponse $response, ?int $expectedVersion = null): StepResult
    {
        return $this->driveBots($match, $this->advance(
            $match,
            $expectedVersion,
            fn (Kernel $kernel, GameState $state): StepResult => $kernel->answer($state, $response),
            null,
            ['op' => 'choice', 'choiceId' => $response->choiceId, 'selection' => $response->selection],
        ));
    }

    /**
     * Rewind to a sequence, by recording that it happened.
     *
     * @return GameState the position as it was
     */
    public function undo(GameMatch $match, int $toSequence): GameState
    {
        DB::transaction(function () use ($match, $toSequence): void {
            MatchAction::create([
                'match_id' => $match->id,
                'sequence' => $match->action_count + 1,
                'seat' => null,
                'action' => ['op' => 'undo', 'toSequence' => $toSequence],
            ]);
            $match->increment('action_count');
        });

        Cache::forget($this->cacheKey($match));

        $state = $this->rebuild($match->refresh());
        $this->cache($match, $state);

        return $state;
    }

    /** Rebuild the position from the durable record: initial state plus the log. */
    public function rebuild(GameMatch $match): GameState
    {
        $kernel = $this->kernel($match->gameVersion);
        $entries = $this->effectiveActions($match);
        $lastSequence = $entries === [] ? 0 : (int) array_key_last($entries);

        $snapshot = MatchSnapshot::query()
            ->where('match_id', $match->id)
            ->where('sequence', '<=', $lastSequence)
            ->orderByDesc('sequence')
            ->first();

        $state = $snapshot !== null
            ? $this->decode($match, $snapshot->state)
            : $kernel->settle($this->initialState($match))->state;

        $from = $snapshot?->sequence ?? 0;

        foreach ($entries as $sequence => $entry) {
            if ($sequence <= $from) {
                continue;
            }
            $state = $this->replayOne($kernel, $state, $entry);
        }

        $this->cache($match, $state);

        return $state;
    }

    /**
     * The log with undos folded away.
     *
     * @return array<int, array<string, mixed>> sequence => action
     */
    public function effectiveActions(GameMatch $match): array
    {
        $entries = [];
        foreach ($match->actions()->get() as $row) {
            $action = $row->action;
            if (($action['op'] ?? null) === 'undo') {
                $to = (int) ($action['toSequence'] ?? 0);
                $entries = array_filter($entries, static fn (int $s): bool => $s <= $to, ARRAY_FILTER_USE_KEY);

                continue;
            }
            $entries[(int) $row->sequence] = $action;
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private function replayOne(Kernel $kernel, GameState $state, array $entry): GameState
    {
        if (($entry['op'] ?? null) === 'choice') {
            $choice = $state->pendingChoice();
            if ($choice === null) {
                return $state;
            }

            return $kernel->settle($kernel->answer(
                $state,
                new ChoiceResponse($choice->id, (array) ($entry['selection'] ?? [])),
            )->state)->state;
        }

        return $kernel->settle($kernel->apply($state, new Action(
            (string) $entry['actionId'],
            (string) $entry['side'],
            $entry['params'] ?? [],
        ))->state)->state;
    }

    /**
     * @param  callable(Kernel, GameState): StepResult  $step
     * @param  array<string, mixed>|null  $record
     */
    private function advance(
        GameMatch $match,
        ?int $expectedVersion,
        callable $step,
        ?Action $action = null,
        ?array $record = null,
    ): StepResult {
        $kernel = $this->kernel($match->gameVersion);
        $state = $this->state($match);

        if ($expectedVersion !== null && $expectedVersion !== $state->version) {
            // Optimistic locking, as doc 10 specifies: the caller is told what the position
            // actually is rather than having their action silently applied to a board that
            // moved underneath them.
            throw new StaleMatchVersion($state->version);
        }

        $applied = $step($kernel, $state);
        $settled = $kernel->settle($applied->state);
        $next = $settled->state;

        $entry = $record ?? [
            'actionId' => $action?->actionId,
            'side' => $action?->side,
            'params' => $action?->params ?? [],
        ];

        DB::transaction(function () use ($match, $entry, $action, $next): void {
            $sequence = $match->action_count + 1;

            MatchAction::create([
                'match_id' => $match->id,
                'sequence' => $sequence,
                'seat' => $action === null ? null : $action->seat(),
                'action' => $entry,
            ]);

            $match->action_count = $sequence;
            if ($next->isOver()) {
                $match->status = GameMatch::COMPLETE;
                $match->result = [
                    'winners' => $next->result?->winners,
                    'losers' => $next->result?->losers,
                    'reason' => $next->result?->reason,
                    'rounds' => $next->result?->rounds,
                    'draw' => $next->result?->draw,
                ];
            }
            $match->save();

            $every = max(1, (int) config('gmd.snapshot_every'));
            if ($sequence % $every === 0 || $next->isOver()) {
                MatchSnapshot::create([
                    'match_id' => $match->id,
                    'sequence' => $sequence,
                    'state' => StateCodec::encode($next),
                    'state_hash' => StateHasher::hash($next),
                ]);
            }
        });

        $this->cache($match, $next);

        return new StepResult($next, [...$applied->events, ...$settled->events]);
    }

    /**
     * Play out every bot move that is now due, and return everything that happened.
     *
     * The loop is the harness's `MatchRunner` loop — legal actions, choose, apply, settle —
     * because a bot at the live table and a bot in a 10,000-match fuzz run have to take the
     * same path through the same engine, or the thing that was fuzzed is not the thing being
     * played.
     *
     * Each decision goes through `advance()`, so it is written to the append-only log with
     * its seat, snapshots keep their cadence, and `undo()` and `rebuild()` treat it as the
     * ordinary action it is. The events are appended to the caller's, which is what lets the
     * browser animate the opponent's turn from the same stream it already drains.
     */
    private function driveBots(GameMatch $match, StepResult $result): StepResult
    {
        $bots = $this->botSeats($match);
        if ($bots === []) {
            return $result;
        }

        $kernel = $this->kernel($match->gameVersion);
        $state = $result->state;
        $events = $result->events;
        $steps = 0;

        while (! $state->isOver()) {
            if ($steps++ >= self::BOT_STEP_CAP) {
                throw new \RuntimeException(
                    'the bots made ' . self::BOT_STEP_CAP . ' moves without the match ending or handing back',
                );
            }

            $choice = $state->pendingChoice();

            if ($choice !== null) {
                if (! isset($bots[$choice->seat()])) {
                    break;
                }

                $response = $this->agentFor($match, $bots[$choice->seat()], $choice->seat())
                    ->resolveChoice($kernel->view($state, $choice->side), $choice);

                $step = $this->advance(
                    $match,
                    null,
                    fn (Kernel $k, GameState $s): StepResult => $k->answer($s, $response),
                    null,
                    ['op' => 'choice', 'choiceId' => $response->choiceId, 'selection' => $response->selection],
                );

                $state = $step->state;
                $events = [...$events, ...$step->events];

                continue;
            }

            $seat = $state->priority();
            if ($seat === null || ! isset($bots[$seat])) {
                break;
            }

            $side = Side::player($seat);
            $legal = $kernel->legalActions($state, $side);
            if ($legal->isEmpty()) {
                // Nothing for this seat to do and no choice open: the position is waiting on
                // something other than a decision, so hand it back rather than spin.
                break;
            }

            $action = $this->agentFor($match, $bots[$seat], $seat)
                ->chooseAction($kernel->view($state, $side), $legal);

            $step = $this->advance(
                $match,
                null,
                fn (Kernel $k, GameState $s): StepResult => $k->apply($s, $action),
                $action,
            );

            $state = $step->state;
            $events = [...$events, ...$step->events];
        }

        return new StepResult($state, $events);
    }

    /**
     * The seats a bot is sitting in, keyed by seat.
     *
     * @return array<int, MatchPlayer>
     */
    private function botSeats(GameMatch $match): array
    {
        $bots = [];
        foreach ($match->players as $player) {
            if ($player->isBot()) {
                $bots[(int) $player->seat] = $player;
            }
        }

        return $bots;
    }

    /**
     * The agent playing one seat.
     *
     * Its RNG is the match seed advanced by the number of actions taken so far, so a bot
     * decision is a pure function of (seed, seat, sequence): the same seed replays the same
     * solo match, and nothing has to be added to the hashed state to make that true. It is
     * deliberately a different stream from the game's own — a bot drawing from the game
     * generator would change the shuffle by thinking.
     */
    private function agentFor(GameMatch $match, MatchPlayer $player, int $seat): Agent
    {
        $profile = $player->bot_profile_id === null
            ? null
            : BotProfile::query()->find($player->bot_profile_id);

        $strategy = $profile?->strategy ?? 'random';

        return match ($strategy) {
            'random' => new RandomAgent(
                Pcg64Rng::at((int) $match->seed + $seat + 1, $match->action_count + 1),
            ),
            default => throw new \RuntimeException(
                "bot strategy \"{$strategy}\" is not implemented yet; only \"random\" is",
            ),
        };
    }

    public function kernel(GameVersion $version): Kernel
    {
        return new Kernel($this->compiler->compile($version));
    }

    private function initialState(GameMatch $match): GameState
    {
        return $this->decode($match, $match->initial_state);
    }

    /** @param array<string, mixed> $document */
    private function decode(GameMatch $match, array $document): GameState
    {
        $system = $this->compiler->compile($match->gameVersion);

        return StateCodec::decode($document, $system->id, $system->digest);
    }

    private function cache(GameMatch $match, GameState $state): void
    {
        Cache::put($this->cacheKey($match), StateCodec::encode($state), now()->addHours(2));
    }

    private function cacheKey(GameMatch $match): string
    {
        return "match:{$match->id}:state";
    }
}
