<?php

declare(strict_types=1);

namespace Gmd\Harness\Runner;

use Gmd\Kernel\Contract\Action;
use Gmd\Kernel\State\EventRecord;
use Gmd\Kernel\State\GameState;

/** How a headless match went. */
final readonly class MatchOutcome
{
    /**
     * @param  list<Action>  $actions
     * @param  list<EventRecord>  $events
     */
    public function __construct(
        public GameState $state,
        public array $actions,
        public array $events,
        public bool $stalled = false,
    ) {}

    public function rounds(): int
    {
        return $this->state->round;
    }

    public function reason(): string
    {
        return $this->stalled ? 'stalled' : ($this->state->result?->reason ?? 'unfinished');
    }

    /** @return list<string> */
    public function winners(): array
    {
        return $this->state->result?->winners ?? [];
    }

    public function describe(): string
    {
        if ($this->stalled) {
            return sprintf('STALLED after %d actions in round %d', count($this->actions), $this->rounds());
        }

        $result = $this->state->result;
        if ($result === null) {
            return 'unfinished';
        }
        if ($result->draw) {
            return sprintf('draw (%s) after %d rounds, %d actions', $result->reason, $result->rounds, count($this->actions));
        }

        return sprintf(
            '%s wins (%s) after %d rounds, %d actions',
            implode(' + ', $result->winners) ?: 'nobody',
            $result->reason,
            $result->rounds,
            count($this->actions),
        );
    }
}
