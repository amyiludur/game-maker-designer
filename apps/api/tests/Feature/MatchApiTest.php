<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\Deck;
use App\Models\Game;
use App\Models\GameMatch;
use App\Services\MatchService;
use Gmd\Kernel\State\Codec\StateHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Playing a match over HTTP.
 *
 * The same kernel the CLI drives, reached through the same contract — which is the whole
 * point of ADR-0002: the engine used to playtest a card is byte-identically the engine used
 * to simulate it ten thousand times.
 */
final class MatchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('games:import', ['path' => 'emberfall'])->assertSuccessful();
    }

    private function startMatch(int $seed = 48, bool $botOpponent = false): array
    {
        $game = Game::query()->where('slug', 'emberfall')->firstOrFail();
        $decks = Deck::query()->where('game_id', $game->id)->orderBy('name')->get();

        $seat1 = ['seat' => 1, 'deckVersionId' => $decks[1]->head_version_id];
        if ($botOpponent) {
            $seat1['botProfileId'] = BotProfile::query()->whereNull('game_id')
                ->where('strategy', 'random')->firstOrFail()->id;
        }

        $response = $this->postJson('/api/v1/matches', [
            'gameVersionId' => $game->current_version_id,
            'mode' => 'solo',
            'seed' => $seed,
            'seats' => [
                ['seat' => 0, 'deckVersionId' => $decks[0]->head_version_id],
                $seat1,
            ],
        ])->assertCreated();

        return $response->json('data');
    }

    /** A seed whose setup gives the first turn to seat 1, which is the bot's. */
    private const BOT_FIRST_SEED = 4;

    public function test_it_deals_an_opening_position_and_offers_legal_actions(): void
    {
        $data = $this->startMatch();

        $this->assertSame(1, $data['view']['round']);
        $this->assertSame('action', $data['view']['phase']);
        $this->assertNotEmpty($data['legalActions']);
        $this->assertContains('pass', array_column($data['legalActions'], 'actionId'));
    }

    public function test_it_hides_what_a_player_may_not_see(): void
    {
        $data = $this->startMatch();

        // The hidden cards are not redacted on the way out — they were never put in. A
        // client cannot cheat by reading its own memory (ADR-0002).
        foreach ($data['view']['zones']['p1.hand'] as $card) {
            $this->assertTrue($card['hidden'] ?? false);
            $this->assertArrayNotHasKey('code', $card);
        }
        foreach ($data['view']['zones']['p0.hand'] as $card) {
            $this->assertArrayHasKey('code', $card);
        }
        // Deck size stays public even though its contents do not.
        $this->assertCount(18, $data['view']['zones']['p1.deck']);
    }

    public function test_it_applies_an_action_and_returns_the_events_that_produced_it(): void
    {
        $data = $this->startMatch();
        $match = $data['match']['id'];
        $play = collect($data['legalActions'])->firstWhere('actionId', 'play_character');
        $this->assertNotNull($play, 'seed 48 should offer a character to play');

        $response = $this->postJson("/api/v1/matches/{$match}/actions", [
            'side' => 'p0',
            'actionId' => $play['actionId'],
            'params' => $play['params'],
            'expectedVersion' => $data['version'],
        ])->assertOk();

        // The event list is the animation script: the client is told what happened, in
        // order, rather than diffing two states to guess.
        $types = array_column($response->json('data.events'), 'type');
        $this->assertContains('card.played', $types);
        $this->assertContains('card.entered_zone', $types);
        $this->assertGreaterThan($data['version'], $response->json('data.version'));
    }

    public function test_it_refuses_an_action_against_a_position_that_has_moved(): void
    {
        $data = $this->startMatch();
        $match = $data['match']['id'];

        $this->postJson("/api/v1/matches/{$match}/actions", ['side' => 'p0', 'actionId' => 'pass'])->assertOk();

        $response = $this->postJson("/api/v1/matches/{$match}/actions", [
            'side' => 'p1',
            'actionId' => 'pass',
            'expectedVersion' => $data['version'],
        ])->assertStatus(409);

        // A resync, not a silent overwrite: the caller gets the position as it actually is.
        $this->assertSame('stale_version', $response->json('error.code'));
        $this->assertNotNull($response->json('data.view'));
    }

    public function test_it_refuses_an_action_the_rules_do_not_allow(): void
    {
        $data = $this->startMatch();
        $match = $data['match']['id'];

        $response = $this->postJson("/api/v1/matches/{$match}/actions", [
            'side' => 'p0',
            'actionId' => 'declare_attack',
            'params' => ['attacker' => 'i-p0-1'],
        ])->assertStatus(422);

        $this->assertSame('illegal_action', $response->json('error.code'));
    }

    public function test_the_action_log_is_append_only(): void
    {
        $data = $this->startMatch();
        $match = GameMatch::query()->findOrFail($data['match']['id']);

        $this->postJson("/api/v1/matches/{$match->id}/actions", ['side' => 'p0', 'actionId' => 'pass'])->assertOk();

        // Enforced by the database, not by application discipline: a rule the app merely
        // intends to follow is one a migration script breaks at 2am.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $match->actions()->first()->forceFill(['seat' => 9])->save();
    }

    public function test_undo_rewinds_without_deleting_history(): void
    {
        $data = $this->startMatch();
        $match = $data['match']['id'];
        $opening = $data['stateHash'];

        $this->postJson("/api/v1/matches/{$match}/actions", ['side' => 'p0', 'actionId' => 'pass'])->assertOk();
        $this->postJson("/api/v1/matches/{$match}/actions", ['side' => 'p1', 'actionId' => 'pass'])->assertOk();

        $undone = $this->postJson("/api/v1/matches/{$match}/undo", ['toSequence' => 0])->assertOk();

        // Back to exactly the opening position — proved by the hash, not by inspection.
        $this->assertSame($opening, $undone->json('data.stateHash'));

        // And the log still records everything, including that an undo happened, which is
        // often the interesting part of a playtest note.
        $log = $this->getJson("/api/v1/matches/{$match}/log")->json('data');
        $this->assertCount(3, $log);
        $this->assertSame('undo', $log[2]['action']['op']);
    }

    public function test_it_rebuilds_the_position_from_the_log_alone(): void
    {
        $data = $this->startMatch();
        $match = GameMatch::query()->findOrFail($data['match']['id']);

        foreach (['p0', 'p1', 'p0'] as $side) {
            $this->postJson("/api/v1/matches/{$match->id}/actions", ['side' => $side, 'actionId' => 'pass']);
        }

        $service = app(MatchService::class);
        $live = $service->state($match->refresh());

        // Drop the cache and every snapshot: the initial state plus the log has to be enough,
        // or a Redis eviction would lose a game rather than slow a request down.
        \Illuminate\Support\Facades\Cache::flush();
        $match->snapshots()->delete();

        $this->assertSame(StateHasher::hash($live), StateHasher::hash($service->rebuild($match)));
    }

    public function test_it_exports_a_replay_of_what_happened(): void
    {
        $data = $this->startMatch();
        $match = $data['match']['id'];

        $this->postJson("/api/v1/matches/{$match}/actions", ['side' => 'p0', 'actionId' => 'pass'])->assertOk();

        $replay = $this->getJson("/api/v1/matches/{$match}/replay")->assertOk()->json('data');

        $this->assertSame('emberfall', $replay['gameId']);
        $this->assertSame(48, $replay['seed']);
        $this->assertSame([['seq' => 1, 'seat' => 0, 'actionId' => 'pass']], $replay['actions']);
    }

    public function test_a_bot_seat_moves_without_the_board_waiting_on_a_human(): void
    {
        // Emberfall's setup ends with `set_first_player {"rule": "random"}`, so on this seed
        // the bot has the first turn. Without a bot driving seat 1 the board would sit here
        // for ever with nothing for p0 to do, which is exactly what it used to do.
        $withoutBot = $this->startMatch(self::BOT_FIRST_SEED);
        $this->assertSame(0, $withoutBot['match']['actionCount']);
        $this->assertEmpty($withoutBot['legalActions']);

        $withBot = $this->startMatch(self::BOT_FIRST_SEED, botOpponent: true);
        $this->assertGreaterThan(0, $withBot['match']['actionCount']);
        $this->assertNotEmpty($withBot['legalActions']);
    }

    public function test_a_bot_move_is_an_ordinary_row_in_the_action_log(): void
    {
        $data = $this->startMatch(self::BOT_FIRST_SEED, botOpponent: true);

        $log = $this->getJson("/api/v1/matches/{$data['match']['id']}/log")->assertOk()->json('data');
        $this->assertNotEmpty($log);

        // Nothing marks it as a bot's: undo, replay and reconstruction have to treat it as
        // the action it is, and they do that by not knowing the difference.
        foreach ($log as $entry) {
            $this->assertSame(1, $entry['seat']);
            $this->assertNotNull($entry['action']['actionId'] ?? $entry['action']['op'] ?? null);
        }
    }

    public function test_the_same_seed_plays_the_same_bot(): void
    {
        // The bot's RNG is derived from the match seed and the action count, so "reproduce
        // that match" stays answerable for a solo game as well as a scripted one.
        $first = $this->startMatch(self::BOT_FIRST_SEED, botOpponent: true);
        $second = $this->startMatch(self::BOT_FIRST_SEED, botOpponent: true);

        $this->assertSame($first['stateHash'], $second['stateHash']);
        $this->assertSame($first['match']['actionCount'], $second['match']['actionCount']);
    }

    public function test_the_bot_answers_and_the_events_come_back_with_the_human_move(): void
    {
        $data = $this->startMatch(self::BOT_FIRST_SEED, botOpponent: true);
        $action = $data['legalActions'][0];

        $response = $this->postJson("/api/v1/matches/{$data['match']['id']}/actions", [
            'side' => 'p0',
            'actionId' => $action['actionId'],
            'params' => $action['params'],
            'expectedVersion' => $data['version'],
        ])->assertOk()->json('data');

        // More than one action was recorded by one request: the human's, then the bot's
        // reply. The events of both come back together, because they are one animation.
        $this->assertGreaterThan($data['match']['actionCount'] + 1, $response['match']['actionCount']);
        $this->assertNotEmpty($response['events']);
    }

    public function test_a_solo_match_can_be_played_to_a_result(): void
    {
        $data = $this->startMatch(self::BOT_FIRST_SEED, botOpponent: true);
        $id = $data['match']['id'];

        for ($move = 0; $move < 400 && ($data['view']['result'] ?? null) === null; $move++) {
            $choice = $data['view']['pendingChoice'] ?? null;

            if ($choice !== null) {
                $data = $this->postJson("/api/v1/matches/{$id}/choice", [
                    'side' => 'p0',
                    'choiceId' => $choice['id'],
                    'selection' => array_slice($choice['options']['cards'] ?? [], 0, 1),
                    'expectedVersion' => $data['version'],
                ])->assertOk()->json('data');

                continue;
            }

            $this->assertNotEmpty($data['legalActions'], 'the board stalled with nothing for p0 to do');
            $action = $data['legalActions'][0];

            $data = $this->postJson("/api/v1/matches/{$id}/actions", [
                'side' => 'p0',
                'actionId' => $action['actionId'],
                'params' => $action['params'],
                'expectedVersion' => $data['version'],
            ])->assertOk()->json('data');
        }

        $this->assertNotNull($data['view']['result'], 'the match never reached a result');
        $this->assertSame('complete', GameMatch::query()->findOrFail($id)->status);
    }

    public function test_undo_rewinds_past_the_bots_reply(): void
    {
        $data = $this->startMatch(self::BOT_FIRST_SEED, botOpponent: true);
        $id = $data['match']['id'];
        $opening = $data['stateHash'];
        $before = $data['match']['actionCount'];

        $action = $data['legalActions'][0];
        $this->postJson("/api/v1/matches/{$id}/actions", [
            'side' => 'p0',
            'actionId' => $action['actionId'],
            'params' => $action['params'],
            'expectedVersion' => $data['version'],
        ])->assertOk();

        // Back to before the human's move, which means undoing the bot's reply too — and it
        // needs no special handling, because a bot's move is just another entry.
        $rewound = $this->postJson("/api/v1/matches/{$id}/undo", ['toSequence' => $before])
            ->assertOk()->json('data');

        $this->assertSame($opening, $rewound['stateHash']);
    }
}
