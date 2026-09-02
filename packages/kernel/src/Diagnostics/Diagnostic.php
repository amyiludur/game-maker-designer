<?php

declare(strict_types=1);

namespace Gmd\Kernel\Diagnostics;

/**
 * A structured, serialisable description of something the kernel refused or could not do.
 *
 * The context fields exist so the UI can say which card and which ability, rather than
 * printing a stack trace at a designer.
 */
final readonly class Diagnostic implements \JsonSerializable
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public DiagnosticCode $code,
        public string $message,
        public ?string $instanceId = null,
        public ?string $cardCode = null,
        public ?string $cardName = null,
        public ?string $abilityId = null,
        public ?int $round = null,
        public ?int $stateVersion = null,
        public array $context = [],
    ) {}

    /** @param array<string, mixed> $context */
    public function withContext(array $context): self
    {
        return new self(
            $this->code,
            $this->message,
            $this->instanceId,
            $this->cardCode,
            $this->cardName,
            $this->abilityId,
            $this->round,
            $this->stateVersion,
            [...$this->context, ...$context],
        );
    }

    /** A one-line rendering suitable for a CLI or a log. */
    public function describe(): string
    {
        $where = array_filter([
            $this->cardName !== null ? $this->cardName : $this->cardCode,
            $this->abilityId !== null ? "ability {$this->abilityId}" : null,
            $this->round !== null ? "round {$this->round}" : null,
        ]);

        return $this->code->value . ': ' . $this->message
            . ($where === [] ? '' : ' (' . implode(', ', $where) . ')');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'code' => $this->code->value,
            'message' => $this->message,
            'instanceId' => $this->instanceId,
            'cardCode' => $this->cardCode,
            'cardName' => $this->cardName,
            'abilityId' => $this->abilityId,
            'round' => $this->round,
            'stateVersion' => $this->stateVersion,
            'context' => $this->context === [] ? null : $this->context,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
