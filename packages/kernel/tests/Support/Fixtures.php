<?php

declare(strict_types=1);

namespace Gmd\Kernel\Tests\Support;

use Gmd\Kernel\System\SystemCompiler;
use Gmd\Kernel\System\SystemDocument;

/**
 * The worked example games, compiled once per process.
 *
 * The kernel itself does no I/O; its tests may, and do, because testing the rules against
 * the real Emberfall cards catches things a toy fixture never would.
 */
final class Fixtures
{
    /** @var array<string, SystemDocument> */
    private static array $compiled = [];

    public static function system(string $game): SystemDocument
    {
        return self::$compiled[$game] ??= (new SystemCompiler)->compile(
            self::json(self::path($game) . '/game-system.json'),
            self::sets($game),
        );
    }

    public static function emberfall(): SystemDocument
    {
        return self::system('emberfall');
    }

    public static function wardensHollow(): SystemDocument
    {
        return self::system('wardens-hollow');
    }

    /** @return array<string, mixed> */
    public static function deck(string $game, string $name): array
    {
        return self::json(self::path($game) . '/decks/' . $name . '.json');
    }

    public static function path(string $game): string
    {
        return dirname(__DIR__, 4) . '/examples/' . $game;
    }

    /** @return array<string, mixed> */
    public static function json(string $file): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private static function sets(string $game): array
    {
        $files = glob(self::path($game) . '/sets/*.json') ?: [];
        sort($files);

        return array_map(self::json(...), $files);
    }
}
