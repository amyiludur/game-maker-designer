<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

use Gmd\Kernel\Budgets;
use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\Contract\Side;
use Gmd\Kernel\Diagnostics\BadDocument;
use Gmd\Kernel\Rng\Rng;

/**
 * A game position part-way through a change.
 *
 * Effects are not single mutations — playing a card moves it, pays a cost, fires triggers
 * and may kill something — so the kernel opens a Draft, works in it, and commits once. The
 * alternative, rebuilding a readonly GameState per mutation, allocates the whole world
 * dozens of times per action and dominates the time budget.
 *
 * A Draft is the only mutable thing in the kernel, and it never escapes: `apply` and
 * `settle` open one, commit it, and return a GameState. Because it implements StateView,
 * expressions and queries evaluate against a half-finished effect exactly as they do
 * against a committed position.
 */
final class Draft implements StateView
{
    /** Bumped by every write; characteristic caches key on it. */
    public int $mutationCounter = 0;

    /**
     * Instances whose characteristics may have changed since the last recompute.
     * `null` means "everything" — see markDirty().
     *
     * @var array<string, true>|null
     */
    private ?array $dirty = [];

    /** @var list<EventRecord> events emitted during this draft, in order */
    private array $emitted = [];

    /** @var array<string, PlayerState> */
    private array $players;

    /** @param array<string, list<string>> $zones */
    private function __construct(
        private readonly GameState $base,
        private readonly Rng $rng,
        private array $zones,
        /** @var array<string, Instance> */
        private array $instances,
        /** @var array<string, AdversaryState> */
        private array $adversaries,
        /** @var list<ModifierRecord> */
        private array $modifiers,
        /** @var list<StackItem> */
        private array $stack,
        /** @var list<TriggerRecord> */
        private array $triggerQueue,
        /** @var array<string, mixed> */
        private array $vars,
        private ?PendingChoice $pendingChoice,
        private ?MatchResult $result,
        private int $round,
        private string $phase,
        private string $step,
        private int $activeSeat,
        private int $firstSeat,
        private ?int $priority,
        private int $consecutivePasses,
        array $players,
    ) {
        $this->players = $players;
    }

    public static function of(GameState $state, Rng $rng): self
    {
        $players = [];
        foreach ($state->players as $player) {
            $players[(string) $player->seat] = $player;
        }

        return new self(
            $state,
            $rng,
            $state->zones,
            $state->instances,
            $state->adversaries,
            $state->modifiers,
            $state->stack,
            $state->triggerQueue,
            $state->vars,
            $state->pendingChoice,
            $state->result,
            $state->round,
            $state->phase,
            $state->step,
            $state->activeSeat,
            $state->firstSeat,
            $state->priority,
            $state->consecutivePasses,
            $players,
        );
    }

    public function commit(): GameState
    {
        ksort($this->players);
        $log = [...$this->base->log, ...$this->emitted];
        if (count($log) > Budgets::LOG_CAPACITY) {
            $log = array_slice($log, -Budgets::LOG_CAPACITY);
        }

        return $this->base->with([
            'version' => $this->base->version + 1,
            'rngPosition' => $this->rng->position(),
            'round' => $this->round,
            'phase' => $this->phase,
            'step' => $this->step,
            'activeSeat' => $this->activeSeat,
            'firstSeat' => $this->firstSeat,
            'priority' => $this->priority,
            'consecutivePasses' => $this->consecutivePasses,
            'players' => array_values($this->players),
            'zones' => $this->zones,
            'instances' => $this->instances,
            'adversaries' => $this->adversaries,
            'modifiers' => $this->modifiers,
            'stack' => $this->stack,
            'triggerQueue' => $this->triggerQueue,
            'pendingChoice' => $this->pendingChoice,
            'vars' => $this->vars,
            'log' => array_values($log),
            'result' => $this->result,
        ]);
    }

    public function rng(): Rng
    {
        return $this->rng;
    }

    /**
     * A throwaway copy of this position, for asking "what would happen if".
     *
     * Used by the cost checker, which pays a cost for real on a copy and then discards it.
     * Copying is nearly free — PHP shares the underlying arrays until one side writes — and
     * it costs far less than rebuilding a committed state just to fork from it.
     */
    public function fork(Rng $rng): self
    {
        $fork = new self(
            $this->base,
            $rng,
            $this->zones,
            $this->instances,
            $this->adversaries,
            $this->modifiers,
            [],
            $this->triggerQueue,
            $this->vars,
            $this->pendingChoice,
            $this->result,
            $this->round,
            $this->phase,
            $this->step,
            $this->activeSeat,
            $this->firstSeat,
            $this->priority,
            $this->consecutivePasses,
            $this->players,
        );
        $fork->mutationCounter = $this->mutationCounter;

        return $fork;
    }

