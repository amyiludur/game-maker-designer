<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

use Gmd\Kernel\Contract\Side;

/** A seat: its economy, its identity card, and whether it is still in the game. */
final readonly class PlayerState
{
    /**
     * @param  array<string, int>  $resources
     * @param  array<string, mixed>  $flags
     */
    public function __construct(
        public int $seat,
        public array $resources = [],
        public array $flags = [],
        public ?string $identityInstance = null,
        public string $status = 'playing',
    ) {}

    public function side(): string
    {
        return Side::player($this->seat);
    }

    public function resource(string $id): int
    {
        return $this->resources[$id] ?? 0;
    }

    public function isPlaying(): bool
    {
        return $this->status === 'playing';
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(
            $changes['seat'] ?? $this->seat,
            $changes['resources'] ?? $this->resources,
            $changes['flags'] ?? $this->flags,
            array_key_exists('identityInstance', $changes) ? $changes['identityInstance'] : $this->identityInstance,
            $changes['status'] ?? $this->status,
        );
    }
}
