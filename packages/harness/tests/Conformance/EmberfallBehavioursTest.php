<?php

declare(strict_types=1);

use Gmd\Harness\Tests\Support\Examples;
use Gmd\Harness\Tests\Support\Line;
use Gmd\Kernel\Expr\Bindings;
use Gmd\Kernel\Expr\EvalContext;
use Gmd\Kernel\Expr\Runtime;

/*
 | The behaviours the conformance replay exists to pin, each checked on its own.
 |
 | The replay is one line through a real match, which makes it an excellent regression
 | detector and a poor explanation: when it breaks it says "seq 9 diverged". These say what
 | is supposed to be true, so a future failure names the rule rather than the step — and they
 | are driven from the fixture itself, so they cannot drift away from the line they explain.
 */

it('gives each player min(round + 1, 6) resources at the start of a round', function (): void {
    $line = Line::emberfall(0);

    expect($line->state->round)->toBe(1);
    expect($line->state->player(0)->resource('ember'))->toBe(2);
    expect($line->state->player(1)->resource('ember'))->toBe(2);
});

it('lets Swift attack the round it entered play', function (): void {
    // Cinder Scout is played at seq 1 and attacks at seq 6, in the same round. Nothing in
    // the engine knows what summoning sickness is: Swift grants a layer-9 permission, and
    // declare_attack's requirement asks for it by name.
    $scout = Line::emberfall(1)->cardAt(1);
    $line = Line::emberfall(5);

    expect($line->state->instance($scout)->enteredOnRound)->toBe(1);
    expect($line->state->qualifiedStep())->toBe('combat.declare_attackers');

    $attackers = array_map(
        fn ($a): ?string => $a->params['attacker'] ?? null,
        $line->kernel->legalActions($line->state, 'p0')->actions,
    );
    expect($attackers)->toContain($scout);
});

it('ends an alternating window after two consecutive passes', function (): void {
    // seq 4 and 5 are both passes; the action step must not survive them.
    expect(Line::emberfall(3)->state->qualifiedStep())->toBe('action.main');
    expect(Line::emberfall(4)->state->qualifiedStep())->toBe('action.main');
    expect(Line::emberfall(5)->state->qualifiedStep())->not->toBe('action.main');
});

it('alternates the first player into the next round', function (): void {
    $first = Line::emberfall(0)->state;
    $second = Line::emberfall(8)->state;

    expect($second->round)->toBe(2);
    expect($second->firstSeat)->not->toBe($first->firstSeat);
});

it('resolves an optional target with no candidates silently', function (): void {
    // Dust Weaver carries Bolster 1: "another friendly character gets +1 attack". At seq 2 it
    // is the only friendly character there is, so the ability has nothing to choose and must
    // resolve without asking. A prompt with no options is a dead end a player cannot leave.
    $line = Line::emberfall(2);
    $weaver = $line->cardAt(2);

    expect($line->state->pendingChoice())->toBeNull();
    expect($line->state->instance($weaver)->code)->toBe('core-023');
    expect($line->state->instance($weaver)->zone)->toBe('p1.play');
});

it('buffs an attached card at layer 6 over its printed value at layer 0', function (): void {
    $scout = Line::emberfall(1)->cardAt(1);

    expect(Line::emberfall(2)->attack($scout))->toBe(2);
    expect(Line::emberfall(3)->attack($scout))->toBe(4);
});

it('reads current attack when checking a target restriction', function (): void {
    // Smother destroys "a character with 2 or less attack". The branded Cinder Scout is on
    // the board at 4 attack and is not a legal target; the freshly played Wandering Emberkin
    // is. This is the interaction the fixture calls "the point of the fixture".
    $line = Line::emberfall(9);
    $scout = $line->cardAt(1);
    $emberkin = $line->cardAt(9);
    $system = Examples::emberfall()->system;
    $runtime = Runtime::make();

    expect($line->attack($scout))->toBe(4);

    $query = $system->cards->get('core-024')->face()->abilities[0]->targets[0]['query'];
    $targets = $runtime->queries->cards($query, new EvalContext(
        $line->state,
        $system,
        $runtime,
        new Bindings(['you' => 'p1']),
    ));

    expect($targets)->toContain($emberkin);
    expect($targets)->not->toContain($scout);
});

it('destroys the card Smother named', function (): void {
    $emberkin = Line::emberfall(9)->cardAt(9);
    $after = Line::emberfall(10);

    expect($after->state->instance($emberkin)->zone)->toBe('p0.discard');
});

it('strikes simultaneously in combat', function (): void {
    // A 4/1 Cinder Scout blocks a 1/2 Dust Weaver. Both deal their damage before either is
    // checked for lethal damage, so both die — reading the amounts one at a time would let
    // the first death spare the second card.
    $before = Line::emberfall(13);
    $scout = $before->cardAt(1);
    $weaver = $before->cardAt(2);

    expect($before->state->instance($scout)->zone)->toBe('p0.play');
    expect($before->state->instance($weaver)->zone)->toBe('p1.play');

    $after = Line::emberfall(14);

    expect($after->state->instance($weaver)->zone)->toBe('p1.discard');
    expect($after->state->instance($scout)->zone)->toBe('p0.discard');
});
