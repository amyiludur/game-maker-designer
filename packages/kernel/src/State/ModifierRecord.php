<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * One continuous effect currently altering the board.
 *
 * The `targets`/`query` split is the important field here, and it is a genuine behavioural
 * difference rather than an implementation detail:
 *
 *  - `targets` — resolved once, when the modifier was created. Bolster's "+1 attack to
 *    another friendly character this round" picks its card then, and a character played
 *    afterwards does not get it.
 *  - `query` — re-evaluated on every recompute. Warhorn Bearer's "your Soldiers get +1"
 *    must pick up a Soldier played later, and must let go of it when Warhorn leaves play.
 *
 * Collapsing the two would make one of those two card texts wrong, so the state records
 * which one this modifier is.
 */
final readonly class ModifierRecord
{
    public const DURATION_INSTANT = 'instant';
    public const DURATION_STEP = 'step';
    public const DURATION_PHASE = 'phase';
    public const DURATION_TURN = 'turn';
    public const DURATION_ROUND = 'round';
    public const DURATION_WHILE_SOURCE_IN_PLAY = 'while_source_in_play';
    public const DURATION_PERMANENT = 'permanent';

    /**
     * @param  list<array{attr: string, mode: string, value: mixed}>  $changes
     * @param  list<string>|null  $targets  resolved instance ids, or null when driven by $query
     * @param  array<string, mixed>|null  $query
     */
    public function __construct(
        public string $id,
        public string $source,
        public int $layer,
        public int $timestamp,
        public array $changes,
        public ?array $targets = null,
        public ?array $query = null,
        public string $duration = self::DURATION_PERMANENT,
        public ?string $abilityId = null,
    ) {}

    public function appliesToFixedTargets(): bool
    {
        return $this->targets !== null;
    }
}
