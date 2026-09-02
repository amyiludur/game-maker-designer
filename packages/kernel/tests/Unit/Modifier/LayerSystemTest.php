<?php

declare(strict_types=1);

use Gmd\Kernel\Tests\Support\Board;

/*
 | The layer system, tested against the real Emberfall cards.
 |
 | Two cards carry the whole design between them. Ember Brand attaches and gives its host
 | +2 attack; Warhorn Bearer gives every *other* friendly Soldier +1. One resolves a fixed
 | target when it is created, the other has to re-evaluate a query every time — and getting
 | that distinction wrong makes one of the two card texts a lie.
 */

it('reads a printed value when nothing modifies it', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-010');
    $context = $board->context();

    expect($context->runtime->modifiers->attribute($context, $board->id('core-010'), 'attack'))->toBe(2);
});

it('applies an attachment buff to its host', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-010');
    $scout = $board->id('core-010');
    $board->attach(0, 'core-016', $scout);
    $context = $board->context();

    // 2 base at layer 0, +2 at layer 6.
    expect($context->runtime->modifiers->attribute($context, $scout, 'attack'))->toBe(4);
});

it('applies an aura to cards that match it and not to those that do not', function (): void {
    $board = Board::emberfall()
        ->seat(0, 'core-001')
        ->inPlay(0, 'core-013')   // Warhorn Bearer, a Soldier: other friendly Soldiers get +1
        ->inPlay(0, 'core-021')   // Ashen Vanguard, a Soldier
        ->inPlay(0, 'core-010');  // Cinder Scout, a Scout
    $context = $board->context();
    $modifiers = $context->runtime->modifiers;

    expect($modifiers->attribute($context, $board->id('core-021'), 'attack'))->toBe(3);
    expect($modifiers->attribute($context, $board->id('core-010'), 'attack'))->toBe(2);
    // "Other" friendly Soldiers: the query excludes $self, so Warhorn does not buff itself.
    expect($modifiers->attribute($context, $board->id('core-013'), 'attack'))->toBe(2);
});

it('lets an aura pick up a card that arrives later', function (): void {
    // This is why an aura must be derived from the board rather than stored when it
    // resolved: a Soldier played three turns after the Warhorn still gets the +1.
    $before = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-013');
    $beforeContext = $before->context();
    expect($beforeContext->runtime->modifiers->board($beforeContext))->toHaveCount(2);

    $after = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-013')->inPlay(0, 'core-021');
    $afterContext = $after->context();
    expect($afterContext->runtime->modifiers->attribute($afterContext, $after->id('core-021'), 'attack'))->toBe(3);
});

it('drops an aura when its source leaves play', function (): void {
    $board = Board::emberfall()
        ->seat(0, 'core-001')
        ->inPlay(0, 'core-013')
        ->inPlay(0, 'core-021');
    $warhorn = $board->id('core-013');
    $vanguard = $board->id('core-021');

    $state = $board->build();
    $context = $board->context(state: $state);
    expect($context->runtime->modifiers->attribute($context, $vanguard, 'attack'))->toBe(3);

    // Move the Warhorn to the discard. Nothing has to remember to remove a modifier: the
    // aura simply stops being derived, because its activeWhile no longer holds.
    $discarded = $state->with([
        'instances' => [
            ...$state->instances,
            $warhorn => $state->instance($warhorn)->with(['zone' => 'p0.discard']),
        ],
        'zones' => ['p0.play' => [$vanguard], 'p0.discard' => [$warhorn]] + $state->zones,
    ]);

    $after = $board->context(state: $discarded);
    expect($after->runtime->modifiers->attribute($after, $vanguard, 'attack'))->toBe(2);
});

it('does not buff an enemy Soldier', function (): void {
    $board = Board::emberfall()
        ->seat(0, 'core-001')
        ->seat(1, 'core-002')
        ->inPlay(0, 'core-013')
        ->inPlay(1, 'core-021');
    $context = $board->context();

    expect($context->runtime->modifiers->attribute($context, $board->id('core-021'), 'attack'))->toBe(2);
});

it('stacks an aura and an attachment', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-013')->inPlay(0, 'core-021');
    $vanguard = $board->id('core-021');
    $board->attach(0, 'core-016', $vanguard);
    $context = $board->context();

    // 2 printed, +1 from the Warhorn, +2 from the Brand, both at layer 6.
    expect($context->runtime->modifiers->attribute($context, $vanguard, 'attack'))->toBe(5);
});

it('explains where a value came from', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-013')->inPlay(0, 'core-021');
    $vanguard = $board->id('core-021');
    $board->attach(0, 'core-016', $vanguard);
    $context = $board->context();

    $breakdown = $context->runtime->modifiers->breakdown($context, $vanguard, 'attack');

    expect($breakdown->printed)->toBe(2);
    expect($breakdown->current)->toBe(5);
    expect($breakdown->steps)->toHaveCount(2);
    expect($breakdown->describe())->toContain('Warhorn Bearer')->toContain('Ember Brand');
});

it('leaves the printed value readable underneath', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-010');
    $scout = $board->id('core-010');
    $board->attach(0, 'core-016', $scout);
    $context = $board->context();

    expect($context->runtime->modifiers->baseAttribute($context, $scout, 'attack'))->toBe(2);
    expect($context->runtime->modifiers->attribute($context, $scout, 'attack'))->toBe(4);
});

it('carries keyword permissions and restrictions onto the board', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-010')->inPlay(0, 'core-021');
    $context = $board->context();
    $modifiers = $context->runtime->modifiers;

    expect($modifiers->characteristics($context, $board->id('core-010'))->permits('attack_while_summoning_sick'))->toBeTrue();
    expect($modifiers->characteristics($context, $board->id('core-021'))->permits('attack_while_summoning_sick'))->toBeFalse();
    expect($modifiers->characteristics($context, $board->id('core-021'))->restricted('must_be_attacked_first'))->toBeTrue();
});

it('scales a per-player attribute with the number of seats', function (): void {
    // A villain printed at 15 health has 30 at two players (doc 16 §5). The card face stays
    // honest at 15; the scaling happens where the value is read.
    $solo = Board::of(Gmd\Kernel\Tests\Support\Fixtures::wardensHollow())->seat(0)->inPlay(0, 'wh-100');
    $soloContext = $solo->context();
    $printed = $soloContext->runtime->modifiers->baseAttribute($soloContext, $solo->id('wh-100'), 'health');

    $duo = Board::of(Gmd\Kernel\Tests\Support\Fixtures::wardensHollow())->seat(0)->seat(1)->inPlay(0, 'wh-100');
    $duoContext = $duo->context();

    expect($soloContext->runtime->modifiers->attribute($soloContext, $solo->id('wh-100'), 'health'))->toBe($printed);
    expect($duoContext->runtime->modifiers->attribute($duoContext, $duo->id('wh-100'), 'health'))->toBe($printed * 2);
});
