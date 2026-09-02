<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

use Gmd\Kernel\Diagnostics\BadDocument;

/**
 * Side ids.
 *
 * A side is anything that can own cards, hold zones and act: a player seat (`p0`, `p1`),
 * an engine-controlled adversary (`villain`), or the table itself (`shared`). Doc 16 §1
 * makes these strings rather than seat integers, which is what lets a co-op scenario and a
 * competitive duel be two configurations of one format instead of two engines.
 *
 * They are plain strings, not value objects, because they are array keys in the hottest
 * paths in the kernel. This class is where the conventions live.
 */
final class Side
{
    public const SHARED = 'shared';

    public static function player(int $seat): string
    {
        if ($seat < 0) {
            throw BadDocument::because("seat numbers start at 0, got {$seat}");
        }

        return 'p' . $seat;
    }

    public static function isPlayer(string $side): bool
    {
        return self::seatOf($side) !== null;
    }

    /** The seat behind a player side id, or null for an adversary or the shared side. */
    public static function seatOf(string $side): ?int
    {
        if (! str_starts_with($side, 'p') || strlen($side) < 2) {
            return null;
        }

        $digits = substr($side, 1);

        return ctype_digit($digits) ? (int) $digits : null;
    }

    public static function seatOrFail(string $side): int
    {
        return self::seatOf($side) ?? throw BadDocument::because("side \"{$side}\" is not a player seat");
    }

    /** Zone keys are always qualified by the side that holds them: `p0.hand`, `shared.removed`. */
    public static function zoneKey(string $side, string $zoneId): string
    {
        return $side . '.' . $zoneId;
    }

    /** @return array{0: string, 1: string} the side and the bare zone id */
    public static function splitZoneKey(string $zoneKey): array
    {
        $at = strpos($zoneKey, '.');
        if ($at === false) {
            throw BadDocument::because("zone key \"{$zoneKey}\" is not qualified by a side");
        }

        return [substr($zoneKey, 0, $at), substr($zoneKey, $at + 1)];
    }
}
