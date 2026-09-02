<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\BadDocument;

/**
 * A committed game position: everything the kernel needs to continue, and nothing else.
 *
 * Two absences are deliberate and load-bearing.
 *
 * Derived characteristics are not here (ADR-0004): a character's current attack is
 * recomputed from its printed value and the active modifiers on every read, so a buff that
 * expires cannot leave a stale number behind.
 *
 * The random generator is not here either — only `seed` and `rngPosition` are (ADR-0005).
 * That keeps the state pure JSON while still describing the stream exactly, which is what
 * makes a match resumed from the database continue the shuffles it would have had.
 */
final readonly class GameState implements StateView
{
    /**
     * @param  list<PlayerState>  $players  indexed by seat
     * @param  array<string, list<string>>  $zones  qualified zone key => ordered instance ids
     * @param  array<string, Instance>  $instances
     * @param  array<string, AdversaryState>  $adversaries
     * @param  list<ModifierRecord>  $modifiers  sorted by (layer, timestamp, id)
     * @param  list<StackItem>  $stack  top last
     * @param  list<TriggerRecord>  $triggerQueue
     * @param  array<string, mixed>  $vars
     * @param  list<EventRecord>  $log
     */
    public function __construct(
        public string $systemId,
        public string $systemVersion,
        public string $systemDigest,
        public int $seed,
        public int $rngPosition,
        public int $version,
        public int $round,
        public string $phase,
        public string $step,
        public int $activeSeat,
        public int $firstSeat,
        public array $players,
        public array $zones,
        public array $instances,
        public array $adversaries = [],
        public array $modifiers = [],
        public array $stack = [],
        public array $triggerQueue = [],
        public ?PendingChoice $pendingChoice = null,
        public array $vars = [],
        public array $log = [],
        public ?MatchResult $result = null,
        public ?int $priority = null,
        public int $consecutivePasses = 0,
    ) {}

    public function round(): int
    {
        return $this->round;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function step(): string
    {
        return $this->step;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function playerCount(): int
    {
        return count($this->players);
    }

    public function activeSide(): string
    {
        return Side::player($this->activeSeat);
    }

    public function firstSide(): string
    {
        return Side::player($this->firstSeat);
    }

    public function sides(): array
    {
        return [...$this->playerSides(), ...array_keys($this->adversaries)];
    }

    public function playerSides(): array
    {
        return array_map(static fn (PlayerState $p): string => $p->side(), $this->players);
    }

    /** The qualified step id a window is addressed by, e.g. "action.main". */
    public function qualifiedStep(): string
    {
        return $this->phase . '.' . $this->step;
    }

    public function instance(string $id): Instance
    {
        return $this->instances[$id]
            ?? throw BadDocument::because("no such card instance \"{$id}\"");
    }

    public function hasInstance(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    public function instances(): array
    {
        return $this->instances;
    }

    public function zone(string $zoneKey): array
    {
        return $this->zones[$zoneKey] ?? [];
    }

    public function zones(): array
    {
        return $this->zones;
    }

    public function player(int $seat): PlayerState
    {
        return $this->players[$seat]
            ?? throw BadDocument::because("no such seat {$seat}");
    }

    public function players(): array
    {
        return $this->players;
    }

    public function playerBySide(string $side): PlayerState
    {
        return $this->player(Side::seatOrFail($side));
    }

    public function adversary(string $id): ?AdversaryState
    {
        return $this->adversaries[$id] ?? null;
    }

    public function adversaries(): array
    {
        return $this->adversaries;
    }

    public function modifiers(): array
    {
        return $this->modifiers;
    }

    public function var(string $key, mixed $default = null): mixed
    {
        return $this->vars[$key] ?? $default;
    }

    public function vars(): array
    {
        return $this->vars;
    }

    public function pendingChoice(): ?PendingChoice
    {
        return $this->pendingChoice;
    }

    public function result(): ?MatchResult
    {
        return $this->result;
    }

    public function stack(): array
    {
        return $this->stack;
    }

    public function triggerQueue(): array
    {
        return $this->triggerQueue;
    }

    public function isOver(): bool
    {
        return $this->result !== null;
    }

    /**
     * A shallow rebuild with some fields replaced.
     *
     * Untouched instances and zones are carried over by reference; they are readonly, so
     * sharing them is safe, and a combat step that touches four of sixty cards allocates
     * four objects rather than sixty.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(
            $changes['systemId'] ?? $this->systemId,
            $changes['systemVersion'] ?? $this->systemVersion,
            $changes['systemDigest'] ?? $this->systemDigest,
            $changes['seed'] ?? $this->seed,
            $changes['rngPosition'] ?? $this->rngPosition,
            $changes['version'] ?? $this->version,
            $changes['round'] ?? $this->round,
            $changes['phase'] ?? $this->phase,
            $changes['step'] ?? $this->step,
            $changes['activeSeat'] ?? $this->activeSeat,
            $changes['firstSeat'] ?? $this->firstSeat,
            $changes['players'] ?? $this->players,
            $changes['zones'] ?? $this->zones,
            $changes['instances'] ?? $this->instances,
            $changes['adversaries'] ?? $this->adversaries,
            $changes['modifiers'] ?? $this->modifiers,
            $changes['stack'] ?? $this->stack,
            $changes['triggerQueue'] ?? $this->triggerQueue,
            array_key_exists('pendingChoice', $changes) ? $changes['pendingChoice'] : $this->pendingChoice,
            $changes['vars'] ?? $this->vars,
            $changes['log'] ?? $this->log,
            array_key_exists('result', $changes) ? $changes['result'] : $this->result,
            array_key_exists('priority', $changes) ? $changes['priority'] : $this->priority,
            $changes['consecutivePasses'] ?? $this->consecutivePasses,
        );
    }
}
