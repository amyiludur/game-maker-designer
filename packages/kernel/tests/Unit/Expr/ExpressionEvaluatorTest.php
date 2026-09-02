<?php

declare(strict_types=1);

use Gmd\Kernel\Diagnostics\NonDeterministicExpression;
use Gmd\Kernel\Diagnostics\UnknownOp;
use Gmd\Kernel\Diagnostics\UnresolvedSelector;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\Tests\Support\Board;
use Gmd\Kernel\Tests\Support\Fixtures;

function evaluatorBoard(): Board
{
    return Board::emberfall()
        ->seat(0, 'core-001', ['ember' => 3])
        ->seat(1, 'core-002', ['ember' => 1])
        ->inPlay(0, 'core-010')
        ->inHand(0, 'core-012')
        ->inHand(0, 'core-014');
}

it('evaluates the income expression the refresh step actually uses', function (): void {
    // min(round + 1, 6) — the reason a player has 2 Ember in round 1 and caps at 6.
    $expression = [
        'op' => 'min',
        'of' => [['op' => 'add', 'left' => ['op' => 'round'], 'right' => 1], 6],
    ];

    foreach ([1 => 2, 3 => 4, 5 => 6, 9 => 6] as $round => $expected) {
        $context = evaluatorBoard()->round($round)->context();
        expect($context->evaluateInt($expression))->toBe($expected);
    }
});

it('divides towards zero and never produces a fraction', function (): void {
    // Rules maths is integer-only (ADR-0005). "Half, rounded up" has to be written out.
    $context = evaluatorBoard()->context();

    expect($context->evaluateInt(['op' => 'div', 'left' => 7, 'right' => 2]))->toBe(3);
    expect($context->evaluateInt(['op' => 'div', 'left' => 7, 'right' => 0]))->toBe(0);
    expect($context->evaluateInt(['op' => 'mod', 'left' => 7, 'right' => 2]))->toBe(1);
});

it('reads resources, zone sizes and counters', function (): void {
    $board = evaluatorBoard();
    $context = $board->context(['you' => 'p0', 'player' => 'p0']);

    expect($context->evaluateInt(['op' => 'resource', 'player' => '$you', 'resource' => 'ember']))->toBe(3);
    expect($context->evaluateInt(['op' => 'zone_size', 'zone' => 'hand', 'player' => '$you']))->toBe(2);
    expect($context->evaluateInt(['op' => 'player_count']))->toBe(2);
});

it('counts counters on a card', function (): void {
    $board = evaluatorBoard();
    $hero = $board->id('core-001');
    $board->counters($hero, ['damage' => 4]);
    $context = $board->context();

    expect($context->evaluateInt(['op' => 'counter', 'of' => $hero, 'counter' => 'damage']))->toBe(4);
    expect($context->evaluateInt(['op' => 'counter', 'of' => $hero, 'counter' => 'charge']))->toBe(0);
});

it('answers the card predicates', function (): void {
    $board = evaluatorBoard();
    $scout = $board->id('core-010');
    $context = $board->context(['card' => $scout, 'self' => $scout]);

    expect($context->evaluateBool(['op' => 'is_type', 'card' => '$card', 'type' => 'character']))->toBeTrue();
    expect($context->evaluateBool(['op' => 'has_trait', 'card' => '$card', 'trait' => 'Scout']))->toBeTrue();
    expect($context->evaluateBool(['op' => 'has_keyword', 'card' => '$card', 'keyword' => 'swift']))->toBeTrue();
    expect($context->evaluateBool(['op' => 'has_permission', 'card' => '$card', 'permission' => 'attack_while_summoning_sick']))->toBeTrue();
    expect($context->evaluateBool(['op' => 'is_exhausted', 'card' => '$card']))->toBeFalse();
    expect($context->evaluateBool(['op' => 'is_attached', 'card' => '$card']))->toBeFalse();
    expect($context->evaluateBool(['op' => 'in_zone', 'card' => '$card', 'zone' => 'play']))->toBeTrue();
    expect($context->evaluateBool(['op' => 'in_zone', 'card' => '$card', 'zone' => 'hand']))->toBeFalse();
});

it('knows whether a card arrived this round', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->round(3);
    $board->inPlay(0, 'core-010', enteredOnRound: 3)->inPlay(0, 'core-021', enteredOnRound: 1);
    $context = $board->context();

    expect($context->evaluateBool(['op' => 'entered_this_round', 'card' => $board->id('core-010')]))->toBeTrue();
    expect($context->evaluateBool(['op' => 'entered_this_round', 'card' => $board->id('core-021')]))->toBeFalse();
});

it('treats and, or and not consistently', function (): void {
    $context = evaluatorBoard()->context();

    expect($context->evaluateBool(['op' => 'and', 'of' => [true, true]]))->toBeTrue();
    expect($context->evaluateBool(['op' => 'and', 'of' => [true, false]]))->toBeFalse();
    expect($context->evaluateBool(['op' => 'or', 'of' => [false, true]]))->toBeTrue();
    // `not` takes a single node rather than a list, and the family accepts both.
    expect($context->evaluateBool(['op' => 'not', 'of' => ['op' => 'eq', 'left' => 1, 'right' => 2]]))->toBeTrue();
});

