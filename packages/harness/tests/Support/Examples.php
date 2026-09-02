<?php

declare(strict_types=1);

namespace Gmd\Harness\Tests\Support;

use Gmd\Harness\Loader\FixtureLoader;
use Gmd\Harness\Loader\GameFixture;

/**
 * The repository's two worked example games, compiled once per process.
 *
 * Tests run against these rather than toy fixtures because they are the conformance
 * targets: emberfall is the competitive duel, wardens-hollow the cooperative scenario, and
 * between them they cover both shapes the platform claims to support.
 */
final class Examples
{
    /** @var array<string, GameFixture> */
    private static array $loaded = [];

    public static function game(string $name): GameFixture
    {
        return self::$loaded[$name] ??= (new FixtureLoader)->load(FixtureLoader::examplePath($name));
    }

    public static function emberfall(): GameFixture
    {
        return self::game('emberfall');
    }

    public static function wardensHollow(): GameFixture
    {
        return self::game('wardens-hollow');
    }
}
