<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Game;
use App\Support\Projectors\CardProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The authoring surface, driven against the real Emberfall example.
 *
 * Imported rather than hand-seeded: the examples are what the kernel is tested against and
 * what the UI is designed for, so testing the API against a bespoke fixture would leave the
 * only interesting question — does this work with real data — unasked.
 */
final class AuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('games:import', ['path' => 'emberfall'])->assertSuccessful();
    }

    public function test_it_lists_games_with_their_lint_state(): void
    {
        $response = $this->getJson('/api/v1/games')->assertOk();

        $game = $response->json('data.0');
        $this->assertSame('emberfall', $game['slug']);
        $this->assertSame(18, $game['cardCount']);
        $this->assertSame(0, $game['version']['lintErrors']);
    }

    public function test_it_filters_cards_on_the_index_columns(): void
    {
        $cheap = $this->getJson('/api/v1/games/emberfall/cards?costMax=2')->assertOk();
        $this->assertGreaterThan(0, $cheap->json('meta.total'));
        foreach ($cheap->json('data') as $card) {
            $this->assertLessThanOrEqual(2, $card['cost']);
        }

        $soldiers = $this->getJson('/api/v1/games/emberfall/cards?traits[]=Soldier')->assertOk();
        foreach ($soldiers->json('data') as $card) {
            $this->assertContains('Soldier', $card['traits']);
        }
    }

    public function test_it_searches_card_text(): void
    {
        $response = $this->getJson('/api/v1/games/emberfall/cards?q=Vanguard')->assertOk();

        $this->assertContains('core-021', array_column($response->json('data'), 'code'));
    }

    public function test_it_serves_a_compiled_bundle_the_editor_can_build_forms_from(): void
    {
        $game = Game::query()->where('slug', 'emberfall')->firstOrFail();
        $response = $this->getJson("/api/v1/games/emberfall/versions/{$game->currentVersion->semver}/compiled")->assertOk();

        $character = $response->json('data.cardTypes.character');
        $this->assertSame(['cost', 'attack', 'health', 'traits'], array_column($character['fields'], 'id'));
        $this->assertSame('int 0–10', $character['fields'][0]['constraint']);
        // The guardrail the modifier engine enforces is published too, so the ability
        // builder can grey out an attribute a continuous effect is not allowed to touch.
        $this->assertSame(['attack', 'health', 'cost'], $character['modifiableAttributes']);
    }

    public function test_it_refuses_an_invalid_card_and_says_where(): void
    {
        $card = Card::query()->where('code', 'core-010')->firstOrFail();
        $broken = $card->document;
        unset($broken['name']);

        $response = $this->putJson("/api/v1/cards/{$card->code}", ['document' => $broken])->assertStatus(422);

        $this->assertSame('invalid_document', $response->json('error.code'));
        $this->assertNotEmpty($response->json('error.details.violations'));
        // The pointer is what lets the editor put the message next to the field rather than
        // at the top of the form.
        $this->assertArrayHasKey('pointer', $response->json('error.details.violations.0'));
    }

    public function test_saving_a_card_writes_a_revision_and_reprojects_it(): void
    {
        $card = Card::query()->where('code', 'core-010')->firstOrFail();
        $document = $card->document;
        $document['attributes']['cost'] = 4;
        $document['name'] = 'Cinder Sprinter';

        $this->putJson("/api/v1/cards/{$card->code}", ['document' => $document, 'message' => 'test'])->assertOk();

        $card->refresh();
        $this->assertSame(4, $card->cost);
        $this->assertSame('Cinder Sprinter', $card->name);
        $this->assertSame(2, $card->revisions()->count());
    }

    public function test_reprojecting_rebuilds_every_index_column_and_is_idempotent(): void
    {
        // This is what makes "the document is the truth" a fact rather than an aspiration
        // (ADR-0001): if the index columns can be destroyed and rebuilt, nothing important
        // can be living in them.
        Card::query()->update(['name' => 'WRONG', 'cost' => 999, 'card_type' => 'nonsense']);

        $this->artisan('cards:reproject')->assertSuccessful();

        $scout = Card::query()->where('code', 'core-010')->firstOrFail();
        $this->assertSame('Cinder Scout', $scout->name);
        $this->assertSame(1, $scout->cost);
        $this->assertSame('character', $scout->card_type);

        $projector = app(CardProjector::class);
        foreach (Card::query()->get() as $card) {
            foreach ($projector->project($card) as $column => $value) {
                $stored = $card->getAttribute($column);
                $this->assertSame(
                    json_encode($stored),
                    json_encode($value),
                    "reprojecting changed {$card->code}.{$column}, so the command is not idempotent",
                );
            }
        }
    }

    public function test_it_reports_set_completeness_against_the_design_budget(): void
    {
        $response = $this->getJson('/api/v1/games/emberfall/sets/core/completeness')->assertOk();

        $byType = collect($response->json('data.byType'))->keyBy('type');
        // The example set really is nine characters against a budget of eleven; the
        // completeness view exists to show gaps like that, so the test asserts the gap.
        $this->assertSame(11, $byType['character']['planned']);
        $this->assertSame(9, $byType['character']['authored']);
    }

    public function test_it_checks_deck_legality_in_the_games_own_words(): void
    {
        $game = Game::query()->where('slug', 'emberfall')->firstOrFail();
        $deck = $game->decks()->firstOrFail();

        $legal = $this->getJson("/api/v1/decks/{$deck->id}")->assertOk();
        $this->assertTrue($legal->json('data.legality.valid'));
        $this->assertSame(24, $legal->json('data.legality.stats.total'));

        $illegal = $this->postJson('/api/v1/games/emberfall/decks/validate', [
            'document' => ['identity' => 'core-001', 'cards' => [['code' => 'core-020', 'count' => 3]]],
        ])->assertOk();

        $this->assertFalse($illegal->json('data.legality.valid') ?? $illegal->json('data.valid'));
        $messages = array_column($illegal->json('data.violations'), 'message');
        $this->assertContains("Every card must match your hero's faction or be neutral.", $messages);
    }
}
