<?php

declare(strict_types=1);

namespace Gmd\Kernel\Event;

/**
 * Something that is happening, while it is still happening.
 *
 * Distinct from the EventRecord that ends up in the log: this one can still be changed or
 * cancelled by a replacement effect ("if you would take damage, prevent 1 instead") before
 * the state moves.
 */
final readonly class GameEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $appliedReplacements  ability keys that have already replaced this event
     */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $source = null,
        public array $appliedReplacements = [],
        public bool $prevented = false,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function withField(string $key, mixed $value): self
    {
        return new self(
            $this->type,
            [...$this->payload, $key => $value],
            $this->source,
            $this->appliedReplacements,
            $this->prevented,
        );
    }

    public function prevented(): self
    {
        return new self($this->type, $this->payload, $this->source, $this->appliedReplacements, true);
    }

    public function replacedBy(string $abilityKey): self
    {
        return new self(
            $this->type,
            $this->payload,
            $this->source,
            [...$this->appliedReplacements, $abilityKey],
            $this->prevented,
        );
    }

    public function wasReplacedBy(string $abilityKey): bool
    {
        return in_array($abilityKey, $this->appliedReplacements, true);
    }

    /**
     * The `$event.*` selectors a trigger filter can read.
     *
     * @return array<string, mixed>
     */
    public function bindings(): array
    {
        $bindings = ['event' => $this->type];
        foreach ($this->payload as $key => $value) {
            $bindings['event.' . $key] = $value;
        }

        return $bindings;
    }
}
