<?php

declare(strict_types=1);

use Gmd\Kernel\Tests\Support\Board;

it('selects cards from a zone across every side', function (): void {
    $board = Board::emberfall()
        ->seat(0, 'core-001')->seat(1, 'core-002')
        ->inPlay(0, 'core-010')
        ->inPlay(1, 'core-021');
    $context = $board->context(['you' => 'p0']);

    $found = $context->runtime->queries->cards(['zone' => 'play', 'types' => ['character']], $context);

    // An unqualified zone means everyone's copy of it — which is what lets "destroy a
    // character" reach across the table.
    expect($found)->toBe([$board->id('core-010'), $board->id('core-021')]);
});

it('narrows to one side by controller', function (): void {
    $board = Board::emberfall()
        ->seat(0, 'core-001')->seat(1, 'core-002')
        ->inPlay(0, 'core-010')
        ->inPlay(1, 'core-021');
    $context = $board->context(['you' => 'p0']);

    expect($context->runtime->queries->cards(
        ['zone' => 'play', 'controller' => '$you', 'types' => ['character']],
        $context,
    ))->toBe([$board->id('core-010')]);

    expect($context->runtime->queries->cards(
        ['zone' => 'play', 'controller' => '$opponent', 'types' => ['character']],
        $context,
    ))->toBe([$board->id('core-021')]);
});

it('filters on traits', function (): void {
    $board = Board::emberfall()
        ->seat(0, 'core-001')
        ->inPlay(0, 'core-010')   // Scout
        ->inPlay(0, 'core-021');  // Soldier
    $context = $board->context(['you' => 'p0']);

    expect($context->runtime->queries->cards(
        ['zone' => 'play', 'traits' => ['any' => ['Soldier']]],
        $context,
    ))->toBe([$board->id('core-021')]);
});

it('excludes named cards', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-021')->inPlay(0, 'core-013');
    $vanguard = $board->id('core-021');
    $context = $board->context(['you' => 'p0', 'self' => $vanguard]);

    expect($context->runtime->queries->cards(
        ['zone' => 'play', 'traits' => ['any' => ['Soldier']], 'exclude' => ['$self']],
        $context,
    ))->toBe([$board->id('core-013')]);
});

it('reads current attack in a filter, not printed attack', function (): void {
    // Smother destroys "a character with 2 or less attack". A Cinder Scout is printed 2/1,
    // so it is a legal target — until someone attaches an Ember Brand, at which point its
    // attack is 4 and it is not. The replay fixture calls pinning this "the point of the
    // fixture", and it is the single clearest example of why the query engine reads through
    // the modifier layers rather than off the card.
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-010')->inPlay(0, 'core-023');
    $scout = $board->id('core-010');
    $weaver = $board->id('core-023');

    $smother = Gmd\Kernel\Tests\Support\Fixtures::emberfall()
        ->cards->get('core-024')->face()->abilities[0]->targets[0]['query'];

    $before = $board->context(['you' => 'p0']);
    expect($before->runtime->queries->cards($smother, $before))->toBe([$scout, $weaver]);

    $board->attach(0, 'core-016', $scout);
    $after = $board->context(['you' => 'p0']);

    expect($after->runtime->queries->cards($smother, $after))->toBe([$weaver]);
});

it('orders by an expression with a stable tiebreak', function (): void {
    $board = Board::emberfall()
        ->seat(0, 'core-001')
        ->inPlay(0, 'core-010')   // attack 2
        ->inPlay(0, 'core-013')   // attack 2, buffs others only
        ->inPlay(0, 'core-021');  // attack 2, +1 from the Warhorn = 3
    $context = $board->context(['you' => 'p0']);

    $found = $context->runtime->queries->cards([
        'zone' => 'play',
        'types' => ['character'],
        'order' => ['by' => ['op' => 'attr', 'of' => '$card', 'attr' => 'attack'], 'dir' => 'desc'],
    ], $context);

    expect($found[0])->toBe($board->id('core-021'));
    // The two remaining cards tie on attack, so the instance id decides — and decides the
    // same way on every machine, which is what the conformance suite depends on.
    expect(array_slice($found, 1))->toBe([$board->id('core-010'), $board->id('core-013')]);
});

it('limits the result', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->inPlay(0, 'core-010')->inPlay(0, 'core-021');
    $context = $board->context(['you' => 'p0']);

    expect($context->runtime->queries->cards(['zone' => 'play', 'types' => ['character'], 'limit' => 1], $context))
        ->toHaveCount(1);
});

it('selects players', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->seat(1, 'core-002');
    $context = $board->context(['you' => 'p0']);

    expect($context->runtime->queries->players(['players' => 'all'], $context))->toBe(['p0', 'p1']);
    expect($context->runtime->queries->players(['players' => 'opponents'], $context))->toBe(['p1']);
    expect($context->runtime->queries->players(['players' => 'you'], $context))->toBe(['p0']);
});
