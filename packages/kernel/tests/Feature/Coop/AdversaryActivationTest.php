<?php

declare(strict_types=1);

use Gmd\Kernel\Contract\ChoiceResponse;
use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\State\GameState;
use Gmd\Kernel\Tests\Support\Board;
use Gmd\Kernel\Tests\Support\Fixtures;

/**
 * The Warden's activation, driven against hand-built positions.
 *
 * Random play does reach these positions, but it reaches them rarely and reports them as a
 * state hash — no use at all when the question is "did the defence window open". Each test
 * here builds the one board that answers its question.
 */
function wardensTable(): Board
{
    return Board::wardensHollow()
        ->adversary('warden', [
            'boss' => ['wh-100', 'warden_area'],
            'mainScheme' => ['wh-110', 'warden_area'],
        ])
        ->at('warden', 'activate');
}

/** Settle from the Warden's step, which is where its activation script runs. */
function runActivation(GameState $state): array
{
    $kernel = new Kernel(Fixtures::wardensHollow());
    $result = $kernel->settle($state);

    return [$kernel, $result];
}

it('schemes against a citizen rather than attacking one', function (): void {
    // Aria Vance's front face is a citizen: the Warden puts threat on the scheme instead of
    // hitting her. Its thwart is 1 and the scheme's acceleration another 1.
    $board = wardensTable()->seat(0, 'wh-001');
    [, $result] = runActivation($board->build());

    $scheme = $result->state->instance($board->id('wh-110'));

    expect($scheme->counter('threat'))->toBe(2)
        ->and($result->state->instance($board->id('wh-001'))->counter('damage'))->toBe(0);
});

it('attacks a guardian for the boss\'s printed attack', function (): void {
    $board = wardensTable()->seat(0, 'wh-001');
    $board->face($board->id('wh-001'), 'back');

    [, $result] = runActivation($board->build());

    // The Warden's opening stage has attack 2, and nothing on the table can defend.
    expect($result->state->instance($board->id('wh-001'))->counter('damage'))->toBe(2)
        ->and($result->state->instance($board->id('wh-110'))->counter('threat'))->toBe(1);
});

it('offers a ready guard post the chance to defend', function (): void {
    $board = wardensTable()->seat(0, 'wh-001')->inPlay(0, 'wh-012');
    $board->face($board->id('wh-001'), 'back');

    [$kernel, $result] = runActivation($board->build());
    $choice = $result->state->pendingChoice();

    expect($choice)->not->toBeNull()
        ->and($choice->kind)->toBe(PendingChoice::CHOOSE_CARDS)
        ->and($choice->side)->toBe('p0')
        ->and($choice->optional)->toBeTrue()
        ->and($choice->options['cards'])->toBe([$board->id('wh-012')]);

    // Taking the defence moves the damage onto Old Hollis and exhausts him. The exhaust is
    // asserted on the event rather than the final state, because settling runs on past
    // cleanup, which readies the table again — correctly, and after the cost was paid.
    $taken = $kernel->answer($result->state, ChoiceResponse::cards($choice->id, $board->id('wh-012')));
    $settled = $kernel->settle($taken->state);

    $defended = array_values(array_filter(
        [...$taken->events, ...$settled->events],
        static fn ($event): bool => $event->type === 'damage.defended',
    ));

    expect($defended)->toHaveCount(1)
        ->and($defended[0]->payload['defender'])->toBe($board->id('wh-012'))
        ->and($defended[0]->payload['protecting'])->toBe($board->id('wh-001'))
        ->and($settled->state->instance($board->id('wh-012'))->counter('damage'))->toBe(2)
        ->and($settled->state->instance($board->id('wh-001'))->counter('damage'))->toBe(0);
});

it('lets the damage through when the defence is declined', function (): void {
    $board = wardensTable()->seat(0, 'wh-001')->inPlay(0, 'wh-012');
    $board->face($board->id('wh-001'), 'back');

    [$kernel, $result] = runActivation($board->build());
    $choice = $result->state->pendingChoice();

    $answered = $kernel->settle($kernel->answer(
        $result->state,
        ChoiceResponse::declined($choice->id),
    )->state)->state;

    expect($answered->instance($board->id('wh-001'))->counter('damage'))->toBe(2)
        ->and($answered->instance($board->id('wh-012'))->counter('damage'))->toBe(0)
        ->and($answered->instance($board->id('wh-012'))->exhausted)->toBeFalse();
});

it('does not offer a guard post that is already exhausted', function (): void {
    // The keyword's reminder says "while this ally is ready", and an exhausted defender is
    // the whole cost of having defended once already.
    $board = wardensTable()->seat(0, 'wh-001')->inPlay(0, 'wh-012', exhausted: true);
    $board->face($board->id('wh-001'), 'back');

    [, $result] = runActivation($board->build());

    expect($result->state->pendingChoice())->toBeNull()
        ->and($result->state->instance($board->id('wh-001'))->counter('damage'))->toBe(2);
});

it('does not offer an ally with no guard post', function (): void {
    // wh-010 is an ordinary ally. Being able to block is a granted permission, not a
    // property of being an ally, so it must not be offered.
    $board = wardensTable()->seat(0, 'wh-001')->inPlay(0, 'wh-010');
    $board->face($board->id('wh-001'), 'back');

    [, $result] = runActivation($board->build());

    expect($result->state->pendingChoice())->toBeNull()
        ->and($result->state->instance($board->id('wh-001'))->counter('damage'))->toBe(2);
});
