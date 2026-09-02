<?php

declare(strict_types=1);

namespace Gmd\Harness\Runner;

use Gmd\Harness\Loader\ReplayFile;
use Gmd\Kernel\State\Codec\StateHasher;
use Gmd\Kernel\State\GameState;

/** Whether a replay still reproduces, and where it stopped agreeing if not. */
final readonly class ReplayResult
{
    /**
     * @param  list<array{seq: int, stateHash: string}>  $checkpoints
     * @param  list<string>  $problems     the replay could not be run this far
     * @param  list<string>  $divergences  it ran, but reached a different position
     */
    public function __construct(
        public ?ReplayFile $replay,
        public ?GameState $state,
        public array $checkpoints = [],
        public array $problems = [],
        public array $divergences = [],
    ) {}

    public static function mismatch(string $reason): self
    {
        return new self(null, null, problems: [$reason]);
    }

    public function isClean(): bool
    {
        return $this->problems === [] && $this->divergences === [];
    }

    public function ranEveryAction(): bool
    {
        return $this->replay !== null && count($this->checkpoints) === count($this->replay->actions);
    }

    public function finalHash(): ?string
    {
        return $this->state === null ? null : StateHasher::hash($this->state);
    }

    /** The `expected` block to write back when blessing. */
    public function expectedBlock(int $every = 1): array
    {
        $checkpoints = [];
        foreach ($this->checkpoints as $index => $checkpoint) {
            if ($every <= 1 || ($index + 1) % $every === 0 || $index === count($this->checkpoints) - 1) {
                $checkpoints[] = $checkpoint;
            }
        }

        $expected = ['finalStateHash' => $this->finalHash()];

        $result = $this->state?->result;
        if ($result !== null) {
            $expected['result'] = array_filter([
                'winner' => count($result->winners) === 1
                    ? \Gmd\Kernel\Contract\Side::seatOf($result->winners[0])
                    : null,
                'reason' => $result->reason,
                'rounds' => $result->rounds,
                'draw' => $result->draw ?: null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        $expected['checkpoints'] = $checkpoints;

        return $expected;
    }
}
