<?php

declare(strict_types=1);

use Gmd\Harness\Runner\FuzzRunner;
use Gmd\Harness\Tests\Support\Examples;
use Gmd\Kernel\Kernel;

/*
 | Random bots, invariants asserted after every action (doc 13).
 |
 | Random play is the point: it reaches board positions no heuristic would ever choose, and
 | it does not need to be good at the game to prove the engine never breaks. The number here
 | is small enough for CI; the real bar is a ten-thousand-match run, which `gmd fuzz` does.
 */

it('never violates a structural invariant over many random matches', function (): void {
    $game = Examples::emberfall();
    $report = new FuzzRunner($game, new Kernel($game->system))->run(matches: 60);

    $failures = array_map(fn ($f): string => $f->describe(), $report->failures);

    expect($failures)->toBe([], "random play broke something:\n  " . implode("\n  ", $failures));
    expect($report->rounds)->toHaveCount(60);
});

it('finishes every random match rather than stalling', function (): void {
    $game = Examples::emberfall();
    $report = new FuzzRunner($game, new Kernel($game->system))->run(matches: 30, firstSeed: 5000);

    // A stall means the settle loop reached a position where nobody could act and nothing
    // was resolving, which is a kernel bug rather than a legitimate way for a game to end.
    expect($report->stalls)->toBe(0);
});

it('reaches both of the ways the game can end', function (): void {
    $game = Examples::emberfall();
    $report = new FuzzRunner($game, new Kernel($game->system))->run(matches: 40);

    expect(array_keys($report->reasons))->toContain('hero_burned');
    // Random bots play badly and often run out the clock; doc 09's 5% STALL threshold is a
    // statement about competent play, not about this. What matters here is that the round
    // cap exists and fires, because it is the only thing standing between a degenerate line
    // and a simulation batch that never returns.
    expect(array_keys($report->reasons))->toContain('round_limit');
});
