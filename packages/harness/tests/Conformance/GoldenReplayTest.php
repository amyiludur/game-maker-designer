<?php

declare(strict_types=1);

use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Loader\ReplayFile;
use Gmd\Harness\Runner\ReplayRunner;

/*
 | The conformance suite (ADR-0002).
 |
 | Every blessed replay is played again from its inputs — game version, decks, seed — and
 | every checkpoint hash must match. This is what proves the engine is deterministic across
 | machines and PHP versions, catches a refactor that quietly changed a rule, and is the bar
 | any second implementation of the kernel would have to clear before it could ship.
 |
 | The initial position is rebuilt rather than restored, so the setup script is under test too.
 */

/** @return list<array{0: string}> */
function goldenReplays(): array
{
    $files = glob(FixtureLoader::repositoryRoot() . '/examples/*/replays/*.json') ?: [];
    sort($files);

    return array_map(static fn (string $file): array => [$file], $files);
}

it('reproduces every golden replay exactly', function (string $file): void {
    $loader = new FixtureLoader;
    $replay = ReplayFile::fromArray($file, $loader->readJson($file));

    if (! $replay->isBlessed()) {
        // Not a failure: a replay recorded from a playtest is useful before anyone has
        // decided its behaviour is the intended one. It just is not a conformance target yet.
        expect(true)->toBeTrue();

        return;
    }

    $result = new ReplayRunner($loader)->verify($replay);

    expect($result->problems)->toBe([], "the replay could not be run to the end:\n  "
        . implode("\n  ", $result->problems));
    expect($result->divergences)->toBe([], "the replay ran but reached a different position:\n  "
        . implode("\n  ", $result->divergences));
    expect($result->ranEveryAction())->toBeTrue();
})->with(goldenReplays());

it('has at least one conformance fixture to check', function (): void {
    // A suite that silently checks nothing passes forever.
    expect(goldenReplays())->not->toBeEmpty();
});

it('covers both shapes a game in this format can take', function (): void {
    // The competitive duel and the cooperative scenario exercise different halves of the
    // kernel — interleaved priority against per-player turns, a second seat against a
    // scripted adversary. A conformance suite covering only one of them would let the other
    // regress in silence, which is exactly what happened to Warden's Hollow for months.
    $games = [];
    foreach (goldenReplays() as [$file]) {
        $document = (new FixtureLoader)->readJson($file);
        if (isset($document['expected']['finalStateHash'])) {
            $games[(string) $document['gameId']] = true;
        }
    }

    expect(array_keys($games))->toContain('emberfall')->toContain('wardens-hollow');
});

it('rebuilds the opening position rather than restoring one', function (): void {
    // A replay that carried its initial state would prove the actions still work and say
    // nothing about whether setup still deals the same cards.
    foreach (goldenReplays() as [$file]) {
        $document = (new FixtureLoader)->readJson($file);
        expect($document)->not->toHaveKey('initialState');
    }
});
