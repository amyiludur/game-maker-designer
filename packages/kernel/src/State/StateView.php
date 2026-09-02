<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

use Gmd\Kernel\Contract\PendingChoice;

/**
 * The read-only surface of a game position.
 *
 * Implemented by both GameState (a committed position) and Draft (a position part-way
 * through an effect). That is what lets one expression evaluator and one query engine serve
 * both legality checks and mid-effect evaluation, instead of two subtly different copies
 * that drift apart.
 */
interface StateView
{
    public function round(): int;

    public function phase(): string;

    public function step(): string;

    public function version(): int;

    public function playerCount(): int;

    public function activeSide(): string;

    public function firstSide(): string;

    /** @return list<string> every side id that can act or own cards, in seat then adversary order */
    public function sides(): array;

    /** @return list<string> player side ids in seat order, eliminated seats included */
    public function playerSides(): array;

    public function instance(string $id): Instance;

    public function hasInstance(string $id): bool;

    /** @return array<string, Instance> */
    public function instances(): array;

    /** @return list<string> instance ids, in zone order; index 0 is the top of an ordered zone */
    public function zone(string $zoneKey): array;

    /** @return array<string, list<string>> */
    public function zones(): array;

    public function player(int $seat): PlayerState;

    /** @return list<PlayerState> */
    public function players(): array;

    public function adversary(string $id): ?AdversaryState;

    /** @return array<string, AdversaryState> */
    public function adversaries(): array;

    /** @return list<ModifierRecord> sorted by (layer, timestamp, id) */
    public function modifiers(): array;

    public function var(string $key, mixed $default = null): mixed;

    /** @return array<string, mixed> */
    public function vars(): array;

    public function pendingChoice(): ?PendingChoice;

    public function result(): ?MatchResult;

    /** @return list<StackItem> innermost last; the top of the stack is the last element */
    public function stack(): array;

    /** @return list<TriggerRecord> */
    public function triggerQueue(): array;
}
