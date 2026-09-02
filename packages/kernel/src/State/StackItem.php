<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * Something currently resolving: an ability, an action's effect, a state check's response,
 * an adversary activation, or the setup script.
 *
 * Everything needed to continue is here and is JSON-serialisable. That is what makes undo
 * by truncate-and-replay exact, lets a live table survive a worker restart mid-effect, and
 * makes the stack part of the conformance hash instead of hidden interpreter state.
 */
final readonly class StackItem
{
    public const KIND_ACTION = 'action';
    public const KIND_ABILITY = 'ability';
    public const KIND_STATE_CHECK = 'state_check';
    public const KIND_ACTIVATION = 'adversary_activation';
    public const KIND_SETUP = 'setup';
    public const KIND_STEP = 'step';

    /**
     * @param  list<StackFrame>  $frames  innermost last
     * @param  array<string, mixed>  $bindings  serialisable selector bindings ($self, $you, $target.*, $event.*)
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $controller,
        public array $frames,
        public array $bindings = [],
        public ?string $sourceInstance = null,
        public ?string $abilityId = null,
        public int $depth = 0,
        public ?string $awaiting = null,
    ) {}

    public function top(): StackFrame
    {
        return $this->frames[count($this->frames) - 1];
    }

    public function isDone(): bool
    {
        return $this->frames === [];
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['kind'] ?? $this->kind,
            $changes['controller'] ?? $this->controller,
            $changes['frames'] ?? $this->frames,
            $changes['bindings'] ?? $this->bindings,
            array_key_exists('sourceInstance', $changes) ? $changes['sourceInstance'] : $this->sourceInstance,
            array_key_exists('abilityId', $changes) ? $changes['abilityId'] : $this->abilityId,
            $changes['depth'] ?? $this->depth,
            array_key_exists('awaiting', $changes) ? $changes['awaiting'] : $this->awaiting,
        );
    }
}