    /** @return list<EventRecord> everything emitted since this draft opened */
    public function emitted(): array
    {
        return $this->emitted;
    }

    // ---------------------------------------------------------------- reads

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

    public function qualifiedStep(): string
    {
        return $this->phase . '.' . $this->step;
    }

    public function version(): int
    {
        return $this->base->version;
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
        return array_map(static fn (PlayerState $p): string => $p->side(), array_values($this->players));
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
        return $this->players[(string) $seat]
            ?? throw BadDocument::because("no such seat {$seat}");
    }

    public function playerBySide(string $side): PlayerState
    {
        return $this->player(Side::seatOrFail($side));
    }

    public function players(): array
    {
        $players = $this->players;
        ksort($players);

        return array_values($players);
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

    /** @return array<string, true>|null null means every instance is dirty */
    public function dirty(): ?array
    {
        return $this->dirty;
    }

    // --------------------------------------------------------------- writes

    /**
     * Record that an instance's characteristics may have changed.
     *
     * Passing null widens it to the whole board, which is required whenever the change can
     * affect *other* cards: a modifier appearing or expiring, or a card with a static
     * ability moving, changing controller or flipping. Getting this wrong is silent, so
     * every caller that is unsure should widen.
     */
    public function markDirty(?string $instanceId): void
    {
        $this->mutationCounter++;
        if ($instanceId === null) {
            $this->dirty = null;

            return;
        }
        if ($this->dirty !== null) {
            $this->dirty[$instanceId] = true;
        }
    }

    public function putInstance(Instance $instance, bool $widensBoard = true): void
    {
        $this->instances[$instance->id] = $instance;
        $this->markDirty($widensBoard ? null : $instance->id);
    }

    public function forgetInstance(string $id): void
    {
        unset($this->instances[$id]);
        $this->markDirty(null);
    }

    /** @param array<string, mixed> $changes */
    public function mutateInstance(string $id, array $changes, bool $widensBoard = true): Instance
    {
        $updated = $this->instance($id)->with($changes);
        $this->putInstance($updated, $widensBoard);

        return $updated;
    }

    /** @param list<string> $instanceIds */
    public function setZone(string $zoneKey, array $instanceIds): void
    {
        $this->zones[$zoneKey] = array_values($instanceIds);
        $this->markDirty(null);
    }

    public function removeFromZone(string $zoneKey, string $instanceId): void
    {
        $zone = $this->zones[$zoneKey] ?? [];
        $at = array_search($instanceId, $zone, true);
        if ($at !== false) {
            array_splice($zone, (int) $at, 1);
            $this->zones[$zoneKey] = $zone;
            $this->markDirty(null);
        }
    }

    /** @param 'top'|'bottom'|int $position */
    public function insertIntoZone(string $zoneKey, string $instanceId, string|int $position = 'bottom'): void
    {
        $zone = $this->zones[$zoneKey] ?? [];
        if ($position === 'top') {
            array_unshift($zone, $instanceId);
        } elseif ($position === 'bottom') {
            $zone[] = $instanceId;
        } else {
            array_splice($zone, max(0, min((int) $position, count($zone))), 0, [$instanceId]);
        }
        $this->zones[$zoneKey] = $zone;
        $this->markDirty(null);
    }

    public function setPlayer(PlayerState $player): void
    {
        $this->players[(string) $player->seat] = $player;
        $this->mutationCounter++;
    }

    public function setAdversary(AdversaryState $adversary): void
    {
        $this->adversaries[$adversary->id] = $adversary;
        $this->mutationCounter++;
    }

    public function addModifier(ModifierRecord $modifier): void
    {
        $this->modifiers[] = $modifier;
        $this->sortModifiers();
        $this->markDirty(null);
    }

    /** @param callable(ModifierRecord): bool $predicate */
    public function removeModifiers(callable $predicate): int
    {
        $kept = array_values(array_filter(
            $this->modifiers,
            static fn (ModifierRecord $m): bool => ! $predicate($m),
        ));
        $removed = count($this->modifiers) - count($kept);
        if ($removed > 0) {
            $this->modifiers = $kept;
            $this->markDirty(null);
        }

        return $removed;
    }

    public function setVar(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
        $this->mutationCounter++;
    }

    public function setPendingChoice(?PendingChoice $choice): void
    {
        $this->pendingChoice = $choice;
        $this->mutationCounter++;
    }

    public function setResult(?MatchResult $result): void
    {
        $this->result = $result;
        $this->mutationCounter++;
    }

    public function setRound(int $round): void
    {
        $this->round = $round;
        $this->mutationCounter++;
    }

    public function setPhaseStep(string $phase, string $step): void
    {
        $this->phase = $phase;
        $this->step = $step;
        $this->mutationCounter++;
    }

    public function setActiveSeat(int $seat): void
    {
        $this->activeSeat = $seat;
        $this->mutationCounter++;
    }

    public function setFirstSeat(int $seat): void
    {
        $this->firstSeat = $seat;
        $this->mutationCounter++;
    }

    public function setPriority(?int $seat): void
    {
        $this->priority = $seat;
        $this->mutationCounter++;
    }

    public function priority(): ?int
    {
        return $this->priority;
    }

    public function isOver(): bool
    {
        return $this->result !== null;
    }

    public function seed(): int
    {
        return $this->base->seed;
    }

    public function rngPosition(): int
    {
        return $this->rng->position();
    }

    public function setConsecutivePasses(int $passes): void
    {
        $this->consecutivePasses = $passes;
        $this->mutationCounter++;
    }

    public function consecutivePasses(): int
    {
        return $this->consecutivePasses;
    }

    /** @param list<StackItem> $stack */
    public function setStack(array $stack): void
    {
        $this->stack = array_values($stack);
        $this->mutationCounter++;
    }

    public function pushStack(StackItem $item): void
    {
        $this->stack[] = $item;
        $this->mutationCounter++;
    }

    public function replaceTopOfStack(StackItem $item): void
    {
        if ($this->stack === []) {
            throw BadDocument::because('cannot replace the top of an empty stack');
        }
        $this->stack[count($this->stack) - 1] = $item;
        $this->mutationCounter++;
    }

    public function popStack(): StackItem
    {
        $item = array_pop($this->stack);
        if ($item === null) {
            throw BadDocument::because('cannot pop an empty stack');
        }
        $this->mutationCounter++;

        return $item;
    }

    public function queueTrigger(TriggerRecord $trigger): void
    {
        $this->triggerQueue[] = $trigger;
        $this->mutationCounter++;
    }

    /** @param list<TriggerRecord> $queue */
    public function setTriggerQueue(array $queue): void
    {
        $this->triggerQueue = array_values($queue);
        $this->mutationCounter++;
    }

    public function emit(EventRecord $event): void
    {
        $this->emitted[] = $event;
        $this->mutationCounter++;
    }

    // ------------------------------------------------- deterministic id allocation

    /** A monotonic counter shared by modifier timestamps and card entry times. */
    public function tick(): int
    {
        $next = (int) ($this->vars['__ts'] ?? 0) + 1;
        $this->vars['__ts'] = $next;

        return $next;
    }

    public function nextEventSeq(): int
    {
        $next = (int) ($this->vars['__eventSeq'] ?? 0) + 1;
        $this->vars['__eventSeq'] = $next;

        return $next;
    }

    public function nextId(string $kind, string $prefix): string
    {
        $key = '__ordinal.' . $kind;
        $next = (int) ($this->vars[$key] ?? 0) + 1;
        $this->vars[$key] = $next;

        return $prefix . $next;
    }

    /** Instance ids are dense and per-side: i-p0-1 is that seat's identity card. */
    public function nextInstanceId(string $side): string
    {
        $key = '__ordinal.instance.' . $side;
        $next = (int) ($this->vars[$key] ?? 0) + 1;
        $this->vars[$key] = $next;

        return 'i-' . $side . '-' . $next;
    }

    /** When a card entered its current zone; the timestamp a derived modifier orders by. */
    public function recordEntry(string $instanceId): int
    {
        $ts = $this->tick();
        /** @var array<string, int> $entries */
        $entries = $this->vars['__enteredAt'] ?? [];
        $entries[$instanceId] = $ts;
        $this->vars['__enteredAt'] = $entries;

        return $ts;
    }

    public function entryTimestamp(string $instanceId): int
    {
        /** @var array<string, int> $entries */
        $entries = $this->vars['__enteredAt'] ?? [];

        return $entries[$instanceId] ?? 0;
    }

    private function sortModifiers(): void
    {
        usort($this->modifiers, static function (ModifierRecord $a, ModifierRecord $b): int {
            return [$a->layer, $a->timestamp, $a->id] <=> [$b->layer, $b->timestamp, $b->id];
        });
    }
}
