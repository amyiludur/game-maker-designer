<?php

declare(strict_types=1);

use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Tests\Support\Examples;
use Gmd\Kernel\System\CompiledAbility;

/*
 * The compiler is exercised against the two worked example games rather than toy fixtures.
 *
 * That is deliberate: the examples are the conformance targets and cover the two shapes an
 * LCG takes, so anything the compiler cannot digest here is something a real designer would
 * hit on day one.
 */

it('compiles the competitive duel', function (): void {
    $system = Examples::emberfall()->system;

    expect($system->id)->toBe('emberfall');
    expect($system->mode())->toBe('competitive');
    expect($system->cards->count())->toBe(18);
    expect(array_keys($system->zones))->toBe(['deck', 'hand', 'play', 'discard', 'removed']);
    expect(array_keys($system->cardTypes))->toBe(['hero', 'character', 'event', 'attachment']);
});

it('compiles the cooperative scenario game', function (): void {
    $system = Examples::wardensHollow()->system;

    expect($system->isCooperative())->toBeTrue();
    expect($system->maxPlayers())->toBe(4);
    expect($system->adversary('warden'))->not->toBeNull();
    expect($system->adversary('warden')?->isEngineControlled())->toBeTrue();
});

it('resolves a keyword into a permission at compile time', function (): void {
    // Swift is not a flag the engine knows about; it is a layer-9 permission the game
    // declares, and the compiler flattens it onto every card that carries it.
    $scout = Examples::emberfall()->system->cards->get('core-010')->face();

    expect($scout->hasKeyword('swift'))->toBeTrue();
    expect($scout->permissions)->toBe(['attack_while_summoning_sick' => true]);
});

it('resolves a keyword into a restriction', function (): void {
    $sentinel = Examples::emberfall()->system->cards->get('core-022')->face();

    expect($sentinel->restrictions)->toBe(['must_be_attacked_first' => true]);
});

it('binds a parameterised keyword to the card that carries it', function (): void {
    // Dust Weaver has Bolster 1. By this point it has a whole triggered ability, with n
    // bound, and the interpreter never has to know what a keyword is.
    $weaver = Examples::emberfall()->system->cards->get('core-023')->face();
    $bolster = array_values(array_filter(
        $weaver->abilities,
        static fn (CompiledAbility $a): bool => $a->keywordId === 'bolster',
    ));

    expect($bolster)->toHaveCount(1);
    expect($bolster[0]->kind)->toBe(CompiledAbility::KIND_TRIGGERED);
    expect($bolster[0]->params)->toBe(['n' => 1]);
    expect($bolster[0]->triggerEvent())->toBe('card.entered_zone');
});

it('shares one compiled body between cards carrying the same keyword', function (): void {
    $system = Examples::emberfall()->system;
    $refs = [];
    foreach (['core-023', 'core-021'] as $code) {
        foreach ($system->cards->get($code)->face()->abilities as $ability) {
            if ($ability->keywordId === 'bolster') {
                $refs[] = (string) $ability->effectProgram();
            }
        }
    }

    expect($refs)->not->toBeEmpty();
    expect(array_unique($refs))->toHaveCount(1);
});

it('indexes abilities by the event that fires them', function (): void {
    $system = Examples::emberfall()->system;

    // Without this index, every emitted event would scan every ability on the board.
    expect($system->abilitiesTriggeredBy('card.entered_zone'))->not->toBeEmpty();
    expect($system->abilitiesTriggeredBy('nothing.happens'))->toBe([]);
});

it('registers a program for every effect script in the game', function (): void {
    $refs = Examples::emberfall()->system->programs->refs();

    expect($refs)->toContain('system:setup');
    expect($refs)->toContain('action:play_character.effect');
    expect($refs)->toContain('action:play_character.cost');
    expect($refs)->toContain('system:step.refresh.income.auto');
    expect($refs)->toContain('statecheck:lethal_damage.then');
});

it('navigates into a nested op list by path', function (): void {
    $programs = Examples::emberfall()->system->programs;

    // The income step is for_each_player { gain_resource }. A stack frame descending into
    // the loop body addresses it by path, which is what lets a suspended effect resume on
    // the right iteration.
    $root = $programs->opsAt('system:step.refresh.income.auto');
    expect($root[0]['op'])->toBe('for_each_player');

    $body = $programs->opsAt('system:step.refresh.income.auto', ['0', 'do']);
    expect($body[0]['op'])->toBe('gain_resource');
});

it('orders steps as the round runs them', function (): void {
    $system = Examples::emberfall()->system;
    $ids = array_map(
        static fn (Gmd\Kernel\System\StepDefinition $s): string => $s->qualifiedId(),
        $system->steps(),
    );

    expect($ids)->toBe([
        'refresh.ready', 'refresh.income', 'refresh.draw',
        'action.main',
        'combat.declare_attackers', 'combat.declare_blockers', 'combat.resolve',
        'end.cleanup',
    ]);
    expect($system->stepAfter('end.cleanup'))->toBeNull();
});

it('offers only the actions a window allows', function (): void {
    $system = Examples::emberfall()->system;

    $main = array_map(fn ($a): string => $a->id, $system->actionsForWindow('action.main'));
    expect($main)->toBe(['play_character', 'play_attachment', 'play_event']);

    $attackers = array_map(fn ($a): string => $a->id, $system->actionsForWindow('combat.declare_attackers'));
    expect($attackers)->toBe(['declare_attack']);
});

it('produces a digest that changes with the rules and not with editor noise', function (): void {
    $loader = new FixtureLoader;
    $system = $loader->readJson(FixtureLoader::examplePath('emberfall') . '/game-system.json');
    $sets = array_values($loader->readDirectory(FixtureLoader::examplePath('emberfall') . '/sets'));
    $compiler = new Gmd\Kernel\System\SystemCompiler;

    $baseline = $compiler->compile($system, $sets)->digest;

    $withSchemaKey = $system;
    $withSchemaKey['$schema'] = 'anything at all';
    expect($compiler->compile($withSchemaKey, $sets)->digest)->toBe($baseline);

    $changedRules = $system;
    $changedRules['deckbuilding']['maxCopies'] = 2;
    expect($compiler->compile($changedRules, $sets)->digest)->not->toBe($baseline);
});