it('quantifies over a query', function (): void {
    $board = evaluatorBoard();
    $context = $board->context(['you' => 'p0']);
    $characters = ['zone' => 'play', 'types' => ['character']];

    expect($context->evaluateInt(['op' => 'count', 'query' => $characters]))->toBe(1);
    expect($context->evaluateBool(['op' => 'exists', 'query' => $characters]))->toBeTrue();
    expect($context->evaluateBool([
        'op' => 'all',
        'of' => $characters,
        'match' => ['op' => 'has_trait', 'card' => '$card', 'trait' => 'Scout'],
    ]))->toBeTrue();
    expect($context->evaluateBool([
        'op' => 'none',
        'of' => $characters,
        'match' => ['op' => 'has_trait', 'card' => '$card', 'trait' => 'Soldier'],
    ]))->toBeTrue();
});

it('compares 3 and "3" as the same number but keeps null apart from zero', function (): void {
    $context = evaluatorBoard()->context();

    expect($context->evaluateBool(['op' => 'eq', 'left' => 3, 'right' => '3']))->toBeTrue();
    expect($context->evaluateBool(['op' => 'eq', 'left' => null, 'right' => 0]))->toBeFalse();
    expect($context->evaluateBool(['op' => 'eq', 'left' => null, 'right' => null]))->toBeTrue();
});

it('refuses a random draw where the read must be pure', function (): void {
    // A random_int inside a query filter would make legalActions() mutate the position it
    // is inspecting, and stop it being safe to call twice.
    evaluatorBoard()->context()->evaluate(['op' => 'random_int', 'min' => 1, 'max' => 6]);
})->throws(NonDeterministicExpression::class);

it('draws randomly where an effect is allowed to', function (): void {
    $board = evaluatorBoard();
    $context = new Gmd\Kernel\Expr\EvalContext(
        $board->build(),
        $board->system(),
        Gmd\Kernel\Expr\Runtime::make(),
        new Gmd\Kernel\Expr\Bindings,
        Pcg64Rng::at(1),
    );

    expect($context->evaluateInt(['op' => 'random_int', 'min' => 1, 'max' => 6]))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
});

it('names the op it does not implement', function (): void {
    evaluatorBoard()->context()->evaluate(['op' => 'summon_the_ancients']);
})->throws(UnknownOp::class, 'summon_the_ancients');

it('names a selector it cannot resolve', function (): void {
    evaluatorBoard()->context()->evaluate('$target.nobody');
})->throws(UnresolvedSelector::class);

it('resolves a seat identity', function (): void {
    $board = evaluatorBoard();
    $context = $board->context(['you' => 'p0']);

    expect($context->evaluate('$you.identity'))->toBe($board->id('core-001'));
});

it('resolves an attachment host', function (): void {
    $board = evaluatorBoard();
    $scout = $board->id('core-010');
    $board->attach(0, 'core-016', $scout);
    $brand = $board->id('core-016');

    expect($board->context(['self' => $brand])->evaluate('$host'))->toBe($scout);
});

it('knows whether a card could arrive in play', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001')->seat(1, 'core-002');
    $board->inHand(0, 'core-010');
    $context = $board->context();

    // A character with room for it, using the zone its card type declares it plays to.
    expect($context->evaluateBool(['op' => 'can_enter_play', 'card' => $board->id('core-010')]))->toBeTrue();
});

it('refuses a second copy of a unique card', function (): void {
    // Emberfall's heroes are unique, and seat 0 already has one in play from setup.
    $board = Board::emberfall()->seat(0, 'core-001');
    $board->inHand(0, 'core-001');
    $context = $board->context();

    $inHand = $board->id('core-001', 1);
    expect($context->evaluateBool(['op' => 'can_enter_play', 'card' => $inHand]))->toBeFalse();

    // Uniqueness is per controller: the other seat has no hero of its own yet.
    $other = Board::emberfall()->seat(0, 'core-002')->seat(1);
    $other->inHand(1, 'core-001');
    expect(
        $other->context()->evaluateBool(['op' => 'can_enter_play', 'card' => $other->id('core-001')]),
    )->toBeTrue();
});

it('refuses a zone that is already full', function (): void {
    // No Emberfall zone declares a maxSize, so the cap is added to the system document here
    // rather than to the example game — the point under test is the kernel's, not Emberfall's.
    $capped = Board::of(Fixtures::cappedPlayZone(2))->seat(0, 'core-001');
    $capped->inPlay(0, 'core-010')->inHand(0, 'core-011');
    $context = $capped->context();

    // The hero already in play plus one character fills a two-card zone.
    expect($context->evaluateBool(['op' => 'can_enter_play', 'card' => $capped->id('core-011')]))->toBeFalse();
});

it('reads the zone it is asked about', function (): void {
    $board = Board::emberfall()->seat(0, 'core-001');
    $board->inHand(0, 'core-010');
    $context = $board->context();

    expect($context->evaluateBool([
        'op' => 'can_enter_play', 'card' => $board->id('core-010'), 'zone' => 'nonesuch',
    ]))->toBeFalse();
});
