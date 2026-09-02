<?php

declare(strict_types=1);

namespace Tests\Feature;

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

    private function startMatch(int $seed = 48): array
    {
        $game = Game::query()->where('slug', 'emberfall')->firstOrFail();
        $decks = Deck::query()->where('game_id', $game->id)->orderBy('name')->get();

        $response = $this->postJson('/api/v1/matches', [
            'gameVersionId' => $game->current_version_id,
            'mode' => 'solo',
            'seed' => $seed,
            'seats' => [
                ['seat' => 0, 'deckVersionId' => $decks[0]->head_version_id],
                ['seat' => 1, 'deckVersionId' => $decks[1]->head_version_id],
            ],
        ])->assertCreated();

        return $response->json('data');
    }

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
}
