<?php

declare(strict_types=1);

namespace Gmd\Kernel\Contract;

/**
 * One thing a player may legally do right now, with its targets already chosen.
 *
 * The client renders these and sends one back; it never works out for itself whether a card
 * is playable. That is not a convenience — it is the reason the client can be wrong about
 * the rules without the game being wrong (ADR-0002).
 */
final readonly class LegalAction
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public string $actionId,
        public string $side,
        public array $params = [],
        public string $label = '',
    ) {}

    public function toAction(): Action
    {
        return new Action($this->actionId, $this->side, $this->params);
    }

    /**
     * A short stable id for this exact (action, targets) pair.
     *
     * Convenience for clients and telemetry — the kernel accepts it but never requires it,
     * because it stops existing the moment enumeration goes lazy.
     */
    public function key(): string
    {
        return substr(hash('sha1', $this->actionId . '|' . json_encode($this->params)), 0, 12);
    }

    public function matches(Action $action): bool
    {
        return $this->actionId === $action->actionId
            && $this->side === $action->side
            && $this->params == $action->params;
    }
}
