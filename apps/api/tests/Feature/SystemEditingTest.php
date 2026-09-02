<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing the system document through the API, and asking what an edit would cost first.
 *
 * Driven against Emberfall because impact is only meaningful with something to impact: 18
 * cards, 2 decks and a golden replay are what make "removing this would break 16 cards" a
 * fact rather than a shape.
 */
final class SystemEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('games:import', ['path' => 'emberfall'])->assertSuccessful();
    }

    public function test_it_saves_a_system_edit_and_recompiles_it(): void
    {
        $document = $this->document();
        $document['keywords'][] = [
            'id' => 'kindle',
            'name' => 'Kindle',
            'reminder' => 'A new keyword, added through the editor.',
        ];

        $response = $this->putJson($this->url(), ['document' => $document])->assertOk();

        $this->assertSame(0, $response->json('data.lint.errors'));

        // The compiled bundle is what the card editor renders from, so a keyword added here
        // has to be offerable on a card without a restart.
        $compiled = $this->getJson($this->url() . '/compiled')->assertOk();
        $this->assertArrayHasKey('kindle', $compiled->json('data.keywords'));
    }

    public function test_a_system_that_will_not_compile_is_still_saved(): void
    {
        // A designer mid-edit is usually in a broken state; refusing the write is how an
        // editor becomes unusable. The failure is reported, not enforced.
        //
        // A step with neither an automatic script nor a window is the shape of that: the
        // schema allows it — a step being written is a step with only a name — and the
        // compiler refuses it, because nothing would ever happen in it.
        $document = $this->document();
        $document['round']['phases'][0]['steps'][0] = ['id' => 'ready', 'name' => 'Ready cards'];

        $this->putJson($this->url(), ['document' => $document])->assertOk();

        $lint = $this->getJson($this->url() . '/lint')->assertOk();
        $this->assertFalse($lint->json('data.compiled'));
        $this->assertNotEmpty($lint->json('data.findings'));
    }

    public function test_the_columns_follow_the_document(): void
    {
        $document = $this->document();
        $document['version'] = '0.5.0';
        $document['name'] = 'Emberfall: Second Edition';

        $this->putJson($this->url(), ['document' => $document])->assertOk();

        $game = Game::query()->where('slug', 'emberfall')->firstOrFail();
        $this->assertSame('0.5.0', $game->currentVersion->semver);
        $this->assertSame('Emberfall: Second Edition', $game->name);
    }

    public function test_it_refuses_to_rename_the_games_id(): void
    {
        $document = $this->document();
        $document['id'] = 'ashfall';

        $this->putJson($this->url(), ['document' => $document])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'id_is_immutable');
    }

    public function test_it_refuses_a_version_number_another_version_already_holds(): void
    {
        $game = Game::query()->where('slug', 'emberfall')->firstOrFail();
        GameVersion::create([
            'game_id' => $game->id,
            'semver' => '0.5.0',
            'status' => GameVersion::DRAFT,
            'document' => $game->currentVersion->document,
        ]);

        $document = $this->document();
        $document['version'] = '0.5.0';

        $this->putJson($this->url(), ['document' => $document])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'semver_taken');
    }

    public function test_impact_names_the_cards_a_removed_resource_would_strand(): void
    {
        $document = $this->document();
        $document['resources'] = [];

        $response = $this->postJson($this->url() . '/impact', ['document' => $document])->assertOk();

        $this->assertSame(['ember'], $response->json('data.changes.resources.removed'));

        $findings = collect($response->json('data.findings'));
        $reference = $findings->firstWhere('rule', 'removed-reference');
        $this->assertNotNull($reference, 'nothing reported the cards that pay Ember');
        $this->assertSame('cards', $reference['subject']);
        $this->assertGreaterThan(0, $reference['count']);

        // And the system's own actions stop making sense: three of them pay a resource that
        // would no longer exist, which is the consequence a designer cannot see by reading
        // the resources tab they are standing in.
        $stranded = $findings->firstWhere('rule', 'removed-still-referenced');
        $this->assertNotNull($stranded, 'nothing reported the actions that pay Ember');
        $this->assertContains('actions.play_character', $stranded['evidence']);
        $this->assertContains('round.refresh.income', $stranded['evidence']);

        // Removing something is what makes a change breaking, and the version it suggests
        // says so before the designer has to think about it.
        $this->assertSame('major', $response->json('data.version.classification'));
        $this->assertSame('0.5.0', $response->json('data.version.suggested'));
    }

    public function test_impact_names_the_cards_a_narrowed_attribute_would_invalidate(): void
    {
        $document = $this->document();
        foreach ($document['cardTypes'] as $index => $type) {
            if ($type['id'] === 'character') {
                foreach ($type['attributes'] as $position => $attribute) {
                    if ($attribute['id'] === 'cost') {
                        $document['cardTypes'][$index]['attributes'][$position]['max'] = 2;
                    }
                }
            }
        }

        $response = $this->postJson($this->url() . '/impact', ['document' => $document])->assertOk();

        $invalidated = collect($response->json('data.findings'))->firstWhere('rule', 'cards-invalidated');
        $this->assertNotNull($invalidated, 'capping cost at 2 broke nothing, which cannot be true');
        $this->assertSame('error', $invalidated['severity']);
        // Named, not counted: the panel exists so a designer can go and look at them.
        $this->assertNotEmpty($invalidated['evidence']);
        $this->assertStringStartsWith('core-', $invalidated['evidence'][0]);
    }

    public function test_impact_names_the_decks_a_deckbuilding_change_would_make_illegal(): void
    {
        $document = $this->document();
        $document['deckbuilding']['deckSize'] = ['min' => 40, 'max' => 60];

        $response = $this->postJson($this->url() . '/impact', ['document' => $document])->assertOk();

        $decks = collect($response->json('data.findings'))->firstWhere('rule', 'decks-invalidated');
        $this->assertNotNull($decks, 'both example decks are 24 cards; a 40-card minimum has to break them');
        $this->assertSame(2, $decks['count']);
    }

    public function test_impact_of_an_addition_is_not_a_breaking_change(): void
    {
        $document = $this->document();
        $document['counters'][] = ['id' => 'ward', 'name' => 'Ward', 'visual' => 'pip-blue'];

        $response = $this->postJson($this->url() . '/impact', ['document' => $document])->assertOk();

        $this->assertSame(['ward'], $response->json('data.changes.counters.added'));
        $this->assertSame([], $response->json('data.changes.counters.removed'));
        $this->assertSame('minor', $response->json('data.version.classification'));
        $this->assertSame('0.4.1', $response->json('data.version.suggested'));

        // A counter nothing uses is a warning, and a warning is not a reason to stop.
        $this->assertSame(
            [],
            collect($response->json('data.findings'))->where('severity', 'error')->values()->all(),
        );
    }

    public function test_impact_reports_a_proposal_that_does_not_compile_without_saving_it(): void
    {
        $document = $this->document();
        $document['round']['phases'][0]['steps'][0] = ['id' => 'ready', 'name' => 'Ready cards'];

        $response = $this->postJson($this->url() . '/impact', ['document' => $document])->assertOk();

        $this->assertFalse($response->json('data.compiles'));
        $this->assertNotNull($response->json('data.error'));

        // Nothing was written: the version on disk is the one that was there before.
        $game = Game::query()->where('slug', 'emberfall')->firstOrFail();
        $this->assertArrayHasKey(
            'auto',
            $game->currentVersion->document['round']['phases'][0]['steps'][0],
            'the proposal was written to the version it was only asked about',
        );
    }

    public function test_impact_does_not_blame_a_change_for_damage_that_already_exists(): void
    {
        // A card that is already invalid stays invalid; saying so under every later edit is
        // how a warning panel gets ignored.
        $card = \App\Models\Card::query()->where('code', 'core-010')->firstOrFail();
        $document = $card->document;
        $document['attributes']['cost'] = 99;
        $card->forceFill(['document' => $document])->saveQuietly();

        $proposed = $this->document();
        $proposed['counters'][] = ['id' => 'ward', 'name' => 'Ward'];

        $response = $this->postJson($this->url() . '/impact', ['document' => $proposed])->assertOk();

        $this->assertNull(collect($response->json('data.findings'))->firstWhere('rule', 'cards-invalidated'));
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return Game::query()->where('slug', 'emberfall')->firstOrFail()->currentVersion->document;
    }

    private function url(): string
    {
        $semver = Game::query()->where('slug', 'emberfall')->firstOrFail()->currentVersion->semver;

        return "/api/v1/games/emberfall/versions/{$semver}";
    }
}
