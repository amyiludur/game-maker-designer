<?php

declare(strict_types=1);

namespace Gmd\Harness\Runner;

/** What a fuzz run found. */
final readonly class FuzzReport
{
    /**
     * @param  list<int>  $rounds
     * @param  array<string, int>  $reasons
     * @param  list<FuzzFailure>  $failures
     */
    public function __construct(
        public int $matches,
        public array $rounds,
        public array $reasons,
        public array $failures,
        public int $stalls,
    ) {}

    public function isClean(): bool
    {
        return $this->failures === [];
    }

    public function meanRounds(): float
    {
        return $this->rounds === [] ? 0.0 : array_sum($this->rounds) / count($this->rounds);
    }

    /**
     * The share of matches that hit the round cap.
     *
     * Doc 09 calls anything above 5% a STALL finding: a game that regularly runs out of
     * rounds is one where the clock, not the cards, is deciding.
     */
    public function roundCapRate(): float
    {
        if ($this->matches === 0) {
            return 0.0;
        }

        $capped = 0;
        foreach ($this->reasons as $reason => $count) {
            if (str_contains($reason, 'round_limit') || str_contains($reason, 'round_cap')) {
                $capped += $count;
            }
        }

        return $capped / $this->matches;
    }

    public function describe(): string
    {
        $lines = [sprintf('%d matches, %d failure(s)', $this->matches, count($this->failures))];
        $lines[] = sprintf('  rounds: mean %.1f, min %d, max %d',
            $this->meanRounds(),
            $this->rounds === [] ? 0 : min($this->rounds),
            $this->rounds === [] ? 0 : max($this->rounds),
        );
        foreach ($this->reasons as $reason => $count) {
            $lines[] = sprintf('  %-24s %d (%.0f%%)', $reason, $count, $count / max(1, $this->matches) * 100);
        }
        foreach (array_slice($this->failures, 0, 10) as $failure) {
            $lines[] = '  ! ' . $failure->describe();
        }

        return implode("\n", $lines);
    }
}
