<?php

declare(strict_types=1);

use Gmd\Harness\Agent\RandomAgent;
use Gmd\Harness\Runner\MatchRunner;
use Gmd\Harness\Tests\Support\Examples;
use Gmd\Kernel\Contract\MatchSetup;
use Gmd\Kernel\Contract\SeatSetup;
use Gmd\Kernel\Kernel;
use Gmd\Kernel\Rng\Pcg64Rng;
use Gmd\Kernel\State\Codec\StateHasher;

function emberfallMatch(int $seed): Gmd\Harness\Runner\MatchOutcome
{
    $game = Examples::emberfall();
    $kernel = new Kernel($game->system);
    $runner = new MatchRunner($kernel, [
        0 => new RandomAgent(Pcg64Rng::at($seed + 1000)),
        1 => new RandomAgent(Pcg64Rng::at($seed + 2000)),
    ]);

    return $runner->run(new MatchSetup(
        [new SeatSetup(0, $game->deck('ember-aggro')), new SeatSetup(1, $game->deck('ash-control'))],
        seed: $seed,
    ));
}

it('plays a complete match between two random bots', function (): void {
    $outcome = emberfallMatch(1);

    expect($outcome->stalled)->toBeFalse($outcome->describe());
    expect($outcome->state->isOver())->toBeTrue();
    expect($outcome->actions)->not->toBeEmpty();
});

it('reaches the outcomes the game actually declares', function (): void {
    $reasons = [];
    for ($seed = 1; $seed <= 12; $seed++) {
        $reasons[] = emberfallMatch($seed)->reason();
    }

    // Every ending must be one the system document declared. An ending the engine invented
    // would mean it had a rule of its own.
    $declared = array_map(fn ($c): string => $c->id, Examples::emberfall()->system->winConditions);
    foreach ($reasons as $reason) {
        expect($declared)->toContain($reason);
    }
    // And the damage-based ending must actually be reachable, or the game cannot be won.
    expect($reasons)->toContain('hero_burned');
});

it('reproduces bit-identically from the same seed', function (): void {
    // The property everything else rests on (ADR-0005). Two runs of the same inputs are the
    // same match, down to the hash.
    $first = emberfallMatch(7);
    $second = emberfallMatch(7);

    expect(StateHasher::hash($second->state))->toBe(StateHasher::hash($first->state));
    expect(count($second->actions))->toBe(count($first->actions));
});

it('produces a different match from a different seed', function (): void {
    expect(StateHasher::hash(emberfallMatch(7)->state))
        ->not->toBe(StateHasher::hash(emberfallMatch(8)->state));
});

it('leaves a final state the published schema accepts', function (): void {
    $violations = Gmd\Kernel\Tests\Support\Schemas::violations(
        Gmd\Kernel\State\Codec\StateCodec::encode(emberfallMatch(3)->state),
        'game-state',
    );

    expect($violations)->toBe([]);
});

it('narrates what happened as an ordered event stream', function (): void {
    $outcome = emberfallMatch(1);
    $types = array_unique($outcome->eventTypes ?? array_map(fn ($e) => $e->type, $outcome->events));

    // The event list is the animation script (doc 08): the client is told what happened,
    // in order, rather than diffing two states to guess.
    expect($types)->toContain('card.played')
        ->toContain('card.entered_zone')
        ->toContain('damage.dealt')
        ->toContain('step.began');

    $seqs = array_map(fn ($e) => $e->seq, $outcome->events);
    expect($seqs)->toBe(array_values(array_unique($seqs)));
});

it('finishes a match inside the performance budget', function (): void {
    $started = hrtime(true);
    emberfallMatch(11);
    $milliseconds = (hrtime(true) - $started) / 1e6;

    // Doc 13's CI gate. Not a micro-benchmark — the point is that a ten-thousand-match
    // batch stays feasible, which is what the balance tooling is for.
    expect($milliseconds)->toBeLessThan(250.0, sprintf('a headless match took %.0fms', $milliseconds));
});
