<?php

declare(strict_types=1);

namespace Gmd\Kernel\State;

/**
 * One thing that happened, in order.
 *
 * The event list is the animation script (doc 08): the client is told what happened rather
 * than diffing two states to guess. It is also the event log the play table narrates, and
 * the audit trail behind a balance report's "damage contributed by this card".
 */
final readonly class EventRecord
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $seq,
        public string $type,
        public array $payload = [],
        public ?string $source = null,
        public ?int $round = null,
        public ?string $phase = null,
        public ?string $step = null,
    ) {}

    public function get(string $key): mixed
    {
        return $this->payload[$key] ?? null;
    }

    /** @param array<string, mixed> $payload */
    public function withPayload(array $payload): self
    {
        return new self($this->seq, $this->type, $payload, $this->source, $this->round, $this->phase, $this->step);
    }
}
