<?php

declare(strict_types=1);

use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\Rng\Pcg64Rng;

/** @return array<string, mixed> */
function rngVectors(): array
{
    /** @var array<string, mixed> $vectors */
    $vectors = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/rng-vectors.json'), true);

    return $vectors;
}

const RNG_SEED = 8412773901;

it('reproduces the independent reference for small bounded draws', function (): void {
    $rng = Pcg64Rng::at(RNG_SEED);
    $drawn = [];
    for ($i = 0; $i < 10; $i++) {
        $drawn[] = $rng->nextInt(0, 5);
    }

    expect($drawn)->toBe(rngVectors()['nextInt_0_5']);
    expect($rng->position())->toBe(rngVectors()['nextInt_0_5_position']);
});

it('reproduces the independent reference for deck-sized bounded draws', function (): void {
    $rng = Pcg64Rng::at(RNG_SEED);
    $drawn = [];
    for ($i = 0; $i < 10; $i++) {
        $drawn[] = $rng->nextInt(1, 24);
    }

    expect($drawn)->toBe(rngVectors()['nextInt_1_24']);
});

it('reproduces the independent reference for a deck shuffle', function (): void {
    $rng = Pcg64Rng::at(RNG_SEED);

    expect($rng->shuffle(range(0, 24)))->toBe(rngVectors()['shuffle_25']);
    expect($rng->position())->toBe(rngVectors()['shuffle_25_position']);
});

it('consumes no draws for a range of one', function (): void {
    $rng = Pcg64Rng::at(RNG_SEED);

    expect([$rng->nextInt(7, 7), $rng->nextInt(7, 7), $rng->nextInt(7, 7)])->toBe([7, 7, 7]);
    expect($rng->position())->toBe(0);
});

it('reconstructs from a position counted in raw draws', function (): void {
    $continuous = Pcg64Rng::at(RNG_SEED);
    for ($i = 0; $i < 37; $i++) {
        $continuous->nextInt(0, 100);
    }
    $position = $continuous->position();

    $expected = [];
    for ($i = 0; $i < 5; $i++) {
        $expected[] = $continuous->nextInt(0, 100);
    }

    $resumed = Pcg64Rng::at(RNG_SEED, $position);
    $actual = [];
    for ($i = 0; $i < 5; $i++) {
        $actual[] = $resumed->nextInt(0, 100);
    }

    expect($actual)->toBe($expected);
});

it('produces a uniform-looking distribution over a small range', function (): void {
    $rng = Pcg64Rng::at(1);
    $counts = array_fill(0, 6, 0);
    for ($i = 0; $i < 60000; $i++) {
        $counts[$rng->nextInt(0, 5)]++;
    }

    // A biased shuffle is still perfectly deterministic, so no conformance test would ever
    // catch one. This is the only place bias gets caught.
    foreach ($counts as $face => $count) {
        expect($count)->toBeGreaterThan(9000, "face {$face} came up {$count} times in 60000");
        expect($count)->toBeLessThan(11000, "face {$face} came up {$count} times in 60000");
    }
});

it('refuses an empty range', function (): void {
    Pcg64Rng::at(1)->nextInt(5, 4);
})->throws(BadDocument::class);

it('refuses a negative position', function (): void {
    Pcg64Rng::at(1, -1);
})->throws(BadDocument::class);

it('picks from a list without disturbing it', function (): void {
    $rng = Pcg64Rng::at(RNG_SEED);
    $items = ['a', 'b', 'c'];

    expect($items)->toContain($rng->pick($items));
    expect($items)->toBe(['a', 'b', 'c']);
});
