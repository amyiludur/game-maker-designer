<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Services\GameCompiler;
use Gmd\Kernel\System\Lint;
use Gmd\Kernel\System\LintFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating things: games, sets and cards.
 *
 * Until these existed the only way into the platform was `games:import`, which meant a
 * designer could edit a game somebody else had written in a file but could not start one.
 */
final class AuthoringCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_offers_the_starter_templates(): void
    {
        $response = $this->getJson('/api/v1/game-templates')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains('blank', $ids);
        $this->assertContains('duel', $ids);

        $duel = collect($response->json('data'))->firstWhere('id', 'duel');
        $this->assertSame(3, $duel['cardTypes']);
        $this->assertSame(4, $duel['phases']);
    }

    public function test_every_template_compiles_and_lints_clean(): void
    {
        // A starter template is the one document nobody can fix from inside the product: a
        // game created from a broken one is broken before its first edit.
        foreach (['blank', 'duel'] as $template) {
            $this->postJson('/api/v1/games', ['name' => "Test {$template}", 'slug' => "t-{$template}", 'template' => $template])
                ->assertCreated();

            $version = Game::query()->where('slug', "t-{$template}")->firstOrFail()->currentVersion;
            $system = app(GameCompiler::class)->compile($version);
            $findings = Lint::standard()->check($system);

            $errors = array_filter($findings, static fn (LintFinding $f): bool => $f->severity === LintFinding::ERROR);
            $this->assertSame([], array_values(array_map(
                static fn (LintFinding $f): string => $f->describe(),
                $errors,
            )), "the {$template} template does not lint clean");
        }
    }

    public function test_it_creates_a_game_from_a_template_with_a_set_to_author_into(): void
    {
        $response = $this->postJson('/api/v1/games', [
            'name' => 'Tidewrack',
            'template' => 'duel',
            'summary' => 'A duel on a sinking ship.',
        ])->assertCreated();

        $this->assertSame('tidewrack', $response->json('data.slug'));
        $this->assertSame('0.1.0', $response->json('data.version.semver'));
        $this->assertSame('draft', $response->json('data.version.status'));
        $this->assertSame(0, $response->json('data.version.lintErrors'));

        $game = Game::query()->where('slug', 'tidewrack')->firstOrFail();
        // The document is the game's, not the template's: its id and name are what the
        // designer typed, and every card authored against it will say so.
        $this->assertSame('tidewrack', $game->currentVersion->document['id']);
        $this->assertSame('Tidewrack', $game->currentVersion->document['name']);
        $this->assertSame('A duel on a sinking ship.', $game->currentVersion->document['summary']);
        $this->assertArrayNotHasKey('$schema', $game->currentVersion->document);

        // Compiled on the way in, so the editor's first request has forms to render.
        $this->assertNotNull($game->currentVersion->compiled);
        $this->assertSame(['core'], $game->sets()->pluck('code')->all());
    }

    public function test_it_refuses_a_second_game_with_the_same_slug(): void
    {
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack'])->assertCreated();

        $this->postJson('/api/v1/games', ['name' => 'Tidewrack'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'slug_taken');
    }

    public function test_it_refuses_a_game_with_no_name_and_an_unknown_template(): void
    {
        $this->postJson('/api/v1/games', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'missing_name');

        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'no-such-template'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_template');
    }

    public function test_a_new_card_is_born_valid(): void
    {
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'duel'])->assertCreated();

        $response = $this->postJson('/api/v1/games/tidewrack/cards', [
            'type' => 'unit',
            'name' => 'Bilge Rat',
        ])->assertCreated();

        // Every required attribute of the type is already filled, with the smallest value
        // that type allows — so the editor opens on a card that saves, not on a form full of
        // errors.
        $this->assertSame('core-001', $response->json('data.code'));
        $this->assertSame('unit', $response->json('data.type'));
        $this->assertSame(['cost' => 0, 'attack' => 0, 'health' => 1], $response->json('data.document.attributes'));
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame(1, $response->json('data.document.number'));

        // Revision 1 is how it began, exactly as an imported card's is.
        $this->assertSame([1], array_column($response->json('data.revisions'), 'revision'));
        $this->assertSame('created', $response->json('data.revisions.0.message'));

        // And it comes back from the browser, which means the projector ran.
        $listed = $this->getJson('/api/v1/games/tidewrack/cards')->assertOk();
        $this->assertSame(['core-001'], array_column($listed->json('data'), 'code'));
        $this->assertSame('Bilge Rat', $listed->json('data.0.name'));
    }

    public function test_card_codes_continue_from_the_set(): void
    {
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'duel'])->assertCreated();

        foreach (['One', 'Two', 'Three'] as $name) {
            $this->postJson('/api/v1/games/tidewrack/cards', ['type' => 'unit', 'name' => $name])->assertCreated();
        }

        $this->assertSame(
            ['core-001', 'core-002', 'core-003'],
            Card::query()->orderBy('code')->pluck('code')->all(),
        );
    }

    public function test_it_refuses_a_card_of_a_type_the_game_does_not_have(): void
    {
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'duel'])->assertCreated();

        $this->postJson('/api/v1/games/tidewrack/cards', ['type' => 'spaceship', 'name' => 'Nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_card_type');
    }

    public function test_it_refuses_a_card_code_that_is_already_taken(): void
    {
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'duel'])->assertCreated();
        $this->postJson('/api/v1/games/tidewrack/cards', ['type' => 'unit', 'name' => 'One'])->assertCreated();

        $this->postJson('/api/v1/games/tidewrack/cards', ['type' => 'unit', 'name' => 'Two', 'code' => 'core-001'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'code_taken');
    }

    public function test_duplicating_a_card_keeps_the_design_and_takes_a_new_code(): void
    {
        $this->artisan('games:import', ['path' => 'emberfall'])->assertSuccessful();

        $response = $this->postJson('/api/v1/cards/core-020/duplicate')->assertCreated();

        // Numbering continues past the highest code in the set rather than filling the gaps
        // the example leaves at core-003..009: a code that once meant a card should not come
        // back meaning a different one.
        $this->assertSame('core-032', $response->json('data.code'));
        $this->assertSame('Cinderpriest (copy)', $response->json('data.name'));
        $this->assertSame('draft', $response->json('data.status'));
        // The point of a duplicate is the part that is expensive to retype.
        $this->assertSame(
            Card::query()->where('code', 'core-020')->firstOrFail()->document['abilities'],
            $response->json('data.document.abilities'),
        );
    }

    public function test_it_creates_a_set_and_numbers_cards_inside_it(): void
    {
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'duel'])->assertCreated();

        $set = $this->postJson('/api/v1/games/tidewrack/sets', [
            'code' => 'tide',
            'name' => 'Tidewrack: Deep Water',
            'budget' => ['unit' => 12, 'event' => 6],
        ])->assertCreated();

        $this->assertSame(2, $set->json('data.releaseOrder'));

        $card = $this->postJson('/api/v1/games/tidewrack/cards', [
            'type' => 'event',
            'name' => 'Undertow',
            'setId' => 'tide',
        ])->assertCreated();

        $this->assertSame('tide-001', $card->json('data.code'));
        $this->assertSame('tide', $card->json('data.document.setId'));

        // The budget reaches the completeness view, which is the reason to record one.
        $completeness = $this->getJson('/api/v1/games/tidewrack/sets/tide/completeness')->assertOk();
        $byType = collect($completeness->json('data.byType'))->keyBy('type');
        $this->assertSame(12, $byType['unit']['planned']);
        $this->assertSame(0, $byType['unit']['authored']);
        $this->assertSame(1, $byType['event']['authored']);
    }

    public function test_a_set_code_is_only_unique_inside_its_game(): void
    {
        // Every game created here gets a set called `core`, so the second one exists the
        // moment a designer makes a game beside Emberfall. An unscoped lookup answers every
        // game's `core` with whichever row was written first.
        $this->artisan('games:import', ['path' => 'emberfall'])->assertSuccessful();
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'duel'])->assertCreated();

        $response = $this->getJson('/api/v1/games/tidewrack/sets/core/completeness')->assertOk();

        $set = CardSet::query()->where('id', $response->json('data.set.id'))->firstOrFail();
        $this->assertSame(
            'tidewrack',
            Game::query()->findOrFail($set->game_id)->slug,
            'the set was resolved from another game',
        );
    }

    public function test_editing_a_set_restates_its_budget(): void
    {
        $this->postJson('/api/v1/games', ['name' => 'Tidewrack', 'template' => 'duel'])->assertCreated();

        $this->patchJson('/api/v1/sets/core', [
            'name' => 'First Wave',
            'budget' => ['unit' => 20],
            'goals' => ['Prove the duel shape'],
        ])->assertOk();

        $set = CardSet::query()->where('code', 'core')->firstOrFail();
        $this->assertSame('First Wave', $set->name);
        $this->assertSame(['unit' => 20], $set->budget());
        $this->assertSame(['Prove the duel shape'], $set->document['design']['goals']);
    }
}
