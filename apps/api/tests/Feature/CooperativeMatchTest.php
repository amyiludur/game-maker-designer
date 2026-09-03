<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\Game;
use App\Models\Scenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The other shape a game in this format takes: one to four players against a script.
 *
 * Nothing in the API is cooperative-specific — a scenario is an input to match creation the
 * way a deck is — so these tests are mostly asking whether the same endpoints answer
 * correctly for a game whose opponent is data rather than a seat.
 */
final class CooperativeMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('games:import', ['path' => 'wardens-hollow'])->assertSuccessful();
    }

    private function game(): Game
    {
        return Game::query()->where('slug', 'wardens-hollow')->firstOrFail();
    }

    public function test_it_imports_the_scenario_and_its_encounter_sets(): void
    {
        $game = $this->game();

        $this->getJson("/api/v1/games/{$game->id}/scenarios")
            ->assertOk()
            ->assertJsonPath('data.0.code', 'the-warden')
            ->assertJsonPath('data.0.adversary', 'warden')
            ->assertJsonPath('data.0.anchors.boss', 'wh-100')
            ->assertJsonPath('data.0.encounterSets.0', 'wh-hollow');

        $scenario = Scenario::query()->where('game_id', $game->id)->firstOrFail();

        // The card count is what a designer reads off this screen: a ten-card encounter deck
        // at four players cycles about every two and a half rounds.
        $this->getJson("/api/v1/games/{$game->id}/scenarios/{$scenario->id}")
            ->assertOk()
            ->assertJsonPath('data.encounterSets.0.code', 'wh-hollow')
            ->assertJsonPath('data.encounterSets.0.cardCount', 10);
    }

    public function test_a_competitive_game_has_no_scenarios_rather_than_an_error(): void
    {
        // The client asks unconditionally and learns the shape from the answer, so a duel
        // has to answer this route with an empty list rather than a 404.
        $this->artisan('games:import', ['path' => 'emberfall'])->assertSuccessful();
        $emberfall = Game::query()->where('slug', 'emberfall')->firstOrFail();

        $this->getJson("/api/v1/games/{$emberfall->id}/scenarios")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_seats_the_adversary_and_scales_it_to_the_table(): void
    {
        $data = $this->startScenario(seats: 3);

        // The villain and the main scheme are on the table, found through the anchors a card
        // saying "damage the villain" would use.
        $anchors = $data['view']['adversaries']['warden']['anchors'];
        $this->assertArrayHasKey('boss', $anchors);
        $this->assertArrayHasKey('mainScheme', $anchors);

        $area = collect($data['view']['zones']['warden.warden_area']);
        $boss = $area->firstWhere('id', $anchors['boss']);

        // Health is printed 9 and per player, so three Watchers face 27 — the asymmetry the
        // scenario's design notes call the main difficulty dial.
        $this->assertSame(27, $boss['attributes']['health']);

        // The encounter deck was stacked from the scenario's sets and is hidden.
        $this->assertCount(10, $data['view']['zones']['warden.encounter_deck']);
    }

    public function test_it_refuses_to_start_a_scenario_game_without_one(): void
    {
        // Better here than as an unresolved `$adversary` selector at round nine.
        $game = $this->game();
        $deck = Deck::query()->where('game_id', $game->id)->firstOrFail();

        $this->postJson('/api/v1/matches', [
            'gameVersionId' => $game->current_version_id,
            'mode' => 'hotseat',
            'seed' => 7,
            'seats' => [['seat' => 0, 'deckVersionId' => $deck->head_version_id]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'bad_document')
            ->assertJsonFragment(['message' => "Warden's Hollow is played against warden, so a match needs a scenario to say which one"]);
    }

    public function test_it_names_the_side_the_game_is_waiting_on(): void
    {
        // A view cannot answer this: the projector strips a choice addressed to another seat
        // out of it, so a hotseat table would have no way to know which chair to move to.
        $data = $this->startScenario(seats: 3);

        $this->assertMatchesRegularExpression('/^p\d+$/', (string) $data['waitingOn']);
    }

    /** @return array<string, mixed> */
    private function startScenario(int $seats): array
    {
        $game = $this->game();
        $deck = Deck::query()->where('game_id', $game->id)->firstOrFail();
        $scenario = Scenario::query()->where('game_id', $game->id)->firstOrFail();

        return $this->postJson('/api/v1/matches', [
            'gameVersionId' => $game->current_version_id,
            'scenarioId' => $scenario->id,
            'mode' => 'hotseat',
            'seed' => 7,
            'seats' => array_map(
                static fn (int $seat): array => ['seat' => $seat, 'deckVersionId' => $deck->head_version_id],
                range(0, $seats - 1),
            ),
        ])->assertCreated()->json('data');
    }
}
