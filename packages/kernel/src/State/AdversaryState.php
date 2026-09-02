<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * An engine-controlled side.
 *
 * `anchors` maps an anchor id declared by the system (`boss`, `mainScheme`) to the
 * instance filling it, which is how a player card can say "deal 2 damage to the villain"
 * without knowing which scenario is being played.
 */
final readonly class AdversaryState
{
    /**
     * @param  array<string, string>  $anchors  anchor id => instance id
     * @param  array<string, mixed>  $flags
     */
    public function __construct(
        public string $id,
        public array $anchors = [],
        public array $flags = [],
    ) {}

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(
            $changes['id'] ?? $this->id,
            $changes['anchors'] ?? $this->anchors,
            $changes['flags'] ?? $this->flags,
        );
    }
}
