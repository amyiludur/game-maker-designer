<?php

declare(strict_types=1);

namespace Gmd\Kernel\View;

use Gmd\Kernel\Contract\PendingChoice;
use Gmd\Kernel\State\MatchResult;

/**
 * What one side is allowed to know.
 *
 * The server does not send a full state and ask the client to hide things: the hidden data
 * is not in here at all. A card in a zone this side cannot see appears as its instance id
 * and nothing else, which is enough to animate a face-down card moving and not enough to
 * know what it is.
 */
final readonly class PlayerView
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $zones  qualified zone key => visible cards
     * @param  list<array<string, mixed>>  $players
     * @param  array<string, mixed>  $adversaries
     * @param  list<array<string, mixed>>  $log
     */
    public function __construct(
        public string $side,
        public int $viewVersion,
        public int $round,
        public string $phase,
        public string $step,
        public string $activeSide,
        public array $zones,
        public array $players,
        public array $adversaries = [],
        public ?PendingChoice $pendingChoice = null,
        public ?MatchResult $result = null,
        public array $log = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'side' => $this->side,
            'viewVersion' => $this->viewVersion,
            'round' => $this->round,
            'phase' => $this->phase,
            'step' => $this->step,
            'activeSide' => $this->activeSide,
            'zones' => $this->zones,
            'players' => $this->players,
            'adversaries' => $this->adversaries === [] ? null : $this->adversaries,
            'pendingChoice' => $this->pendingChoice,
            'result' => $this->result,
            'log' => $this->log === [] ? null : $this->log,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
